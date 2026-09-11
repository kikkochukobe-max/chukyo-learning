<?php
declare(strict_types=1);

// 愛知県大問1「1日◯問」チェック表（講師用）
//   生徒 × 日付のマス目。その日に unit_key = math_js3_aichi_daimon1 を
//   しきい値（既定30問）以上解いていれば赤、解けていなければ白のマスで表示する。
//   既定は「吉根教室の中3生・8/25から」。教室・学年・期間・しきい値は上の窓で変えられる。
// 権限: super_admin=全教室 / それ以外=teacher_classrooms の担当教室のみ（teacher.php と同じ）
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';

const GRID_UNIT_KEY = 'math_js3_aichi_daimon1';
const GRID_DEFAULT_CLASSROOM = '吉根';
const GRID_DEFAULT_GRADE = 203;      // grade_key の中3（200+3）
const GRID_DEFAULT_FROM  = '08-25';  // 既定の集計開始日（今年の8/25）
const GRID_DEFAULT_MIN   = 30;       // この問題数以上で赤

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// 学年の保存形式のブレ（js3 / 中3 / 中３）を吸収して 100+n(小) / 200+n(中) / 300+n(高) を返す。
// teacher.php の grade_sort_key と同じ方針
function grade_key(?string $grade): int
{
    if ($grade === null || trim($grade) === '') return 0;
    $g = strtr(trim($grade), ['０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9']);
    if (preg_match('/(es|小)\s*(\d)/ui', $g, $m)) return 100 + (int)$m[2];
    if (preg_match('/(js|中)\s*(\d)/ui', $g, $m)) return 200 + (int)$m[2];
    if (preg_match('/(hs|高)\s*(\d)/ui', $g, $m)) return 300 + (int)$m[2];
    return 0;
}

function grade_label(int $key): string
{
    if ($key >= 300) return '高' . ($key - 300);
    if ($key >= 200) return '中' . ($key - 200);
    if ($key >= 100) return '小' . ($key - 100);
    return 'その他';
}

$actor = current_actor();

// ---- 未ログイン時: 講師ログインフォーム（teacher.php / report.php と同じ） ----
if (!$actor || $actor['type'] !== 'teacher') {
    ?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>大問1 チェック表 | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root{--paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;--ai:#2C5F8A;--white:#fff;
    --radius:14px;--shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06)}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6;zoom:1.2}
  .box{max-width:360px;margin:80px auto;background:var(--white);border-radius:var(--radius);
    box-shadow:var(--shadow);border-top:4px solid var(--ai);padding:28px}
  h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:18px;color:var(--ai)}
  p.sub{font-size:12px;color:var(--ink-soft);margin-top:2px}
  label{display:block;font-size:12px;font-weight:700;margin-top:14px}
  input{width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;margin-top:4px}
  button{margin-top:18px;width:100%;background:var(--ai);color:#fff;border:none;border-radius:8px;
    padding:11px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Zen Maru Gothic',sans-serif}
  .err{color:#c0392b;font-size:12px;margin-top:8px;min-height:16px}
</style>
</head>
<body>
<div class="box">
  <h1>大問1 チェック表</h1>
  <p class="sub">講師アカウントでログインしてください</p>
  <label>ログインID<input type="text" id="lid" autocomplete="username"></label>
  <label>パスワード<input type="password" id="lpw" autocomplete="current-password"></label>
  <button id="lbtn" type="button">ログイン</button>
  <div class="err" id="lerr"></div>
</div>
<script>
document.getElementById('lbtn').addEventListener('click', async () => {
  const err = document.getElementById('lerr');
  err.textContent = '';
  try {
    const res = await fetch('/api/auth.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        actor_type: 'teacher',
        login_id: document.getElementById('lid').value.trim(),
        password: document.getElementById('lpw').value,
      }),
    });
    const data = await res.json().catch(() => null);
    if (res.ok && data && data.ok) { location.reload(); }
    else if (data && data.error === 'locked') { err.textContent = '失敗が続いたためロック中です。10分後にやり直してください'; }
    else { err.textContent = 'ログインIDかパスワードが違います'; }
  } catch (e) { err.textContent = '通信エラーが発生しました'; }
});
document.getElementById('lpw').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') document.getElementById('lbtn').click();
});
</script>
</body>
</html><?php
    exit;
}

