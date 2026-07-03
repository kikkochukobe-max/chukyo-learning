<?php
declare(strict_types=1);

// 生徒マイページ「学習の記録」。デザインは mypage_mock.html が正（見た目は変えない）。
// データ取得元: study_sessions / answer_logs / xp_logs / retry_queue / question_catalog
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';

$actor = current_actor();

// ---- 未ログイン時: 共通ヘッダーのログイン窓で入ってもらう ----
if (!$actor || $actor['type'] !== 'student') {
    ?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>学習の記録 | 中京個別指導学院</title>
</head>
<body style="font-family:sans-serif;background:#FBFAF6;">
<script src="/assets/divp-header.js"></script>
<div style="max-width:560px;margin:60px auto;padding:0 16px;text-align:center;color:#33312B;">
  <p style="font-size:18px;font-weight:700;">学習の記録を見るにはログインが必要です</p>
  <p style="font-size:14px;color:#8B877C;">右上の「ログイン」ボタンから<br>生徒コードとPINを入力してください</p>
</div>
</body>
</html><?php
    exit;
}

$studentId = $actor['id'];
$pdo = db();

// ---- 生徒情報(教室名・学年) ----
$stmt = $pdo->prepare(
    'SELECT s.student_name, s.grade, c.classroom_name
     FROM students s JOIN classrooms c ON c.classroom_id = s.classroom_id
     WHERE s.student_id = :id'
);
$stmt->execute(['id' => $studentId]);
$student = $stmt->fetch();

function grade_label(?string $grade): string
{
    if (!$grade) return '';
    if (preg_match('/^es(\d)$/', $grade, $m)) return '小' . $m[1];
    if (preg_match('/^js(\d)$/', $grade, $m)) return '中' . $m[1];
    if (preg_match('/^hs(\d)$/', $grade, $m)) return '高' . $m[1];
    return $grade;
}

// ---- 表示期間(今週/先週/今月/全期間) ----
$period = (string)($_GET['period'] ?? 'week');
if (!in_array($period, ['week', 'last_week', 'month', 'all'], true)) {
    $period = 'week';
}
$thisMonday = new DateTimeImmutable('monday this week');
switch ($period) {
    case 'last_week':
        $from = $thisMonday->modify('-7 days');
        $to = $thisMonday;
        break;
    case 'month':
        $from = new DateTimeImmutable('first day of this month 00:00:00');
        $to = $from->modify('+1 month');
        break;
    case 'all':
        $from = null;
        $to = null;
        break;
    default: // week
        $from = $thisMonday;
        $to = $thisMonday->modify('+7 days');
        break;
}
$periodLabels = ['week' => '今週', 'last_week' => '先週', 'month' => '今月', 'all' => 'これまで'];
$eyebrow = $periodLabels[$period] . 'の がんばり';

// 期間条件付きのWHERE句を組み立てる（$fromがnullなら全期間）
function period_where(string $column, ?DateTimeImmutable $from, ?DateTimeImmutable $to, array &$params): string
{
    if ($from === null) {
        return '';
    }
    $params['from'] = $from->format('Y-m-d 00:00:00');
    $params['to'] = $to->format('Y-m-d 00:00:00');
    return " AND {$column} >= :from AND {$column} < :to";
}

// 期間内の学習時間(分)
$params = ['id' => $studentId];
$where = period_where('started_at', $from, $to, $params);
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(duration_sec),0) FROM study_sessions WHERE student_id = :id{$where}"
);
$stmt->execute($params);
$weekMinutes = (int)floor(((int)$stmt->fetchColumn()) / 60);

// 期間内の解答数・正答率
$params = ['id' => $studentId];
$where = period_where('answered_at', $from, $to, $params);
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS total, COALESCE(SUM(is_correct),0) AS correct FROM answer_logs
     WHERE student_id = :id{$where}"
);
$stmt->execute($params);
$week = $stmt->fetch();
$weekSolved = (int)$week['total'];
$weekRate = $weekSolved > 0 ? (int)round(100 * (int)$week['correct'] / $weekSolved) : 0;

// ---- レベル(累計XPから算出: floor(sqrt(totalXp/100))+1) ----
$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM xp_logs WHERE student_id = :id');
$stmt->execute(['id' => $studentId]);
$totalXp = (int)$stmt->fetchColumn();
$level = (int)floor(sqrt($totalXp / 100)) + 1;
$levelFloor = ($level - 1) * ($level - 1) * 100;   // 現レベルの開始XP
$levelCeil  = $level * $level * 100;               // 次レベルに必要な累計XP
$xpToNext = $levelCeil - $totalXp;
$levelPct = (int)round(100 * ($totalXp - $levelFloor) / max(1, $levelCeil - $levelFloor));

