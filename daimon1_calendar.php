<?php
declare(strict_types=1);

// 愛知県大問1「1日◯問」カレンダー（生徒1人ぶん）
//   daimon1_grid.php（講師用・生徒×日付の一覧表）の個人版。8/26〜今日を月ごとのカレンダーにして、
//   その日に unit_key = math_js3_aichi_daimon1 を GRID_MIN 問以上解いた日を赤で塗る。
//   日曜日は斜線（取り組み日の数え方からも外す）。
// 見られる人: 講師（super_admin=全教室 / それ以外=担当教室の生徒のみ）・ひもづく保護者・本人。
//   URL（?sid=生徒ID）を保護者に送ると、未ログインなら保護者ログイン窓が出て、
//   ログインするとそのまま同じカレンダーが開く。
// 設計ルール（guardian.php と同じ）: 誤解答の詳細・端末情報は出さない。日ごとの問題数だけ。
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';

const CAL_UNIT_KEY = 'math_js3_aichi_daimon1';
const CAL_FROM_MD  = '08-26';   // 集計開始日（今年の8/26。今日がそれより前なら前年の8/26）
const CAL_MIN      = 30;        // この問題数以上で赤（daimon1_grid.php の既定と同じ）

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$sid = isset($_GET['sid']) ? (int)$_GET['sid'] : 0;
$actor = current_actor();
$pdo = db();

// ---- 見てよい人かどうか ----
$allowed = false;
$backLink = null;   // [href, label]
if ($actor && $sid > 0) {
    if ($actor['type'] === 'student') {
        $allowed = $actor['id'] === $sid;
        $backLink = ['/mypage.php', '← マイページへ'];
    } elseif ($actor['type'] === 'guardian') {
        $st = $pdo->prepare('SELECT 1 FROM guardian_students WHERE guardian_id = :g AND student_id = :s');
        $st->execute(['g' => $actor['id'], 's' => $sid]);
        $allowed = (bool)$st->fetchColumn();
        $backLink = ['/guardian.php', '← 保護者ページへ'];
    } elseif ($actor['type'] === 'teacher') {
        $st = $pdo->prepare('SELECT role, must_change_password FROM teachers WHERE teacher_id = :id');
        $st->execute(['id' => $actor['id']]);
        $me = $st->fetch();
        if ($me && (int)$me['must_change_password'] === 1) { header('Location: /password.php'); exit; }
        if ($me && $me['role'] === 'super_admin') {
            $allowed = true;
        } elseif ($me) {
            $st = $pdo->prepare(
                'SELECT 1 FROM students s
                 JOIN teacher_classrooms tc ON tc.classroom_id = s.classroom_id
                 WHERE s.student_id = :s AND tc.teacher_id = :t'
            );
            $st->execute(['s' => $sid, 't' => $actor['id']]);
            $allowed = (bool)$st->fetchColumn();
        }
        $backLink = ['/daimon1_grid.php', '← チェック表へ'];
    }
}

$student = null;
if ($allowed) {
    $st = $pdo->prepare(
        'SELECT s.student_name, s.grade, c.classroom_name FROM students s
         JOIN classrooms c ON c.classroom_id = s.classroom_id WHERE s.student_id = :s'
    );
    $st->execute(['s' => $sid]);
    $student = $st->fetch() ?: null;
    if (!$student) $allowed = false;
}

