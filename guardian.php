<?php
declare(strict_types=1);

// 保護者閲覧ページ。ひもづく子ども全員の学習サマリー（学習時間・種類別の解答数/正解数/正答率）を表示。
// 設計ルール: 誤解答の詳細・端末情報は出さない（それらは講師画面専用）。
// 保護者ログインは login_id = g+代表の子の生徒コード / PIN 4桁（auth.php の actor_type=guardian）。
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';

$actor = current_actor();
$isGuardian = $actor && $actor['type'] === 'guardian';

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function grade_label(?string $grade): string
{
    if (!$grade) return '';
    if (preg_match('/^es(\d)$/', $grade, $m)) return '小' . $m[1];
    if (preg_match('/^js(\d)$/', $grade, $m)) return '中' . $m[1];
    if (preg_match('/^hs(\d)$/', $grade, $m)) return '高' . $m[1];
    return $grade;
}

// ---- 表示期間 ----
$period = (string)($_GET['period'] ?? 'week');
if (!in_array($period, ['week', 'last_week', 'month', 'all'], true)) {
    $period = 'week';
}
$thisMonday = new DateTimeImmutable('monday this week');
switch ($period) {
    case 'last_week': $from = $thisMonday->modify('-7 days'); $to = $thisMonday; break;
    case 'month':     $from = new DateTimeImmutable('first day of this month 00:00:00'); $to = $from->modify('+1 month'); break;
    case 'all':       $from = null; $to = null; break;
    default:          $from = $thisMonday; $to = $thisMonday->modify('+7 days'); break;
}
$periodLabels = ['week' => '今週', 'last_week' => '先週', 'month' => '今月', 'all' => 'これまで'];

// 学習の足あと（日別ドット）は週表示のときだけ出す
$showWeekDots = in_array($period, ['week', 'last_week'], true);
$todayStr = (new DateTimeImmutable('today'))->format('Y-m-d');
$dayLabels = ['月', '火', '水', '木', '金', '土', '日'];

function period_where(string $column, ?DateTimeImmutable $from, ?DateTimeImmutable $to, array &$params): string
{
    if ($from === null) return '';
    $params['from'] = $from->format('Y-m-d 00:00:00');
    $params['to'] = $to->format('Y-m-d 00:00:00');
    return " AND {$column} >= :from AND {$column} < :to";
}