// ---- 解き直し(pending件数) ----
$stmt = $pdo->prepare("SELECT COUNT(*) FROM retry_queue WHERE student_id = :id AND status = 'pending'");
$stmt->execute(['id' => $studentId]);
$retryCount = (int)$stmt->fetchColumn();

// ---- 単元カルテ(選択期間・種類別) ----
$params = ['id' => $studentId];
$where = period_where('al.answered_at', $from, $to, $params);
$stmt = $pdo->prepare(
    "SELECT al.unit_key, COALESCE(qc.label, al.question_key) AS label,
            COUNT(*) AS solved, COALESCE(SUM(al.is_correct),0) AS correct,
            MIN(al.answer_id) AS first_seen
     FROM answer_logs al
     LEFT JOIN question_catalog qc
       ON qc.unit_key = al.unit_key AND qc.question_key = al.question_key
     WHERE al.student_id = :id{$where}
     GROUP BY al.unit_key, al.question_key
     ORDER BY al.unit_key, first_seen"
);
$stmt->execute($params);
$karteRows = $stmt->fetchAll();

$unitMeta = require __DIR__ . '/api/units.php';
$units = [];
foreach ($karteRows as $row) {
    $units[$row['unit_key']][] = $row;
}

// ---- 学習の足あと(週表示の時だけ・日別学習分数) ----
$showWeekDots = in_array($period, ['week', 'last_week'], true);
$dailySec = [];
if ($showWeekDots) {
    $stmt = $pdo->prepare(
        'SELECT DATE(started_at) AS d, COALESCE(SUM(duration_sec),0) AS sec FROM study_sessions
         WHERE student_id = :id AND started_at >= :from AND started_at < :to
         GROUP BY DATE(started_at)'
    );
    $stmt->execute([
        'id'   => $studentId,
        'from' => $from->format('Y-m-d 00:00:00'),
        'to'   => $to->format('Y-m-d 00:00:00'),
    ]);
    foreach ($stmt->fetchAll() as $row) {
        $dailySec[$row['d']] = (int)$row['sec'];
    }
}
$dayLabels = ['月', '火', '水', '木', '金', '土', '日'];
$todayStr = (new DateTimeImmutable('today'))->format('Y-m-d');