$teacherId = $actor['id'];
$pdo = db();

$stmt = $pdo->prepare('SELECT role, teacher_name, must_change_password FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $teacherId]);
$me = $stmt->fetch();
if (!$me) { header('Location: /teacher.php'); exit; }
$role = (string)$me['role'];

// 初期パスワードのままなら、変更するまで先に進ませない（teacher.php / admin.php と同じ）
if ((int)$me['must_change_password'] === 1) {
    header('Location: /password.php');
    exit;
}

if ($role === 'super_admin') {
    $classrooms = $pdo->query('SELECT classroom_id, classroom_name FROM classrooms ORDER BY classroom_id')->fetchAll();
} else {
    $stmt = $pdo->prepare(
        'SELECT c.classroom_id, c.classroom_name FROM classrooms c
         JOIN teacher_classrooms tc ON tc.classroom_id = c.classroom_id
         WHERE tc.teacher_id = :id ORDER BY c.classroom_id'
    );
    $stmt->execute(['id' => $teacherId]);
    $classrooms = $stmt->fetchAll();
}
$allowedIds = array_map(fn($c) => (int)$c['classroom_id'], $classrooms);

// ---- 表示条件（既定＝吉根・中3・今年の8/25〜今日・30問） ----
$classroomId = isset($_GET['classroom']) ? (int)$_GET['classroom'] : 0;
if (!in_array($classroomId, $allowedIds, true)) {
    $classroomId = 0;
    foreach ($classrooms as $c) {   // 既定は吉根。担当外なら担当教室の先頭
        if ((string)$c['classroom_name'] === GRID_DEFAULT_CLASSROOM) { $classroomId = (int)$c['classroom_id']; break; }
    }
    if ($classroomId === 0 && $allowedIds) $classroomId = $allowedIds[0];
}
$classroomName = '';
foreach ($classrooms as $c) {
    if ((int)$c['classroom_id'] === $classroomId) $classroomName = (string)$c['classroom_name'];
}

$gradeKey = isset($_GET['grade']) ? (int)$_GET['grade'] : GRID_DEFAULT_GRADE;
if ($gradeKey < 0 || $gradeKey > 399) $gradeKey = GRID_DEFAULT_GRADE;

$minCount = isset($_GET['min']) ? (int)$_GET['min'] : GRID_DEFAULT_MIN;
if ($minCount < 1 || $minCount > 999) $minCount = GRID_DEFAULT_MIN;

$showTest = isset($_GET['showtest']);   // テスト生は既定で非表示（teacher.php と同方針）

$ymd = function ($s) {   // 'YYYY-MM-DD' として妥当なら正規化して返す
    $s = (string)$s;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return ($d !== false && $d->format('Y-m-d') === $s) ? $s : null;
};
$today = new DateTimeImmutable('today');
$from = $ymd($_GET['from'] ?? '');
$from = $from !== null ? new DateTimeImmutable($from) : new DateTimeImmutable($today->format('Y') . '-' . GRID_DEFAULT_FROM);
$to   = $ymd($_GET['to'] ?? '');
$to   = $to !== null ? new DateTimeImmutable($to) : $today;
if ($to < $from) $to = $from;
// 表が横に伸びすぎないよう最大120日。超えるときは終了日から遡る
if ((int)$from->diff($to)->days > 120) $from = $to->modify('-120 days');

$days = [];
for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) $days[] = $d;

// ---- 生徒（対象教室・対象学年・在籍中） ----
$students = [];
if ($classroomId > 0) {
    $sql = 'SELECT student_id, student_name, grade FROM students
            WHERE is_active = 1 AND classroom_id = :cid';
    if (!$showTest) $sql .= " AND student_name NOT LIKE '%テスト%'";
    $sql .= ' ORDER BY student_name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['cid' => $classroomId]);
    foreach ($stmt->fetchAll() as $r) {
        if (grade_key($r['grade']) !== $gradeKey) continue;
        $students[(int)$r['student_id']] = ['name' => (string)$r['student_name'], 'grade' => (string)$r['grade']];
    }
}