$children = [];
$guardianName = '';
if ($isGuardian) {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT guardian_name FROM guardians WHERE guardian_id = :id');
    $stmt->execute(['id' => $actor['id']]);
    $guardianName = (string)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT s.student_id, s.student_name, s.grade, c.classroom_name
         FROM guardian_students gs
         JOIN students s ON s.student_id = gs.student_id
         JOIN classrooms c ON c.classroom_id = s.classroom_id
         WHERE gs.guardian_id = :gid AND s.is_active = 1
         ORDER BY s.login_id'
    );
    $stmt->execute(['gid' => $actor['id']]);
    $kids = $stmt->fetchAll();

    $unitMeta = require __DIR__ . '/api/units.php';

    foreach ($kids as $kid) {
        $sid = (int)$kid['student_id'];

        // 学習時間
        $p = ['id' => $sid];
        $w = period_where('started_at', $from, $to, $p);
        $st = $pdo->prepare("SELECT COALESCE(SUM(duration_sec),0) FROM study_sessions WHERE student_id = :id{$w}");
        $st->execute($p);
        $sec = (int)$st->fetchColumn();

        // 解答数・正解数
        $p = ['id' => $sid];
        $w = period_where('answered_at', $from, $to, $p);
        $st = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(is_correct),0) AS correct FROM answer_logs WHERE student_id = :id{$w}");
        $st->execute($p);
        $ans = $st->fetch();
        $solved = (int)$ans['total'];
        $correct = (int)$ans['correct'];
        $rate = $solved > 0 ? (int)round(100 * $correct / $solved) : 0;

        // 累計XP → レベル（全期間）
        $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM xp_logs WHERE student_id = :id');
        $st->execute(['id' => $sid]);
        $totalXp = (int)$st->fetchColumn();
        $level = (int)(floor(sqrt($totalXp / 100)) + 1);

        // 解き直し残（pending・全期間）
        $st = $pdo->prepare("SELECT COUNT(*) FROM retry_queue WHERE student_id = :id AND status = 'pending'");
        $st->execute(['id' => $sid]);
        $pending = (int)$st->fetchColumn();

        // 単元カルテ（種類別・期間）
        $p = ['id' => $sid];
        $w = period_where('al.answered_at', $from, $to, $p);
        $st = $pdo->prepare(
            "SELECT al.unit_key, COALESCE(qc.label, al.question_key) AS label,
                    COUNT(*) AS solved, COALESCE(SUM(al.is_correct),0) AS correct, MIN(al.answer_id) AS first_seen
             FROM answer_logs al
             LEFT JOIN question_catalog qc ON qc.unit_key = al.unit_key AND qc.question_key = al.question_key
             WHERE al.student_id = :id{$w}
             GROUP BY al.unit_key, al.question_key
             ORDER BY al.unit_key, first_seen"
        );
        $st->execute($p);
        $units = [];
        foreach ($st->fetchAll() as $row) {
            $units[$row['unit_key']][] = $row;
        }

        // 学習の足あと（週表示時のみ・日別学習秒数）
        $daily = [];
        if ($showWeekDots) {
            $st = $pdo->prepare(
                'SELECT DATE(started_at) AS d, COALESCE(SUM(duration_sec),0) AS sec FROM study_sessions
                 WHERE student_id = :id AND started_at >= :from AND started_at < :to
                 GROUP BY DATE(started_at)'
            );
            $st->execute(['id' => $sid, 'from' => $from->format('Y-m-d 00:00:00'), 'to' => $to->format('Y-m-d 00:00:00')]);
            foreach ($st->fetchAll() as $row) {
                $daily[$row['d']] = (int)$row['sec'];
            }
        }

        $children[] = [
            'name' => $kid['student_name'],
            'classroom' => $kid['classroom_name'],
            'grade' => $kid['grade'],
            'minutes' => (int)floor($sec / 60),
            'solved' => $solved,
            'correct' => $correct,
            'rate' => $rate,
            'level' => $level,
            'pending' => $pending,
            'daily' => $daily,
            'units' => $units,
        ];
    }
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>保護者ページ | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@500;700;900&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<style>
  :root{
    --paper:#FBFAF6; --grid:#ECE9E0; --ink:#33312B; --ink-soft:#8B877C;
    --shu:#C73E2E; --shu-soft:#F6E3DF; --ai:#2C5F8A; --kin:#C9A227;
    --white:#FFFFFF; --radius:14px;
    --shadow:0 1px 3px rgba(51,49,43,.08), 0 6px 16px rgba(51,49,43,.06);
  }
  *{margin:0;padding:0;box-sizing:border-box}
  body{
    font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);
    background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px, transparent 1px),linear-gradient(90deg, var(--grid) 1px, transparent 1px);
    background-size:24px 24px;line-height:1.6;-webkit-font-smoothing:antialiased;
  }
  .wrap{max-width:680px;margin:0 auto;padding:0 16px 64px}
  header{display:flex;align-items:center;justify-content:space-between;padding:14px 2px 10px;gap:10px}
  header img.logo{height:34px;width:auto;display:block}
  .who{text-align:right;font-size:12px;color:var(--ink-soft)}
  .who b{display:block;font-size:15px;color:var(--ink);font-family:'Zen Maru Gothic',sans-serif;font-weight:700}
  .who a{color:var(--ai);text-decoration:none;font-size:12px}
  .tolist{display:inline-block;margin:0 2px 6px;font-size:13px;color:var(--ai);text-decoration:none;font-family:'Zen Maru Gothic',sans-serif;font-weight:700}

  .ptabs{display:flex;gap:6px;flex-wrap:wrap;margin:4px 0 12px}
  .ptab{font-size:13px;padding:5px 14px;border-radius:999px;border:1.5px solid var(--grid);
    background:var(--white);color:var(--ink-soft);text-decoration:none;font-weight:700}
  .ptab.active{background:var(--ai);border-color:var(--ai);color:#fff}

  .child{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    border-top:4px solid var(--ai);padding:18px;margin-bottom:16px}
  .child h2{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:18px;color:var(--ai)}
  .child h2 small{font-size:12px;font-weight:500;color:var(--ink-soft);margin-left:6px}
  .stats{display:flex;flex-wrap:wrap;gap:12px 22px;margin-top:12px}
  .stat .num{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:30px;line-height:1;font-feature-settings:'tnum'}
  .stat .num small{font-size:13px;font-weight:700;margin-left:2px;color:var(--ink-soft)}
  .stat .lbl{font-size:11px;color:var(--ink-soft);margin-top:4px}
  .lv{color:var(--kin)}
  .pending{margin-top:10px;font-size:13px;color:var(--shu);font-weight:700}

  /* 学習の足あと（週ドット） */
  .week-title{font-size:12px;color:var(--ink-soft);font-weight:700;margin:14px 0 6px}
  .week{display:flex;justify-content:space-between;background:var(--paper);border-radius:10px;padding:12px 10px}
  .day{text-align:center;font-size:11px;color:var(--ink-soft)}
  .dot{width:30px;height:30px;border-radius:50%;margin:0 auto 4px;border:2px dashed var(--grid);display:flex;align-items:center;justify-content:center}
  .dot.on{border:none;background:var(--shu);color:#fff;font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:13px}
  .dot.today{outline:2px solid var(--ai);outline-offset:2px}

  .unit{margin-top:14px}
  .unit .ut{font-size:13px;font-weight:700;font-family:'Zen Maru Gothic',sans-serif}
  .unit .ut small{font-size:11px;font-weight:500;color:var(--ink-soft);margin-left:4px}
  table{border-collapse:collapse;width:100%;font-size:13px;margin-top:6px}
  th{font-size:11px;color:var(--ink-soft);font-weight:700;text-align:left;border-bottom:2px solid var(--grid);padding:5px 6px}
  td{border-bottom:1px solid #F3F0E8;padding:6px}
  .num{text-align:right;font-feature-settings:'tnum';white-space:nowrap}
  .rate-ok{color:var(--kin);font-weight:700}
  .rate-low{color:#D89A45}
  .empty{color:var(--ink-soft);font-size:13px;padding:6px 0}

  /* ログインフォーム */
  .box{max-width:360px;margin:64px auto;background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);padding:26px 22px;border-top:4px solid var(--ai)}
  .box h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:20px;color:var(--ai);text-align:center}
  .box p.sub{font-size:12px;color:var(--ink-soft);text-align:center;margin-top:4px}
  .box label{display:block;font-size:12px;font-weight:700;margin-top:14px}
  .box input{width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;margin-top:4px}
  .box button{margin-top:18px;width:100%;background:var(--ai);color:#fff;border:none;border-radius:8px;padding:11px;font-size:15px;font-weight:700;cursor:pointer;font-family:'Zen Maru Gothic',sans-serif}
  .box .err{color:var(--shu);font-size:13px;margin-top:10px;text-align:center;min-height:18px}
  footer{margin-top:28px;text-align:center;font-size:11px;color:var(--ink-soft)}
</style>
</head>
<body>
<?php if (!$isGuardian): ?>
<div class="box">
  <h1>保護者ページ</h1>
  <p class="sub">保護者IDとPINでログインしてください</p>
  <label>保護者ID（例: g260038）<input type="text" id="lid" autocomplete="username"></label>
  <label>PIN（4桁）<input type="password" id="lpin" inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="current-password"></label>
  <button id="login-btn" type="button">ログイン</button>
  <div class="err" id="login-err"></div>
</div>
<script>
document.getElementById('login-btn').addEventListener('click', async () => {
  const errEl = document.getElementById('login-err');
  errEl.textContent = '';
  const login_id = document.getElementById('lid').value.trim();
  const pin = document.getElementById('lpin').value.trim();
  if (!login_id || !pin) { errEl.textContent = '保護者IDとPINを入力してください'; return; }
  try {
    const res = await fetch('/api/auth.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({ actor_type: 'guardian', login_id, password: pin }),
    });
    const data = await res.json().catch(() => null);
    if (res.ok && data && data.ok) { location.reload(); }
    else if (data && data.error === 'locked') { errEl.textContent = '失敗が続いたためロック中です。10分後にやり直してください'; }
    else { errEl.textContent = '保護者IDかPINが違います'; }
  } catch (e) { errEl.textContent = '通信エラーが発生しました'; }
});
document.getElementById('lpin').addEventListener('keydown', (e) => { if (e.key === 'Enter') document.getElementById('login-btn').click(); });
</script>
<?php else: ?>
<div class="wrap">
  <header>
    <img class="logo" src="https://chukyokobetsu.com/manage/wp-content/themes/chukyo/images/common/logo_chukyo.png" alt="中京個別指導学院">
    <div class="who"><b><?= h($guardianName) ?> 様</b><a href="#" id="logout">ログアウト</a></div>
  </header>
  <a class="tolist" href="/learning/index.php">← 学習ツールの目次へ</a>

  <nav class="ptabs">
<?php foreach ($periodLabels as $key => $label): ?>
    <a class="ptab<?= $period === $key ? ' active' : '' ?>" href="?period=<?= $key ?>"><?= h($label) ?></a>
<?php endforeach; ?>
  </nav>

<?php if (count($children) === 0): ?>
  <div class="child"><p class="empty">ひもづくお子さまが登録されていません。教室にお問い合わせください。</p></div>
<?php else: ?>
<?php foreach ($children as $c): ?>
  <section class="child">
    <h2><?= h($c['name']) ?> さん<small><?= h($c['classroom']) ?>教室<?= $c['grade'] ? '・' . h(grade_label($c['grade'])) : '' ?></small></h2>
    <div class="stats">
      <div class="stat"><div class="num"><?= $c['minutes'] ?><small>分</small></div><div class="lbl">学習時間</div></div>
      <div class="stat"><div class="num"><?= $c['solved'] ?><small>問</small></div><div class="lbl">解いた問題</div></div>
      <div class="stat"><div class="num"><?= $c['correct'] ?><small>問</small></div><div class="lbl">正解</div></div>
      <div class="stat"><div class="num"><?= $c['rate'] ?><small>%</small></div><div class="lbl">正答率</div></div>
      <div class="stat"><div class="num lv">Lv.<?= $c['level'] ?></div><div class="lbl">レベル（累計）</div></div>
    </div>
<?php if ($c['pending'] > 0): ?>
    <div class="pending">解き直しが <?= $c['pending'] ?>問 のこっています</div>
<?php endif; ?>

<?php if ($showWeekDots): ?>
    <div class="week-title">学習の足あと（ドットの数字は学習時間・分）</div>
    <div class="week">
<?php for ($i = 0; $i < 7; $i++):
      $day = $from->modify("+{$i} days");
      $dayStr = $day->format('Y-m-d');
      $mins = isset($c['daily'][$dayStr]) ? (int)floor($c['daily'][$dayStr] / 60) : 0;
      $isToday = $dayStr === $todayStr;
      $classes = 'dot' . ($mins > 0 ? ' on' : '') . ($isToday ? ' today' : '');
?>
      <div class="day"><div class="<?= $classes ?>"><?= $mins > 0 ? $mins : '' ?></div><?= $dayLabels[$i] ?></div>
<?php endfor; ?>
    </div>
<?php endif; ?>

<?php if (count($c['units']) === 0): ?>
    <p class="empty" style="margin-top:12px;">この期間の学習記録はありません</p>
<?php else: ?>
<?php foreach ($c['units'] as $unitKey => $rows):
    $meta = $unitMeta[$unitKey] ?? ['title' => $unitKey, 'sub' => ''];
?>
    <div class="unit">
      <div class="ut"><?= h($meta['title']) ?><?php if (!empty($meta['sub'])): ?><small><?= h($meta['sub']) ?></small><?php endif; ?></div>
      <table>
        <tr><th>種類</th><th class="num">解答数</th><th class="num">正解</th><th class="num">正答率</th></tr>
<?php foreach ($rows as $row):
    $rSolved = (int)$row['solved'];
    $rCorrect = (int)$row['correct'];
    $rRate = $rSolved > 0 ? (int)round(100 * $rCorrect / $rSolved) : 0;
    $cls = $rRate >= 90 ? 'rate-ok' : ($rRate < 60 ? 'rate-low' : '');
?>
        <tr>
          <td><?= h($row['label']) ?></td>
          <td class="num"><?= $rSolved ?></td>
          <td class="num"><?= $rCorrect ?></td>
          <td class="num <?= $cls ?>"><?= $rRate ?>%<?= $rRate >= 90 ? '◎' : '' ?></td>
        </tr>
<?php endforeach; ?>
      </table>
    </div>
<?php endforeach; ?>
<?php endif; ?>
  </section>
<?php endforeach; ?>
<?php endif; ?>

  <footer>中京個別指導学院 保護者ページ</footer>
</div>
<script>
document.getElementById('logout').addEventListener('click', async (e) => {
  e.preventDefault();
  await fetch('/api/logout.php', { method: 'POST', credentials: 'same-origin' });
  location.reload();
});
</script>
<?php endif; ?>
</body>
</html>