// ---- がんばりメッセージ ----
if ($weekSolved === 0) {
    $heroMsg = ($period === 'week') ? '今週も がんばろう！' : 'この期間の記録はありません';
} elseif ($weekRate >= 80) {
    $heroMsg = 'よく取り組めています！';
} else {
    $heroMsg = 'コツコツ 続けていこう！';
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>学習の記録 | 中京個別指導学院</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@500;700;900&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<style>
  :root{
    /* ブランドトークン: 朱色はロゴの実色に合わせて微調整可 */
    --paper:#FBFAF6;        /* ノートの紙 */
    --grid:#ECE9E0;         /* 方眼の線 */
    --ink:#33312B;          /* 墨(本文) */
    --ink-soft:#8B877C;     /* 薄墨(補足) */
    --shu:#C73E2E;          /* 朱色(丸つけ・アクセント) */
    --shu-soft:#F6E3DF;     /* 朱の淡色(バー地) */
    --ai:#2C5F8A;           /* 藍(リンク・講師側でも共用) */
    --kin:#C9A227;          /* 金(XP・レベル) */
    --white:#FFFFFF;
    --radius:14px;
    --shadow:0 1px 3px rgba(51,49,43,.08), 0 6px 16px rgba(51,49,43,.06);
  }
  *{margin:0;padding:0;box-sizing:border-box}
  body{
    font-family:'Zen Kaku Gothic New',sans-serif;
    color:var(--ink);
    background-color:var(--paper);
    /* 方眼ノート */
    background-image:
      linear-gradient(var(--grid) 1px, transparent 1px),
      linear-gradient(90deg, var(--grid) 1px, transparent 1px);
    background-size:24px 24px;
    line-height:1.6;
    -webkit-font-smoothing:antialiased;
  }
  .wrap{max-width:560px;margin:0 auto;padding:0 16px 64px}

  /* ---------- ヘッダー ---------- */
  header{
    display:flex;align-items:center;justify-content:space-between;
    padding:14px 2px 10px;
  }
  header img.logo{height:34px;width:auto;display:block}
  .who{text-align:right;font-size:12px;color:var(--ink-soft)}
  .who b{display:block;font-size:15px;color:var(--ink);
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700}

  /* ---------- 今週のがんばり(花丸カード) ---------- */
  .hero{
    position:relative;background:var(--white);border-radius:var(--radius);
    box-shadow:var(--shadow);padding:22px 20px 20px;margin-top:6px;
    border-top:4px solid var(--shu);overflow:hidden;
  }
  .eyebrow{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;
    font-size:13px;letter-spacing:.14em;color:var(--shu);
  }
  .hero h1{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;
    font-size:22px;letter-spacing:.02em;margin-top:2px;
  }
  .stats{display:flex;gap:26px;margin-top:14px}
  .stat .num{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;
    font-size:38px;line-height:1;font-feature-settings:'tnum';
  }
  .stat .num small{font-size:15px;font-weight:700;margin-left:2px;color:var(--ink-soft)}
  .stat .lbl{font-size:12px;color:var(--ink-soft);margin-top:4px}
  /* レベルバー */
  .level{margin-top:18px;padding-top:14px;border-top:1px dashed var(--grid)}
  .level .row{display:flex;justify-content:space-between;align-items:baseline;font-size:13px}
  .level .lv{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;
    font-size:17px;color:var(--kin)}
  .bar{height:10px;background:#F2EEE2;border-radius:5px;margin-top:6px;overflow:hidden}
  .bar>i{display:block;height:100%;border-radius:5px;
    background:linear-gradient(90deg,#E4C455,var(--kin))}

  /* ---------- 解き直しボタン ---------- */
  .retry{
    display:flex;align-items:center;justify-content:space-between;
    margin-top:16px;background:var(--shu);color:#fff;border-radius:var(--radius);
    padding:16px 18px;box-shadow:var(--shadow);text-decoration:none;
  }
  .retry .t{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:16px}
  .retry .t small{display:block;font-size:11px;font-weight:500;opacity:.85;letter-spacing:.05em}
  .retry .badge{
    background:#fff;color:var(--shu);border-radius:999px;
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:16px;
    min-width:44px;text-align:center;padding:6px 12px;
  }

  /* ---------- 単元カルテ ---------- */
  .section-title{
    display:flex;align-items:center;gap:10px;margin:28px 2px 10px;
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:16px;
  }
  .section-title::after{content:"";flex:1;border-top:2px dotted var(--ink-soft);opacity:.4}
  .karte{background:var(--white);border-radius:var(--radius);
    box-shadow:var(--shadow);padding:18px 18px 8px;margin-bottom:14px}
  .karte h2{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:16px;
    display:flex;align-items:baseline;gap:8px;
  }
  .karte h2 small{font-size:11px;font-weight:500;color:var(--ink-soft)}
  .qrow{padding:12px 0;border-bottom:1px solid #F3F0E8}
  .qrow:last-child{border-bottom:none}
  .qhead{display:flex;justify-content:space-between;align-items:baseline;font-size:14px}
  .qhead .rate{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:17px;
    font-feature-settings:'tnum';
  }
  .qhead .rate small{font-size:11px;font-weight:500;color:var(--ink-soft);margin-left:4px}
  .qbar{position:relative;height:12px;background:var(--shu-soft);
    border-radius:6px;margin-top:6px;overflow:visible}
  .qbar>i{display:block;height:100%;border-radius:6px;background:var(--shu)}
  /* 90%以上は丸つけの「◎」マークが付く */
  .qbar .maru{
    position:absolute;right:-4px;top:50%;transform:translateY(-50%);
    width:22px;height:22px;border-radius:50%;background:var(--white);
    border:2.5px solid var(--shu);display:flex;align-items:center;justify-content:center;
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:11px;color:var(--shu);
  }
  .low i{background:#D89A45}          /* 60%未満は橙: がんばりどころ */
  .low .qhead .rate{color:#B07B2E}

  /* ---------- 学習の足あと(週ドット) ---------- */
  .week{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:16px 18px;display:flex;justify-content:space-between}
  .day{text-align:center;font-size:11px;color:var(--ink-soft)}
  .dot{width:30px;height:30px;border-radius:50%;margin:0 auto 4px;
    border:2px dashed var(--grid);display:flex;align-items:center;justify-content:center}
  .dot.on{border:none;background:var(--shu);color:#fff;
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:13px}
  .dot.today{outline:2px solid var(--ai);outline-offset:2px}

  footer{margin-top:28px;text-align:center;font-size:11px;color:var(--ink-soft)}

  /* ---------- 期間タブ ---------- */
  .ptabs{display:flex;gap:8px;margin-top:8px}
  .ptab{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:12px;
    padding:4px 14px;border-radius:999px;text-decoration:none;
    background:var(--white);color:var(--ink-soft);border:1.5px solid var(--grid);
  }
  .ptab.active{background:var(--shu);color:#fff;border-color:var(--shu)}
</style>
</head>
<body>
<div class="wrap">

  <header>
    <img class="logo" src="https://chukyokobetsu.com/manage/wp-content/themes/chukyo/images/common/logo_chukyo.png"
         alt="中京個別指導学院">
    <div class="who"><b><?= h($student['student_name']) ?> さん</b><?= h($student['classroom_name']) ?>教室<?= $student['grade'] ? '・' . h(grade_label($student['grade'])) : '' ?></div>
  </header>

  <!-- 期間タブ -->
  <nav class="ptabs">
<?php foreach ($periodLabels as $key => $label): ?>
    <a class="ptab<?= $period === $key ? ' active' : '' ?>" href="?period=<?= $key ?>"><?= h($label) ?></a>
<?php endforeach; ?>
  </nav>

  <!-- がんばりカード -->
  <section class="hero">
    <div class="eyebrow"><?= h($eyebrow) ?></div>
    <h1><?= h($heroMsg) ?></h1>
    <div class="stats">
      <div class="stat"><div class="num"><?= $weekMinutes ?><small>分</small></div><div class="lbl">学習時間</div></div>
      <div class="stat"><div class="num"><?= $weekSolved ?><small>問</small></div><div class="lbl">解いた問題</div></div>
      <div class="stat"><div class="num"><?= $weekRate ?><small>%</small></div><div class="lbl">正答率</div></div>
    </div>
    <div class="level">
      <div class="row"><span class="lv">Lv. <?= $level ?></span><span style="color:var(--ink-soft);font-size:12px">つぎのレベルまで あと<?= $xpToNext ?>XP</span></div>
      <div class="bar"><i style="width:<?= $levelPct ?>%"></i></div>
    </div>
  </section>

<?php if ($retryCount > 0): ?>
  <!-- 解き直し -->
  <a class="retry" href="/retry.php">
    <span class="t">きょうの解き直し<small>まちがえた問題に もう一度チャレンジ</small></span>
    <span class="badge"><?= $retryCount ?>問</span>
  </a>
<?php endif; ?>

  <!-- 単元カルテ -->
  <div class="section-title">単元カルテ</div>

<?php if (count($units) === 0): ?>
  <section class="karte">
    <h2><?= $period === 'all' ? 'まだ記録がありません' : 'この期間の記録はありません' ?></h2>
    <div class="qrow"><div class="qhead"><span style="color:var(--ink-soft);font-size:13px;">問題を解くと、ここに種類別の成績が表示されます</span></div></div>
  </section>
<?php else: ?>
<?php foreach ($units as $unitKey => $rows):
    $meta = $unitMeta[$unitKey] ?? ['title' => $unitKey, 'sub' => ''];
?>
  <section class="karte">
    <h2><?= h($meta['title']) ?> <?php if ($meta['sub']): ?><small><?= h($meta['sub']) ?></small><?php endif; ?></h2>
<?php foreach ($rows as $row):
    $solved = (int)$row['solved'];
    $correct = (int)$row['correct'];
    $rate = $solved > 0 ? (int)round(100 * $correct / $solved) : 0;
    $isLow = $rate < 60;
    $isMaru = $rate >= 90;
?>
    <div class="qrow<?= $isLow ? ' low' : '' ?>">
      <div class="qhead"><span><?= h($row['label']) ?></span><span class="rate"><?= $rate ?><small>% (<?= $correct ?>/<?= $solved ?>)</small></span></div>
      <div class="qbar"><i style="width:<?= $rate ?>%"></i><?php if ($isMaru): ?><span class="maru">◎</span><?php endif; ?></div>
    </div>
<?php endforeach; ?>
  </section>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($showWeekDots): ?>
  <!-- 学習の足あと -->
  <div class="section-title">学習の足あと</div>
  <section class="week">
<?php for ($i = 0; $i < 7; $i++):
    $day = $from->modify("+{$i} days");
    $dayStr = $day->format('Y-m-d');
    $minutes = isset($dailySec[$dayStr]) ? (int)floor($dailySec[$dayStr] / 60) : 0;
    $isToday = $dayStr === $todayStr;
    $classes = 'dot' . ($minutes > 0 ? ' on' : '') . ($isToday ? ' today' : '');
?>
    <div class="day"><div class="<?= $classes ?>"><?= $minutes > 0 ? $minutes : '' ?></div><?= $dayLabels[$i] ?></div>
<?php endfor; ?>
  </section>
<?php endif; ?>

  <footer>中京個別指導学院 学習の記録<?= $showWeekDots ? ' ・ ドットの数字は学習時間(分)' : '' ?></footer>
</div>
</body>
</html>