// ---- 日別の解答数（生徒 × 日付） ----
$count = [];   // student_id => ['Y-m-d' => 問題数]
if ($students) {
    $ids = array_keys($students);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT student_id, DATE(answered_at) AS d, COUNT(*) AS n
         FROM answer_logs
         WHERE unit_key = ? AND student_id IN ($in)
           AND answered_at >= ? AND answered_at < ?
         GROUP BY student_id, DATE(answered_at)"
    );
    $stmt->execute(array_merge(
        [GRID_UNIT_KEY],
        $ids,
        [$from->format('Y-m-d 00:00:00'), $to->modify('+1 day')->format('Y-m-d 00:00:00')]
    ));
    foreach ($stmt->fetchAll() as $r) {
        $count[(int)$r['student_id']][(string)$r['d']] = (int)$r['n'];
    }
}

// 生徒ごとの集計（達成日数の多い順→合計問題数の多い順→氏名）
$rows = [];
foreach ($students as $sid => $s) {
    $hit = 0; $sum = 0; $touched = 0;
    foreach ($days as $d) {
        $n = $count[$sid][$d->format('Y-m-d')] ?? 0;
        if ($n > 0) $touched++;
        if ($n >= $minCount) $hit++;
        $sum += $n;
    }
    $rows[] = ['sid' => $sid, 'name' => $s['name'], 'grade' => $s['grade'],
               'hit' => $hit, 'sum' => $sum, 'touched' => $touched];
}
usort($rows, fn($a, $b) => [$b['hit'], $b['sum'], $a['name']] <=> [$a['hit'], $a['sum'], $b['name']]);

// 日ごとの達成人数（列の合計）
$dayHit = [];
foreach ($days as $d) {
    $k = $d->format('Y-m-d');
    $n = 0;
    foreach ($students as $sid => $_) {
        if (($count[$sid][$k] ?? 0) >= $minCount) $n++;
    }
    $dayHit[$k] = $n;
}

// 学年の選択肢（対象教室に在籍している学年だけ出す）
$gradeOptions = [];
if ($classroomId > 0) {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT grade FROM students WHERE is_active = 1 AND classroom_id = :cid"
        . ($showTest ? '' : " AND student_name NOT LIKE '%テスト%'")
    );
    $stmt->execute(['cid' => $classroomId]);
    foreach ($stmt->fetchAll() as $r) {
        $k = grade_key($r['grade']);
        if ($k > 0) $gradeOptions[$k] = grade_label($k);
    }
    ksort($gradeOptions);
}
if (!isset($gradeOptions[$gradeKey])) $gradeOptions[$gradeKey] = grade_label($gradeKey);