$head = function (string $title) { ?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?> | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root{--paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;--ai:#2C5F8A;--shu:#C73E2E;
    --shu-soft:#F8E6E3;--kin:#C9A227;--white:#fff;--radius:14px;
    --shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06)}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6;padding:20px 16px 56px}
  .wrap{max-width:720px;margin:0 auto}

  .box{max-width:360px;margin:56px auto;background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:26px 22px;border-top:4px solid var(--ai)}
  .box h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:19px;color:var(--ai);text-align:center}
  .box p.sub{font-size:12px;color:var(--ink-soft);text-align:center;margin-top:4px}
  .box label{display:block;font-size:12px;font-weight:700;margin-top:14px}
  .box input{width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;margin-top:4px}
  .box button{margin-top:18px;width:100%;background:var(--ai);color:#fff;border:none;border-radius:8px;padding:11px;
    font-size:15px;font-weight:700;cursor:pointer;font-family:'Zen Maru Gothic',sans-serif}
  .box .err{color:var(--shu);font-size:13px;margin-top:10px;text-align:center;min-height:18px}
  .box p.alt{font-size:11px;color:var(--ink-soft);text-align:center;margin-top:12px}
  .box p.alt a{color:var(--ai)}

  .nav{font-size:12px;margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .nav a{color:var(--ai);text-decoration:none;font-weight:700}
  .nav button{background:var(--white);border:1px solid #cbd5e1;border-radius:8px;padding:5px 12px;font-size:12px;
    font-weight:700;color:var(--ai);cursor:pointer;font-family:'Zen Maru Gothic',sans-serif}
  .nav .copied{color:#3E8E5A;font-weight:700}

  header.rep{border-bottom:3px solid var(--shu);padding-bottom:8px;margin-bottom:16px}
  h1.t{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:21px;color:var(--shu);line-height:1.35}
  header.rep .who{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:16px;margin-top:2px}
  header.rep .who small{font-size:12px;color:var(--ink-soft);margin-left:6px;font-weight:400}
  header.rep .meta{font-size:12px;color:var(--ink-soft)}

  .rates{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:10px}
  .rate{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);border-top:4px solid var(--shu);
    padding:12px 10px 14px;text-align:center}
  .rate-lbl{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:13px;color:var(--ink-soft)}
  .rate-num{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:44px;color:var(--shu);line-height:1.1}
  .rate-num small{font-size:20px;margin-left:2px}
  .rate-sub{font-size:12px;color:var(--ink-soft)}
  .rate-bar{height:10px;border-radius:999px;background:var(--grid);margin-top:8px;overflow:hidden}
  .rate-bar i{display:block;height:100%;background:var(--shu);border-radius:999px;
    -webkit-print-color-adjust:exact;print-color-adjust:exact}
  .stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:16px}
  .stat{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);padding:10px 8px;text-align:center}
  .stat .num{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:24px;color:var(--shu);line-height:1.2}
  .stat .num small{font-size:12px;margin-left:2px;color:var(--ink-soft);font-weight:700}
  .stat .lbl{font-size:11px;color:var(--ink-soft)}

  section.month{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:14px 14px 16px;margin-bottom:16px}
  section.month h2{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:16px;margin-bottom:8px}
  section.month h2 small{font-size:12px;color:var(--ink-soft);font-weight:700;margin-left:8px}
  .cal{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px}
  .cal .wd{text-align:center;font-size:11px;font-weight:700;color:var(--ink-soft);padding-bottom:2px}
  .cal .wd.sun{color:var(--shu)} .cal .wd.sat{color:var(--ai)}
  .day{position:relative;aspect-ratio:1/1;border:1px solid var(--grid);border-radius:6px;background:var(--white);
    display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:0}
  .day .d{position:absolute;top:2px;left:5px;font-size:10px;color:var(--ink-soft);line-height:1}
  .day .n{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:15px;line-height:1;margin-top:6px}
  .day .n small{font-size:9px;font-weight:700;margin-left:1px}
  .day.blank{border:none;background:transparent}
  .day.out{background:#F4F2EC;border-color:#F4F2EC}
  .day.out .d{color:#C7C2B6}
  .day.some .n{color:var(--ink)}
  .day.hit{background:var(--shu);border-color:var(--shu)}
  .day.hit .d,.day.hit .n{color:#fff}
  /* 日曜は斜線。取り組み日の分母には入れない */
  .day.sun{background:repeating-linear-gradient(-45deg,#fff 0 5px,#DDD8CC 5px 6.5px)}
  .day.sun.hit{background:repeating-linear-gradient(-45deg,var(--shu) 0 5px,#E07A6D 5px 6.5px)}
  .day.today{box-shadow:0 0 0 2px var(--kin)}

  .legend{display:flex;gap:14px;flex-wrap:wrap;font-size:11px;color:var(--ink-soft);margin-bottom:14px}
  .legend i{display:inline-block;width:16px;height:16px;border:1px solid var(--grid);border-radius:3px;
    vertical-align:-3px;margin-right:4px;background:var(--white)}
  .legend i.hit{background:var(--shu);border-color:var(--shu)}
  .legend i.sun{background:repeating-linear-gradient(-45deg,#fff 0 3px,#DDD8CC 3px 4.5px)}
  p.note{font-size:11px;color:var(--ink-soft);margin-top:4px}
  .msg{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);padding:22px;margin-top:40px;
    font-size:14px;text-align:center}
  .msg a{color:var(--ai)}

  @media (max-width:420px){
    .rate-num{font-size:34px}
    .rate-sub{font-size:11px}
    .day .n{font-size:13px}
    .stat .num{font-size:20px}
  }
  @media print{
    body{background:#fff;padding:0}
    .nav,.divp-header{display:none!important}
    section.month,.stat{box-shadow:none;border:1px solid var(--grid)}
    section.month{break-inside:avoid}
    .day,.legend i{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  }
</style>
</head>
<body>
<?php };

// ---- 未ログイン（または見られない相手）: 保護者ログイン窓 ----
if (!$actor) {
    $head('大問1 カレンダー');
    ?>
<div class="box">
  <h1>大問1 取り組みカレンダー</h1>
  <p class="sub">保護者IDと、お子さまのPIN（4桁）でログインしてください</p>
  <label>保護者ID（例: g260038）<input type="text" id="lid" autocomplete="username" autocapitalize="off" autocorrect="off" spellcheck="false"></label>
  <label>お子さまのPIN（4桁）<input type="password" id="lpin" inputmode="numeric" maxlength="4" autocomplete="current-password"></label>
  <button id="login-btn" type="button">ログイン</button>
  <div class="err" id="login-err"></div>
  <p class="alt">講師の方は <a href="/teacher.php">講師ページ</a> でログインしてから開いてください</p>
</div>
<script>
document.getElementById('login-btn').addEventListener('click', async () => {
  const errEl = document.getElementById('login-err');
  errEl.textContent = '';
  const login_id = document.getElementById('lid').value.trim();
  const pin = document.getElementById('lpin').value.trim();
  if (!login_id || !pin) { errEl.textContent = '保護者IDとお子さまのPINを入力してください'; return; }
  try {
    const res = await fetch('/api/auth.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({ actor_type: 'guardian', login_id, password: pin }),
    });
    const data = await res.json().catch(() => null);
    if (res.ok && data && data.ok) { location.reload(); }
    else if (data && data.error === 'locked') { errEl.textContent = '失敗が続いたためロック中です。10分後にやり直してください'; }
    else { errEl.textContent = '保護者IDか、お子さまのPINが違います'; }
  } catch (e) { errEl.textContent = '通信エラーが発生しました'; }
});
document.getElementById('lpin').addEventListener('keydown', (e) => { if (e.key === 'Enter') document.getElementById('login-btn').click(); });
</script>
</body>
</html><?php
    exit;
}

if (!$allowed) {
    http_response_code(403);
    $head('大問1 カレンダー');
    ?>
<div class="wrap">
  <div class="msg">
    このカレンダーは表示できません。<br>
    <small style="color:var(--ink-soft)">ログインしているアカウントでは、このお子さまの記録を見られません。</small>
    <?php if ($backLink): ?><p style="margin-top:12px"><a href="<?= h($backLink[0]) ?>"><?= h($backLink[1]) ?></a></p><?php endif; ?>
  </div>
</div>
</body>
</html><?php
    exit;
}

// ---- 期間（8/26〜今日） ----
$today = new DateTimeImmutable('today');
$from = new DateTimeImmutable($today->format('Y') . '-' . CAL_FROM_MD);
if ($from > $today) $from = $from->modify('-1 year');
$to = $today;

// ---- 日別の解答数 ----
$st = $pdo->prepare(
    'SELECT DATE(answered_at) AS d, COUNT(*) AS n FROM answer_logs
     WHERE unit_key = :u AND student_id = :s AND answered_at >= :f AND answered_at < :t
     GROUP BY DATE(answered_at)'
);
$st->execute([
    'u' => CAL_UNIT_KEY, 's' => $sid,
    'f' => $from->format('Y-m-d 00:00:00'), 't' => $to->modify('+1 day')->format('Y-m-d 00:00:00'),
]);
$count = [];
foreach ($st->fetchAll() as $r) $count[(string)$r['d']] = (int)$r['n'];

// 集計: 日曜は分母から外す（日曜に解いた問題は合計と達成日には入れる）
$hit = 0; $sum = 0; $targetDays = 0;
for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
    $n = $count[$d->format('Y-m-d')] ?? 0;
    if ((int)$d->format('w') !== 0) $targetDays++;
    if ($n >= CAL_MIN) $hit++;
    $sum += $n;
}
// 達成率 = 達成日 ÷ 日曜をのぞいた日数。日曜に達成した日も分子に入るので100%で頭打ちにする
$rate = $targetDays > 0 ? min(100, (int)round(100 * $hit / $targetDays)) : 0;
// 解答数達成率 = 解いた問題の合計 ÷ (日曜をのぞいた日数 × CAL_MIN)。
// 多く解いたぶんが見えるよう数字は100%で止めない（棒だけ100%まで）
$goal = $targetDays * CAL_MIN;
$qRate = $goal > 0 ? (int)round(100 * $sum / $goal) : 0;

// 月ごとのカレンダー（日曜はじまり）
$months = [];
for ($m = $from->modify('first day of this month'); $m <= $to; $m = $m->modify('+1 month')) $months[] = $m;

$WD = ['日', '月', '火', '水', '木', '金', '土'];
$gradeLabel = (string)$student['grade'];
if (preg_match('/^(es|js|hs)(\d)$/', $gradeLabel, $mm)) $gradeLabel = ['es' => '小', 'js' => '中', 'hs' => '高'][$mm[1]] . $mm[2];

$head('大問1 カレンダー');
?>
<div class="wrap">

<div class="nav">
  <?php if ($backLink): ?><a href="<?= h($backLink[0]) ?>"><?= h($backLink[1]) ?></a><?php endif; ?>
  <?php if ($actor['type'] === 'teacher'): ?>
  <a href="/teacher.php?student_id=<?= $sid ?>">生徒詳細</a>
  <button type="button" id="copy">保護者に送るリンクをコピー</button><span class="copied" id="copied"></span>
  <?php endif; ?>
  <button type="button" onclick="window.print()">印刷</button>
</div>

<header class="rep">
  <h1 class="t">愛知県公立入試 大問1<br>「1日<?= CAL_MIN ?>問」カレンダー</h1>
  <div class="who"><?= h((string)$student['student_name']) ?> さん<small><?= h((string)$student['classroom_name']) ?>教室<?= $gradeLabel !== '' ? '・' . h($gradeLabel) : '' ?></small></div>
  <div class="meta"><?= h($from->format('Y年n月j日')) ?> 〜 <?= h($to->format('n月j日')) ?></div>
</header>

<div class="rates">
<div class="rate">
  <div class="rate-lbl">達成率</div>
  <div class="rate-num"><?= $rate ?><small>%</small></div>
  <div class="rate-sub"><?= $hit ?>日 ÷ <?= $targetDays ?>日（日曜のぞく）</div>
  <div class="rate-bar"><i style="width:<?= $rate ?>%"></i></div>
</div>
<div class="rate">
  <div class="rate-lbl">解答数達成率</div>
  <div class="rate-num"><?= $qRate ?><small>%</small></div>
  <div class="rate-sub"><?= number_format($sum) ?>問 ÷ (<?= $targetDays ?>日 × <?= CAL_MIN ?>問)</div>
  <div class="rate-bar"><i style="width:<?= min(100, $qRate) ?>%"></i></div>
</div>
</div>

<div class="stats">
  <div class="stat"><div class="num"><?= $hit ?><small>日</small></div><div class="lbl"><?= CAL_MIN ?>問以上の日</div></div>
  <div class="stat"><div class="num"><?= count($count) ?><small>日</small></div><div class="lbl">取り組んだ日</div></div>
  <div class="stat"><div class="num"><?= number_format($sum) ?><small>問</small></div><div class="lbl">解いた問題の合計</div></div>
</div>

<div class="legend">
  <span><i class="hit"></i><?= CAL_MIN ?>問以上</span>
  <span><i></i><?= CAL_MIN ?>問未満（数字は解いた問題数）</span>
  <span><i class="sun"></i>日曜日</span>
</div>

<?php foreach ($months as $m):
    $first = $m; $last = $m->modify('last day of this month');
    $lead = (int)$first->format('w');
?>
<section class="month">
  <h2><?= h($m->format('Y年n月')) ?></h2>
  <div class="cal">
<?php foreach ($WD as $i => $w): ?>
    <div class="wd<?= $i === 0 ? ' sun' : ($i === 6 ? ' sat' : '') ?>"><?= $w ?></div>
<?php endforeach; ?>
<?php for ($i = 0; $i < $lead; $i++): ?>
    <div class="day blank"></div>
<?php endfor; ?>
<?php for ($d = $first; $d <= $last; $d = $d->modify('+1 day')):
    $k = $d->format('Y-m-d');
    $inRange = $d >= $from && $d <= $to;
    $n = $count[$k] ?? 0;
    $cls = 'day';
    if (!$inRange) $cls .= ' out';
    elseif ($n >= CAL_MIN) $cls .= ' hit';
    elseif ($n > 0) $cls .= ' some';
    if ((int)$d->format('w') === 0) $cls .= ' sun';
    if ($k === $today->format('Y-m-d')) $cls .= ' today';
?>
    <div class="<?= $cls ?>"><span class="d"><?= (int)$d->format('j') ?></span><?php if ($inRange && $n > 0): ?><span class="n"><?= $n ?><small>問</small></span><?php endif; ?></div>
<?php endfor; ?>
  </div>
</section>
<?php endforeach; ?>

<p class="note">マスの数字はその日に解いた大問1の問題数です（解き直しもふくみます）。<?= CAL_MIN ?>問以上解いた日を赤くしています。</p>

</div>
<?php if ($actor['type'] === 'teacher'): ?>
<script>
document.getElementById('copy').addEventListener('click', async () => {
  const url = location.origin + '/daimon1_calendar.php?sid=<?= $sid ?>';
  const out = document.getElementById('copied');
  try { await navigator.clipboard.writeText(url); out.textContent = 'コピーしました'; }
  catch (e) { window.prompt('このURLを保護者に送ってください', url); }
  setTimeout(() => { out.textContent = ''; }, 2500);
});
</script>
<?php endif; ?>
</body>
</html>
