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
    'SELECT s.student_name, s.grade, s.classroom_id, c.classroom_name
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
if (!in_array($period, ['today', 'yesterday', 'week', 'last_week', 'month', 'all'], true)) {
    $period = 'week';
}
$thisMonday = new DateTimeImmutable('monday this week');
switch ($period) {
    case 'today':
        $from = new DateTimeImmutable('today 00:00:00');
        $to = $from->modify('+1 day');
        break;
    case 'yesterday':
        $from = new DateTimeImmutable('yesterday 00:00:00');
        $to = $from->modify('+1 day');
        break;
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
$periodLabels = ['today' => '今日', 'yesterday' => '昨日', 'week' => '今週', 'last_week' => '先週', 'month' => '今月', 'all' => 'これまで'];
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
$weekCorrect = (int)$week['correct'];
$weekRate = $weekSolved > 0 ? (int)round(100 * $weekCorrect / $weekSolved) : 0;

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

// ---- 今日の1問（pending の中でいちばん多くまちがえた問題を1つ）----
// 問題文は最後に間違えた時の answer_logs から取る（retry.php と同じ引き方）。
$stmt = $pdo->prepare(
    "SELECT rq.unit_key, rq.question_key, rq.params_hash, rq.wrong_count,
            COALESCE(qc.label, rq.question_key) AS label,
            al.question_text
     FROM retry_queue rq
     LEFT JOIN question_catalog qc
       ON qc.unit_key = rq.unit_key AND qc.question_key = rq.question_key
     LEFT JOIN answer_logs al ON al.answer_id = (
        SELECT MAX(al2.answer_id) FROM answer_logs al2
        WHERE al2.student_id = rq.student_id AND al2.unit_key = rq.unit_key
          AND al2.question_key = rq.question_key AND al2.params_hash = rq.params_hash
          AND al2.is_correct = 0
     )
     WHERE rq.student_id = :id AND rq.status = 'pending'
     ORDER BY rq.wrong_count DESC, rq.updated_at DESC
     LIMIT 1"
);
$stmt->execute(['id' => $studentId]);
$todaysProblem = $stmt->fetch();

// ---- 教室内ランキング(自分の順位だけ表示。他の生徒の名前は出さない) ----
require_once __DIR__ . '/api/ranking.php';
$rankFromStr = $from ? $from->format('Y-m-d 00:00:00') : null;
$rankToStr = $to ? $to->format('Y-m-d 00:00:00') : null;
// テスト生は通常ランキングから除外。ただし本人がテスト生なら含める（動作確認用）
$viewerIsTest = mb_strpos((string)$student['student_name'], 'テスト') !== false;
$rankRows = ranking_rows($pdo, [(int)$student['classroom_id']], $rankFromStr, $rankToStr, $viewerIsTest);
$myRanks = [];
foreach (['solved' => '解いた問題', 'correct' => '正解数', 'rate' => '正答率', 'xp' => 'ゲットしたXP'] as $metric => $metricLabel) {
    $list = ranking_ranked($rankRows, $metric);
    $mine = null;
    foreach ($list as $r) {
        if ((int)$r['student_id'] === $studentId) { $mine = $r; break; }
    }
    $myRanks[] = [
        'label' => $metricLabel,
        'metric' => $metric,
        'rank' => $mine ? (int)$mine['rank'] : null,
        'total' => count($list),
    ];
}

// ---- 全教室混合ランキング(イベント期間中のみ表示。集計もイベント期間の実績) ----
$activeEvent = ranking_active_event(require __DIR__ . '/api/ranking_events.php');
$eventRanks = null;
if ($activeEvent) {
    $evFromStr = $activeEvent['from'] . ' 00:00:00';
    $evToStr = (new DateTimeImmutable($activeEvent['to']))->modify('+1 day')->format('Y-m-d 00:00:00');
    $evRows = ranking_rows($pdo, $activeEvent['classroom_ids'] ?? null, $evFromStr, $evToStr, $viewerIsTest);
    $evSolved = 0;   // 足切りメッセージ用に自分のイベント期間内解答数を控えておく
    foreach ($evRows as $r) {
        if ((int)$r['student_id'] === $studentId) { $evSolved = (int)$r['solved']; break; }
    }
    $eventRanks = [];
    foreach (['solved' => '解いた問題', 'correct' => '正解数', 'rate' => '正答率', 'xp' => 'ゲットしたXP'] as $metric => $metricLabel) {
        $list = ranking_ranked($evRows, $metric);
        $mine = null;
        foreach ($list as $r) {
            if ((int)$r['student_id'] === $studentId) { $mine = $r; break; }
        }
        $eventRanks[] = [
            'label' => $metricLabel,
            'metric' => $metric,
            'rank' => $mine ? (int)$mine['rank'] : null,
            'total' => count($list),
        ];
    }
}

// ---- 100マス計算（タイムアタック）: 自分のベスト＆教室内じゅんい（全期間） ----
// answer_logs を残さないゲームなので単元カルテには出ない。ここに専用カードで見せる。
require_once __DIR__ . '/api/time_ranking.php';
$hyakuUnit = 'math_es_hyakumasu';
$hyakuSummary = time_records_summary($pdo, $studentId, $hyakuUnit, null, null);
$hyakuTop = [];
$hyakuRank = null;
$hyakuTotal = 0;
if ($hyakuSummary['plays'] > 0) {
    $hyakuTop = time_records_top($pdo, $studentId, $hyakuUnit, 5);
    $hRankRows = time_ranking_rows($pdo, [(int)$student['classroom_id']], $hyakuUnit, null, null, $viewerIsTest);
    $hyakuTotal = count($hRankRows);
    foreach ($hRankRows as $r) {
        if ((int)$r['student_id'] === $studentId) { $hyakuRank = (int)$r['rank']; break; }
    }
}

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

// ---- 教科（unit_key の先頭要素）でのフィルタ用ラベル ----
$subjectLabels = [
    'math'     => '算数・数学',
    'english'  => '英語',
    'japanese' => '国語',
    'science'  => '理科',
    'social'   => '社会',
    'allgrade' => 'その他',
];
function subject_of(string $unitKey): string
{
    return explode('_', $unitKey)[0];
}
// カルテに出ている教科だけを、$subjectLabels の順で並べる
$karteSubjects = [];
foreach (array_keys($units) as $uk) {
    $karteSubjects[subject_of($uk)] = true;
}
$karteSubjectKeys = array_values(array_filter(
    array_keys($subjectLabels),
    fn($s) => isset($karteSubjects[$s])
));
// 台帳に無い教科があれば末尾に足す
foreach (array_keys($karteSubjects) as $s) {
    if (!in_array($s, $karteSubjectKeys, true)) {
        $karteSubjectKeys[] = $s;
    }
}

// ---- 学習の足あと(週表示の時だけ・日別の学習時間と解いた問題数) ----
$showWeekDots = in_array($period, ['week', 'last_week'], true);
$dailySec = [];
$dailySolved = [];
if ($showWeekDots) {
    $range = [
        'id'   => $studentId,
        'from' => $from->format('Y-m-d 00:00:00'),
        'to'   => $to->format('Y-m-d 00:00:00'),
    ];
    // 学習時間（秒）を日別に
    $stmt = $pdo->prepare(
        'SELECT DATE(started_at) AS d, COALESCE(SUM(duration_sec),0) AS sec FROM study_sessions
         WHERE student_id = :id AND started_at >= :from AND started_at < :to
         GROUP BY DATE(started_at)'
    );
    $stmt->execute($range);
    foreach ($stmt->fetchAll() as $row) {
        $dailySec[$row['d']] = (int)$row['sec'];
    }
    // 解いた問題数を日別に
    $stmt = $pdo->prepare(
        'SELECT DATE(answered_at) AS d, COUNT(*) AS cnt FROM answer_logs
         WHERE student_id = :id AND answered_at >= :from AND answered_at < :to
         GROUP BY DATE(answered_at)'
    );
    $stmt->execute($range);
    foreach ($stmt->fetchAll() as $row) {
        $dailySolved[$row['d']] = (int)$row['cnt'];
    }
}
$dayLabels = ['月', '火', '水', '木', '金', '土', '日'];
$todayStr = (new DateTimeImmutable('today'))->format('Y-m-d');

// ---- がんばりメッセージ ----
if ($weekSolved === 0) {
    $heroMsg = ($period === 'week') ? '今週も がんばろう！' : (($period === 'today') ? '今日も がんばろう！' : 'この期間の記録はありません');
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
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
    /* Androidが端末フォント設定で本文を縮小するのを防ぎ、指定サイズで表示する */
    -webkit-text-size-adjust:100%;text-size-adjust:100%;
  }
  .wrap{max-width:560px;margin:0 auto;padding:0 16px 64px}

  /* ---------- ヘッダー ---------- */
  header{
    display:flex;align-items:center;justify-content:space-between;
    padding:14px 2px 10px;
  }
  header img.logo{height:34px;width:auto;display:block}
  .who{text-align:right;font-size:12px;color:var(--ink-soft)}
  .tolist{display:inline-block;margin:0 2px 6px;font-size:13px;color:var(--ai);
    text-decoration:none;font-family:'Zen Maru Gothic',sans-serif;font-weight:700}
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
  .stats{display:flex;flex-wrap:wrap;gap:16px 22px;margin-top:14px}
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

  /* ---------- 今日の1問（いちばん多くまちがえた問題）---------- */
  .today{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:16px 18px;margin-top:16px;border-top:4px solid var(--shu)}
  .today-head{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
  .today-badge{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:15px;color:var(--shu)}
  .today-sub{font-size:11px;color:var(--ink-soft)}
  .today-unit{margin-top:8px;font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:14px;
    display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .today .chip{display:inline-block;font-size:11px;font-weight:700;color:var(--shu);
    background:var(--shu-soft);border-radius:999px;padding:1px 10px;font-family:'Zen Maru Gothic',sans-serif}
  .today-q{margin-top:8px;font-size:16px;overflow-x:auto;padding:10px 12px;background:var(--paper);
    border:1px dashed var(--grid);border-radius:10px}
  .today-go{display:block;text-align:center;margin-top:12px;background:var(--shu);color:#fff;
    border-radius:10px;padding:12px;text-decoration:none;
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:14px}

  /* ---------- 教室内ランキング(自分の順位のみ) ---------- */
  .rankcard{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:16px 18px 8px;margin-top:16px;border-top:4px solid var(--kin)}
  .rankcard .rc-title{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:14px;color:var(--kin)}
  .rankrow{display:flex;justify-content:space-between;align-items:baseline;
    padding:10px 0;border-bottom:1px solid #F3F0E8;font-size:14px}
  .rankrow:last-child{border-bottom:none}
  .rankrow .pos{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:20px;
    font-feature-settings:'tnum'}
  .rankrow .pos small{font-size:11px;font-weight:500;color:var(--ink-soft);margin-left:4px}
  .rankrow .pos.top3{color:var(--kin)}
  .rankrow .none{font-size:12px;color:var(--ink-soft)}

  /* ---------- 100マス タイムアタック ---------- */
  .hyaku-best{display:flex;align-items:baseline;gap:8px;margin:8px 0 2px;flex-wrap:wrap}
  .hyaku-best .t{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:34px;
    color:var(--ai);font-feature-settings:'tnum';line-height:1}
  .hyaku-best .u{font-size:12px;color:var(--ink-soft)}
  .hyaku-list{list-style:none;margin:6px 0 4px;padding:0}
  .hyaku-list li{display:flex;align-items:center;gap:10px;padding:7px 0;
    border-bottom:1px solid #F3F0E8;font-size:14px;font-feature-settings:'tnum'}
  .hyaku-list li:last-child{border-bottom:none}
  .hyaku-list .rk{width:1.6em;text-align:center;font-weight:900;color:var(--ink-soft)}
  .hyaku-list .tm{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:17px;color:var(--ai);flex:1}
  .hyaku-list .mc{font-size:11px;color:var(--ink-soft)}
  .hyaku-list .dt{font-size:11px;color:var(--ink-soft)}

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
  .dot{width:26px;height:26px;border-radius:50%;margin:0 auto 5px;
    border:2px dashed var(--grid)}
  .dot.on{border:none;background:var(--shu)}
  .dot.today{outline:2px solid var(--ai);outline-offset:2px}
  .dname{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;color:var(--ink)}
  .dstat{font-size:11px;line-height:1.5;font-feature-settings:'tnum'}
  .dstat b{font-weight:900;color:var(--ink)}
  .dstat.zero,.dstat.zero b{color:#C7C2B6;font-weight:700}

  footer{margin-top:28px;text-align:center;font-size:11px;color:var(--ink-soft)}

  /* ---------- 期間タブ ---------- */
  .ptabs{display:flex;gap:8px;margin-top:8px}
  .ptab{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:12px;
    padding:4px 14px;border-radius:999px;text-decoration:none;
    background:var(--white);color:var(--ink-soft);border:1.5px solid var(--grid);
  }
  .ptab.active{background:var(--shu);color:#fff;border-color:var(--shu)}

  /* ---------- 教科タブ（単元カルテの絞り込み） ---------- */
  .stabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 2px 12px}
  .stab{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:12px;
    padding:4px 14px;border-radius:999px;cursor:pointer;
    background:var(--white);color:var(--ink-soft);border:1.5px solid var(--grid);
  }
  .stab.active{background:var(--ai);color:#fff;border-color:var(--ai)}
</style>
</head>
<body>
<div class="wrap">

  <header>
    <img class="logo" src="https://chukyokobetsu.com/manage/wp-content/themes/chukyo/images/common/logo_chukyo.png"
         alt="中京個別指導学院">
    <div class="who"><b><?= h($student['student_name']) ?> さん</b><?= h($student['classroom_name']) ?>教室<?= $student['grade'] ? '・' . h(grade_label($student['grade'])) : '' ?></div>
  </header>

  <a class="tolist" href="/learning/index.php">← 学習ツールの目次へ</a>

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
      <div class="stat"><div class="num"><?= $weekCorrect ?><small>問</small></div><div class="lbl">正解</div></div>
      <div class="stat"><div class="num"><?= $weekRate ?><small>%</small></div><div class="lbl">正答率</div></div>
    </div>
    <div class="level">
      <div class="row"><span class="lv">Lv. <?= $level ?></span><span style="color:var(--ink-soft);font-size:12px">つぎのレベルまで あと<?= $xpToNext ?>XP</span></div>
      <div class="bar"><i style="width:<?= $levelPct ?>%"></i></div>
    </div>
  </section>

<?php if ($todaysProblem):
    $tpMeta = $unitMeta[$todaysProblem['unit_key']] ?? ['title' => $todaysProblem['unit_key'], 'sub' => '', 'url' => null];
?>
  <!-- 今日の1問（いちばん多くまちがえた問題の解き直し） -->
  <section class="today">
    <div class="today-head">
      <span class="today-badge">今日の1問</span>
      <span class="today-sub">いちばん多くまちがえた問題だよ（これまで<?= (int)$todaysProblem['wrong_count'] ?>回）</span>
    </div>
    <div class="today-unit"><?= h($tpMeta['title'] ?? '') ?><span class="chip"><?= h($todaysProblem['label']) ?></span></div>
<?php if (!empty($todaysProblem['question_text'])): ?>
    <div class="today-q" data-math="<?= h($todaysProblem['question_text']) ?>"><?= h($todaysProblem['question_text']) ?></div>
<?php endif; ?>
<?php if (!empty($tpMeta['url'])):
    // focus に params_hash を渡すと、ツールがモード選択画面を飛ばしてこの1問だけを直接出題する
    $tpFocus = !empty($todaysProblem['params_hash']) ? '&focus=' . rawurlencode($todaysProblem['params_hash']) : '';
?>
    <a class="today-go" href="<?= h($tpMeta['url']) ?>?retry=1<?= h($tpFocus) ?>">この問題を解き直す →</a>
<?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($retryCount > 0): ?>
  <!-- 解き直し -->
  <a class="retry" href="/retry.php">
    <span class="t">過去のまちがいを解き直す<small>2回連続で正解すると リストから消えるよ</small></span>
    <span class="badge"><?= $retryCount ?>問</span>
  </a>
<?php endif; ?>

  <!-- 教室内ランキング(自分の順位のみ) -->
  <section class="rankcard">
    <div class="rc-title"><?= h($student['classroom_name']) ?>教室の中での じゅんい（<?= h($periodLabels[$period]) ?>）</div>
<?php foreach ($myRanks as $mr): ?>
    <div class="rankrow">
      <span><?= h($mr['label']) ?></span>
<?php if ($mr['rank'] !== null): ?>
      <span class="pos<?= $mr['rank'] <= 3 ? ' top3' : '' ?>"><?= $mr['rank'] ?>位<small><?= $mr['total'] ?>人中</small></span>
<?php elseif ($mr['metric'] === 'rate' && $weekSolved < RANK_MIN_SOLVED): ?>
      <span class="none">あと<?= RANK_MIN_SOLVED - $weekSolved ?>問とくと じゅんいが出るよ</span>
<?php else: ?>
      <span class="none">もんだいをとくと じゅんいが出るよ</span>
<?php endif; ?>
    </div>
<?php endforeach; ?>
  </section>

<?php if ($hyakuSummary['plays'] > 0): ?>
  <!-- 100マス計算 タイムアタック（全期間・自分の記録） -->
  <section class="rankcard" style="border-top-color:var(--ai);">
    <div class="rc-title" style="color:var(--ai);">100マス たし算 タイムアタック</div>
    <div class="hyaku-best">
      <span class="t"><?= h(fmt_time_ms((int)$hyakuSummary['best'])) ?></span>
      <span class="u">ベストタイム ・ これまで <?= (int)$hyakuSummary['plays'] ?>回</span>
    </div>
<?php if ($hyakuRank !== null): ?>
    <div class="rankrow">
      <span><?= h($student['classroom_name']) ?>教室での じゅんい（速さ）</span>
      <span class="pos<?= $hyakuRank <= 3 ? ' top3' : '' ?>"><?= $hyakuRank ?>位<small><?= $hyakuTotal ?>人中</small></span>
    </div>
<?php endif; ?>
    <ol class="hyaku-list">
<?php foreach ($hyakuTop as $i => $t):
    $medal = ['🥇', '🥈', '🥉'][$i] ?? (string)($i + 1);
?>
      <li>
        <span class="rk"><?= h((string)$medal) ?></span>
        <span class="tm"><?= h(fmt_time_ms((int)$t['time_ms'])) ?></span>
        <span class="mc">ミス <?= (int)$t['miss_count'] ?></span>
        <span class="dt"><?= h(substr((string)$t['created_at'], 5, 5)) ?></span>
      </li>
<?php endforeach; ?>
    </ol>
  </section>
<?php endif; ?>

<?php if ($eventRanks !== null):
    $evFromD = new DateTimeImmutable($activeEvent['from']);
    $evToD = new DateTimeImmutable($activeEvent['to']);
    $evPeriodLabel = $evFromD->format('n') . '月' . $evFromD->format('j') . '日〜'
                   . $evToD->format('n') . '月' . $evToD->format('j') . '日';
?>
  <!-- 全教室混合ランキング(イベント期間限定) -->
  <section class="rankcard" style="border-top-color:var(--shu);">
    <div class="rc-title" style="color:var(--shu);"><?= h($activeEvent['label']) ?> ぜんきょうしつでの じゅんい</div>
    <div style="font-size:11px;color:var(--ink-soft);"><?= h($evPeriodLabel) ?>の きろくで きそうよ</div>
<?php foreach ($eventRanks as $mr): ?>
    <div class="rankrow">
      <span><?= h($mr['label']) ?></span>
<?php if ($mr['rank'] !== null): ?>
      <span class="pos<?= $mr['rank'] <= 3 ? ' top3' : '' ?>"><?= $mr['rank'] ?>位<small><?= $mr['total'] ?>人中</small></span>
<?php elseif ($mr['metric'] === 'rate' && $evSolved < RANK_MIN_SOLVED): ?>
      <span class="none">あと<?= RANK_MIN_SOLVED - $evSolved ?>問とくと じゅんいが出るよ</span>
<?php else: ?>
      <span class="none">もんだいをとくと じゅんいが出るよ</span>
<?php endif; ?>
    </div>
<?php endforeach; ?>
  </section>
<?php endif; ?>

  <!-- 単元カルテ -->
  <div class="section-title">単元カルテ</div>

<?php if (count($karteSubjectKeys) > 1): ?>
  <nav class="stabs" id="karteTabs">
    <button class="stab active" data-subject="all">すべて</button>
<?php foreach ($karteSubjectKeys as $s): ?>
    <button class="stab" data-subject="<?= h($s) ?>"><?= h($subjectLabels[$s] ?? $s) ?></button>
<?php endforeach; ?>
  </nav>
<?php endif; ?>

<?php if (count($units) === 0): ?>
  <section class="karte">
    <h2><?= $period === 'all' ? 'まだ記録がありません' : 'この期間の記録はありません' ?></h2>
    <div class="qrow"><div class="qhead"><span style="color:var(--ink-soft);font-size:13px;">問題を解くと、ここに種類別の成績が表示されます</span></div></div>
  </section>
<?php else: ?>
<?php foreach ($units as $unitKey => $rows):
    $meta = $unitMeta[$unitKey] ?? ['title' => $unitKey, 'sub' => ''];
?>
  <section class="karte" data-subject="<?= h(subject_of($unitKey)) ?>">
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
    $solved  = $dailySolved[$dayStr] ?? 0;
    $isToday = $dayStr === $todayStr;
    $active  = $minutes > 0 || $solved > 0;
    $classes = 'dot' . ($active ? ' on' : '') . ($isToday ? ' today' : '');
?>
    <div class="day">
      <div class="<?= $classes ?>"></div>
      <div class="dname"><?= $dayLabels[$i] ?></div>
      <div class="dstat<?= $minutes > 0 ? '' : ' zero' ?>"><b><?= $minutes ?></b>分</div>
      <div class="dstat<?= $solved > 0 ? '' : ' zero' ?>"><b><?= $solved ?></b>問</div>
    </div>
<?php endfor; ?>
  </section>
<?php endif; ?>

  <footer>中京個別指導学院 学習の記録<?= $showWeekDots ? ' ・ 分=学習時間 / 問=解いた問題数' : '' ?></footer>
</div>
<script>
// 「今日の1問」の問題文を KaTeX で整形。retry.php と同じ規則:
// 全体がLaTeXのものと、Unicodeの√/²・分数F(a/b)混じりの日本語文の両方に対応する。
function _mescape(t){ return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function _texWhole(src){ try { return katex.renderToString(src, { throwOnError: true, displayMode: false }); } catch (e) { return _mescape(src); } }
function _K(latex, fallback){
  try { if (typeof katex === 'undefined') throw 0;
    return katex.renderToString(latex, { throwOnError: false, displayMode: false }); }
  catch (e) { return _mescape(fallback != null ? fallback : latex); }
}
function _toLatex(token){
  var m = token.match(/^\([-－]√([\d.]+)\)²$/);   if (m) return '(-\\sqrt{' + m[1] + '})^2';
  m = token.match(/^\(√([\d.]+)\)²$/);            if (m) return '(\\sqrt{' + m[1] + '})^2';
  m = token.match(/^±√([\d.]+)$/);                 if (m) return '\\pm\\sqrt{' + m[1] + '}';
  m = token.match(/^(\d+)√([\d.]+)$/);             if (m) return m[1] + '\\sqrt{' + m[2] + '}';
  m = token.match(/^[-－]√([\d.]+)$/);             if (m) return '-\\sqrt{' + m[1] + '}';
  m = token.match(/^√\((\d+)\/(\d+)\)$/);          if (m) return '\\sqrt{\\dfrac{' + m[1] + '}{' + m[2] + '}}';
  m = token.match(/^F\((\d+)\/(\d+)\)$/);          if (m) return '\\dfrac{' + m[1] + '}{' + m[2] + '}';
  m = token.match(/^[-－]√\(\(-(\d+)\)²\)$/);      if (m) return '-\\sqrt{(-' + m[1] + ')^2}';
  m = token.match(/^√\(\(-(\d+)\)²\)$/);           if (m) return '\\sqrt{(-' + m[1] + ')^2}';
  m = token.match(/^√([\d.]+)$/);                  if (m) return '\\sqrt{' + m[1] + '}';
  return token;
}
function _plain(t){ return _mescape(t).replace(/(?<!\d)-([\d])/g, '－$1').replace(/\n/g, '<br>'); }
function _renderMath(str){
  var re = /[-－]√\(\(-\d+\)²\)|√\(\(-\d+\)²\)|\([-－]√[\d.]+\)²|\(√[\d.]+\)²|√\(\d+\/\d+\)|±√[\d.]+|\d+√[\d.]+|[-－]√[\d.]+|√[\d.]+|F\(\d+\/\d+\)/g;
  var out = '', last = 0, mt;
  while ((mt = re.exec(str)) !== null) {
    out += _plain(str.slice(last, mt.index));
    out += _K(_toLatex(mt[0]), mt[0]);
    last = mt.index + mt[0].length;
  }
  out += _plain(str.slice(last));
  return out;
}
function renderMathToHTML(src){
  src = String(src == null ? '' : src);
  if (/[\\^_{}]/.test(src)) return _texWhole(src);
  if (/[√²³]/.test(src) || /F\(\d+\/\d+\)/.test(src)) return _renderMath(src);
  return _mescape(src).replace(/\n/g, '<br>');
}
document.querySelectorAll('.today-q').forEach(function (el) {
  el.innerHTML = renderMathToHTML(el.getAttribute('data-math') || '');
});

// 単元カルテの教科タブ: data-subject で .karte を表示/非表示
(function () {
  var tabs = document.getElementById('karteTabs');
  if (!tabs) return;
  var cards = Array.prototype.slice.call(document.querySelectorAll('.karte[data-subject]'));
  tabs.addEventListener('click', function (e) {
    var btn = e.target.closest('.stab');
    if (!btn) return;
    var sel = btn.getAttribute('data-subject');
    tabs.querySelectorAll('.stab').forEach(function (b) { b.classList.toggle('active', b === btn); });
    cards.forEach(function (c) {
      c.style.display = (sel === 'all' || c.getAttribute('data-subject') === sel) ? '' : 'none';
    });
  });
})();
</script>
</body>
</html>