$WD = ['日', '月', '火', '水', '木', '金', '土'];
$unitMeta = require __DIR__ . '/api/units.php';
$unitTitle = $unitMeta[GRID_UNIT_KEY]['title'] ?? GRID_UNIT_KEY;
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>大問1 チェック表 | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root{--paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;--ai:#2C5F8A;--shu:#C73E2E;
    --kin:#C9A227;--white:#fff;--radius:14px;
    --shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06)}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6;padding:24px 16px 64px}
  .wrap{max-width:1200px;margin:0 auto}

  header.rep{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;
    border-bottom:3px solid var(--ai);padding-bottom:10px;margin-bottom:18px}
  h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:22px;color:var(--ai)}
  .meta{font-size:12px;color:var(--ink-soft);text-align:right;line-height:1.5}
  .nav{font-size:12px;margin-bottom:14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
  .nav a{color:var(--ai);text-decoration:none;font-weight:700}
  .nav a:hover{text-decoration:underline}
  .btnprint{background:var(--white);border:1px solid #cbd5e1;border-radius:8px;padding:6px 14px;
    font-size:12px;font-weight:700;color:var(--ai);cursor:pointer;font-family:'Zen Maru Gothic',sans-serif}

  form.cond{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px;color:var(--ink-soft);
    background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);padding:12px 16px;margin-bottom:18px}
  form.cond select,form.cond input{padding:5px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;
    font-family:inherit;color:var(--ink)}
  form.cond input[type=number]{width:64px}
  form.cond button{background:var(--ai);color:#fff;border:none;border-radius:6px;padding:6px 14px;
    font-size:12px;font-weight:700;cursor:pointer;font-family:'Zen Maru Gothic',sans-serif}
  form.cond label{display:inline-flex;align-items:center;gap:4px}

  section{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:18px 20px 22px;margin-bottom:22px;border-top:4px solid var(--shu)}
  h2{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:16px;color:var(--shu)}
  p.note{font-size:11px;color:var(--ink-soft);margin-top:6px}
  .legend{display:flex;gap:16px;align-items:center;font-size:11px;color:var(--ink-soft);margin-top:10px;flex-wrap:wrap}
  .legend i{display:inline-block;width:16px;height:16px;border:1px solid var(--grid);border-radius:3px;
    vertical-align:-3px;margin-right:4px}
  .legend i.hit{background:var(--shu);border-color:var(--shu)}
  .legend i.some{background:var(--white)}

  .scroll{overflow-x:auto;margin-top:14px}
  table{border-collapse:separate;border-spacing:0;font-size:12px}
  th,td{border-bottom:1px solid var(--grid);border-right:1px solid var(--grid);padding:4px 6px;white-space:nowrap}
  thead th{font-size:11px;color:var(--ink-soft);font-weight:700;text-align:center;background:#F7F6F1;
    border-bottom:2px solid var(--grid);line-height:1.25}
  thead th .wd{display:block;font-size:10px;font-weight:400}
  thead th.sat{color:var(--ai)}
  thead th.sun{color:var(--shu)}
  th.name,td.name{position:sticky;left:0;background:var(--white);text-align:left;z-index:2;
    border-right:2px solid var(--grid);min-width:120px}
  thead th.name{background:#F7F6F1;z-index:3}
  td.name a{color:var(--ink);text-decoration:none;font-weight:700}
  td.name a:hover{color:var(--ai);text-decoration:underline}
  td.name small{color:var(--ink-soft);font-weight:400;margin-left:4px}
  td.cell{width:30px;min-width:30px;text-align:center;color:var(--ink-soft);font-size:11px;background:var(--white)}
  td.cell.hit{background:var(--shu);color:#fff;font-weight:700}
  td.cell.wknd{background:#FCFBF7}
  td.cell.hit.wknd{background:var(--shu)}
  td.sumcell{text-align:right;font-weight:700;background:#F7FAFC;border-left:2px solid var(--grid)}
  td.sumcell small{display:block;font-weight:400;color:var(--ink-soft);font-size:10px}
  tfoot td{background:#F2F6FA;font-weight:700;text-align:center;color:var(--ai);font-size:11px}
  tfoot td.name{text-align:left;background:#F2F6FA}
  .empty{font-size:13px;color:var(--ink-soft);padding:14px 0}

  @media print{
    body{background:#fff;padding:0;zoom:.8}
    .nav,form.cond,.divp-header{display:none!important}
    section{box-shadow:none;border:1px solid var(--grid)}
    .scroll{overflow:visible}
    td.cell.hit{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  }
</style>
</head>
<body>
<div class="wrap">

<div class="nav">
  <a href="/teacher.php">← 講師ページ</a>
  <a href="/report.php">利用状況レポート</a>
  <button class="btnprint" type="button" onclick="window.print()">この表を印刷</button>
</div>

<header class="rep">
  <div>
    <h1>大問1 「1日<?= (int)$minCount ?>問」チェック表</h1>
    <div class="meta" style="text-align:left"><?= h($unitTitle) ?> ／ <?= h($classroomName) ?>教室 <?= h(grade_label($gradeKey)) ?>生 ／ <?= h($from->format('Y年n月j日')) ?> 〜 <?= h($to->format('n月j日')) ?></div>
  </div>
  <div class="meta">
    作成日 <?= h($today->format('Y年n月j日')) ?><br>
    <?= h((string)$me['teacher_name']) ?>
  </div>
</header>

<form class="cond" method="get">
  <label>教室
    <select name="classroom">
<?php foreach ($classrooms as $c): ?>
      <option value="<?= (int)$c['classroom_id'] ?>"<?= (int)$c['classroom_id'] === $classroomId ? ' selected' : '' ?>><?= h((string)$c['classroom_name']) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label>学年
    <select name="grade">
<?php foreach ($gradeOptions as $k => $label): ?>
      <option value="<?= (int)$k ?>"<?= $k === $gradeKey ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label>期間 <input type="date" name="from" value="<?= h($from->format('Y-m-d')) ?>"></label>〜
  <input type="date" name="to" value="<?= h($to->format('Y-m-d')) ?>">
  <label>1日 <input type="number" name="min" min="1" max="999" value="<?= (int)$minCount ?>"> 問以上で赤</label>
<?php if ($showTest): ?><input type="hidden" name="showtest" value="1"><?php endif; ?>
  <button type="submit">この条件で見る</button>
  <a href="?<?= $showTest ? '' : 'showtest=1' ?>" style="color:var(--ink-soft)"><?= $showTest ? 'テスト生を隠す' : 'テスト生も表示' ?></a>
</form>

<section>
  <h2><?= h($classroomName) ?>教室 <?= h(grade_label($gradeKey)) ?>生 ／ 日ごとの取り組み</h2>
  <p class="note">マスの中の数字はその日に解いた問題数。<?= (int)$minCount ?>問以上解いた日を赤くしています（在籍中の生徒のみ<?= $showTest ? '' : '・テスト生は除外' ?>）。</p>
  <div class="legend">
    <span><i class="hit"></i><?= (int)$minCount ?>問以上</span>
    <span><i class="some"></i><?= (int)$minCount ?>問未満（数字は解いた問題数、空欄は0問）</span>
  </div>

<?php if (!$rows): ?>
  <div class="empty">対象の生徒がいません。教室と学年を確かめてください（学年が未入力の生徒はこの表に出ません）。</div>
<?php else: ?>
  <div class="scroll">
  <table>
    <thead>
      <tr>
        <th class="name">生徒</th>
<?php foreach ($days as $d):
        $w = (int)$d->format('w');
        $cls = $w === 0 ? 'sun' : ($w === 6 ? 'sat' : '');
?>
        <th class="<?= $cls ?>"><?= h($d->format('n/j')) ?><span class="wd"><?= $WD[$w] ?></span></th>
<?php endforeach; ?>
        <th style="border-left:2px solid var(--grid)">達成<br>日数</th>
        <th>合計<br>問題数</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <td class="name"><a href="/teacher.php?student_id=<?= (int)$r['sid'] ?>"><?= h($r['name']) ?></a><small><?= h($r['grade']) ?></small></td>
<?php foreach ($days as $d):
        $k = $d->format('Y-m-d');
        $n = $count[$r['sid']][$k] ?? 0;
        $w = (int)$d->format('w');
        $cls = 'cell' . ($n >= $minCount ? ' hit' : '') . (($w === 0 || $w === 6) ? ' wknd' : '');
?>
        <td class="<?= $cls ?>"><?= $n > 0 ? (int)$n : '' ?></td>
<?php endforeach; ?>
        <td class="sumcell"><?= (int)$r['hit'] ?><small>/<?= count($days) ?>日</small></td>
        <td class="sumcell"><?= number_format($r['sum']) ?><small><?= (int)$r['touched'] ?>日</small></td>
      </tr>
<?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td class="name">達成した人数（<?= count($rows) ?>人中）</td>
<?php foreach ($days as $d): ?>
        <td><?= $dayHit[$d->format('Y-m-d')] > 0 ? (int)$dayHit[$d->format('Y-m-d')] : '' ?></td>
<?php endforeach; ?>
        <td style="border-left:2px solid var(--grid)"></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
  </div>
<?php endif; ?>

  <p class="note">「達成日数」は期間内で<?= (int)$minCount ?>問以上解いた日の数、「合計問題数」の下の小さい数字は1問でも解いた日数です。生徒名を押すと講師ページの生徒詳細に移ります。</p>
</section>

</div>
</body>
</html>
