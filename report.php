<?php
declare(strict_types=1);

// 利用状況レポート（会議・共有用の1枚資料）
//   ① 教室別利用状況: 1問以上解いた生徒 ÷ 在籍生徒 × 100 を「小」「中」に分けて出す
//   ② 人気のサイトランキング: 利用生徒数の多い順（解答数・正答率つき）
// 除外: 高校生 / 学年未設定 / テスト生（氏名に「テスト」）/ 退塾（is_active=0）
//   → 高校生と学年未設定は「除外した人数」だけ注記に出す（分母がずれていたら気づけるように）
// 権限: super_admin=全教室 / classroom_admin・teacher=担当教室のみ（teacher.php と同じ）
// 集計は db/reports/kadouritsu_by_classroom.sql と同じ定義。SQLを直したらこちらも直す
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// 学年の保存形式のブレ（es4 / 小4 / 中２ / ES4）を吸収して校種を返す。
// teacher.php の grade_sort_key と同じ方針（全角数字も半角に寄せてから見る）
function stage_of(?string $grade): string
{
    if ($grade === null || trim($grade) === '') return '未設定';
    $g = strtr(trim($grade), ['０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9']);
    if (preg_match('/^(es|小)/ui', $g)) return '小';
    if (preg_match('/^(js|中)/ui', $g)) return '中';
    if (preg_match('/^(hs|高)/ui', $g)) return '高';
    return '未設定';
}

$actor = current_actor();

// ---- 未ログイン時: 講師ログインフォーム（teacher.php と同じ） ----
if (!$actor || $actor['type'] !== 'teacher') {
    ?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>利用状況レポート | 中京個別指導学院</title>
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
  <h1>利用状況レポート</h1>
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

// ---- 期間 ----
$period = (string)($_GET['period'] ?? 'all');
$periodLabels = ['week' => '今週', 'month' => '今月', 'last_month' => '先月', 'all' => '全期間（累計）'];
if (!isset($periodLabels[$period])) $period = 'all';

$ymd = function ($s) {   // 'YYYY-MM-DD' として妥当なら正規化して返す（2/30 のような値を弾く）
    $s = (string)$s;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return ($d !== false && $d->format('Y-m-d') === $s) ? $s : null;
};
$customFrom = $ymd($_GET['from'] ?? '');
$customTo   = $ymd($_GET['to'] ?? '');
$isCustom = ($customFrom !== null && $customTo !== null && $customFrom <= $customTo);

if ($isCustom) {
    $from = new DateTimeImmutable($customFrom . ' 00:00:00');
    $to   = (new DateTimeImmutable($customTo . ' 00:00:00'))->modify('+1 day');   // 終了日を含める
    $periodLabel = $customFrom . ' 〜 ' . $customTo;
} else {
    switch ($period) {
        case 'week':       $from = new DateTimeImmutable('monday this week'); $to = $from->modify('+7 days'); break;
        case 'month':      $from = new DateTimeImmutable('first day of this month 00:00:00'); $to = $from->modify('+1 month'); break;
        case 'last_month': $from = (new DateTimeImmutable('first day of this month 00:00:00'))->modify('-1 month'); $to = $from->modify('+1 month'); break;
        default:           $from = null; $to = null; break;
    }
    $periodLabel = $periodLabels[$period];
}
$fromStr = $from ? $from->format('Y-m-d 00:00:00') : null;
$toStr   = $to   ? $to->format('Y-m-d 00:00:00')   : null;

// ============================================================
// 集計
//   在籍（分母）: is_active=1 / 氏名に「テスト」を含まない / 校種が小 or 中 / 権限内の教室
//   利用者（分子）: 上記のうち期間内に answer_logs が1件以上ある生徒
// 校種判定は保存形式にブレがあるのでPHP側（stage_of）で行い、SQLは素直に全件取る
// ============================================================
$allowedIds = array_map(fn($c) => (int)$c['classroom_id'], $classrooms);

$students = [];   // student_id => ['classroom_id'=>, 'stage'=>]
$excluded = ['高' => 0, '未設定' => 0];
if ($allowedIds) {
    $in = implode(',', array_fill(0, count($allowedIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT student_id, classroom_id, grade FROM students
         WHERE is_active = 1 AND student_name NOT LIKE '%テスト%' AND classroom_id IN ($in)"
    );
    $stmt->execute($allowedIds);
    foreach ($stmt->fetchAll() as $r) {
        $stage = stage_of($r['grade']);
        if ($stage === '小' || $stage === '中') {
            $students[(int)$r['student_id']] = ['classroom_id' => (int)$r['classroom_id'], 'stage' => $stage];
        } else {
            $excluded[$stage]++;
        }
    }
}

// 期間内の解答を「単元 × 生徒」で1回だけ取り、教室別集計とツール別集計の両方に使う
$rows = [];
if ($students) {
    $sql = 'SELECT unit_key, student_id, COUNT(*) AS n, SUM(is_correct) AS c FROM answer_logs';
    $params = [];
    if ($fromStr !== null) {
        $sql .= ' WHERE answered_at >= :from AND answered_at < :to';
        $params = ['from' => $fromStr, 'to' => $toStr];
    }
    $sql .= ' GROUP BY unit_key, student_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

$STAGES = ['小', '中'];

// 教室別: [classroom_id][stage] => ['total'=>在籍, 'used'=>利用者, 'ans'=>解答数]
$cell = [];
foreach ($allowedIds as $cid) {
    foreach ($STAGES as $st) $cell[$cid][$st] = ['total' => 0, 'used' => 0, 'ans' => 0];
}
foreach ($students as $s) {
    $cell[$s['classroom_id']][$s['stage']]['total']++;
}

// ツール別: [unit_key][stage] => ['users'=>利用生徒数, 'ans'=>解答数, 'ok'=>正解数]
$unitAgg = [];
$perStudentAns = [];   // student_id => 期間内の解答数（利用者判定用）

foreach ($rows as $r) {
    $sid = (int)$r['student_id'];
    if (!isset($students[$sid])) continue;          // 高校生・テスト生・退塾・権限外を落とす
    $stage = $students[$sid]['stage'];
    $unit  = (string)$r['unit_key'];
    $n = (int)$r['n'];
    $ok = (int)$r['c'];

    $perStudentAns[$sid] = ($perStudentAns[$sid] ?? 0) + $n;
    $cell[$students[$sid]['classroom_id']][$stage]['ans'] += $n;

    if (!isset($unitAgg[$unit])) {
        foreach ($STAGES as $st) $unitAgg[$unit][$st] = ['users' => 0, 'ans' => 0, 'ok' => 0];
    }
    $unitAgg[$unit][$stage]['users']++;
    $unitAgg[$unit][$stage]['ans'] += $n;
    $unitAgg[$unit][$stage]['ok']  += $ok;
}
foreach ($perStudentAns as $sid => $n) {
    if ($n > 0) $cell[$students[$sid]['classroom_id']][$students[$sid]['stage']]['used']++;
}

// 合計行
$sumAll = [];
foreach ($STAGES as $st) $sumAll[$st] = ['total' => 0, 'used' => 0, 'ans' => 0];
foreach ($allowedIds as $cid) {
    foreach ($STAGES as $st) {
        foreach (['total', 'used', 'ans'] as $k) $sumAll[$st][$k] += $cell[$cid][$st][$k];
    }
}

// 人気ランキング（利用生徒数の多い順 → 同数なら解答数の多い順）
$unitMeta = require __DIR__ . '/api/units.php';
$ranking = [];
foreach ($unitAgg as $unit => $byStage) {
    $users = $byStage['小']['users'] + $byStage['中']['users'];
    $ans   = $byStage['小']['ans']   + $byStage['中']['ans'];
    $ok    = $byStage['小']['ok']    + $byStage['中']['ok'];
    $ranking[] = [
        'unit'  => $unit,
        'title' => $unitMeta[$unit]['title'] ?? $unit,
        'sub'   => $unitMeta[$unit]['sub'] ?? '',
        'users' => $users,
        'users_es' => $byStage['小']['users'],
        'users_js' => $byStage['中']['users'],
        'ans'   => $ans,
        'ok'    => $ok,
    ];
}
usort($ranking, fn($a, $b) => [$b['users'], $b['ans']] <=> [$a['users'], $a['ans']]);

// 校種別のトップ（小のランキング / 中のランキング）
$rankByStage = [];
foreach ($STAGES as $st) {
    $list = [];
    foreach ($unitAgg as $unit => $byStage) {
        if ($byStage[$st]['users'] === 0) continue;
        $list[] = [
            'title' => $unitMeta[$unit]['title'] ?? $unit,
            'users' => $byStage[$st]['users'],
            'ans'   => $byStage[$st]['ans'],
        ];
    }
    usort($list, fn($a, $b) => [$b['users'], $b['ans']] <=> [$a['users'], $a['ans']]);
    $rankByStage[$st] = $list;
}

function pct(int $used, int $total): ?float
{
    return $total > 0 ? round(100 * $used / $total, 1) : null;
}

// 稼働率の色分け: 70%以上=藍(良い) / 40%以上=金 / それ未満=橙（責める赤は使わない）
function rate_class(?float $r): string
{
    if ($r === null) return 'na';
    if ($r >= 70) return 'good';
    if ($r >= 40) return 'mid';
    return 'low';
}

$today = (new DateTimeImmutable('today'))->format('Y年n月j日');
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>利用状況レポート | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root{--paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;--ai:#2C5F8A;--shu:#C73E2E;
    --kin:#C9A227;--dai:#D89A45;--white:#fff;--radius:14px;
    --shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06)}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6;padding:24px 16px 64px}
  .wrap{max-width:1000px;margin:0 auto}

  header.rep{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;
    border-bottom:3px solid var(--ai);padding-bottom:10px;margin-bottom:18px}
  h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:22px;color:var(--ai)}
  .meta{font-size:12px;color:var(--ink-soft);text-align:right;line-height:1.5}
  .nav{font-size:12px;margin-bottom:14px}
  .nav a{color:var(--ai);text-decoration:none;font-weight:700;margin-right:14px}
  .nav a:hover{text-decoration:underline}

  .tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}
  .tabs a{font-size:12px;font-weight:700;padding:5px 12px;border-radius:999px;text-decoration:none;
    color:var(--ai);background:var(--white);border:1px solid #cbd5e1}
  .tabs a.on{background:var(--ai);color:#fff;border-color:var(--ai)}
  .rangeform{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--ink-soft);margin-bottom:20px}
  .rangeform input{padding:4px 6px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px}
  .rangeform button{background:var(--ai);color:#fff;border:none;border-radius:6px;padding:5px 12px;
    font-size:12px;font-weight:700;cursor:pointer}
  .rangeform a.clear{color:var(--ink-soft)}

  section{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:18px 20px 22px;margin-bottom:22px;border-top:4px solid var(--ai)}
  section.rank{border-top-color:var(--kin)}
  h2{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:16px;color:var(--ai);
    display:flex;align-items:center;gap:8px}
  section.rank h2{color:#8a6d12}
  h2 .no{display:inline-flex;width:22px;height:22px;border-radius:50%;background:var(--ai);color:#fff;
    align-items:center;justify-content:center;font-size:12px}
  section.rank h2 .no{background:var(--kin)}
  h3{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:13px;margin:18px 0 6px;color:var(--ink)}
  p.note{font-size:11px;color:var(--ink-soft);margin-top:6px}

  table{width:100%;border-collapse:collapse;margin-top:12px;font-size:13px}
  th,td{padding:6px 8px;border-bottom:1px solid var(--grid);text-align:right;white-space:nowrap}
  th{font-size:11px;color:var(--ink-soft);font-weight:700;border-bottom:2px solid var(--grid)}
  th.g{border-left:1px solid var(--grid)}
  td.g{border-left:1px solid var(--grid)}
  th:first-child,td:first-child{text-align:left}
  thead tr.grp th{text-align:center;color:var(--ai);font-size:11px;padding-bottom:2px;border-bottom:none}
  tbody tr:hover{background:#F7FAFC}
  tr.sum td{font-weight:700;background:#F2F6FA;border-top:2px solid var(--ai);border-bottom:none}
  .rate{font-weight:700}
  .rate.good{color:var(--ai)}
  .rate.mid{color:var(--kin)}
  .rate.low{color:var(--dai)}
  .rate.na{color:var(--ink-soft);font-weight:400}
  .bar{display:block;height:5px;border-radius:3px;background:var(--grid);margin-top:3px;min-width:52px}
  .bar i{display:block;height:100%;border-radius:3px;background:var(--ai)}
  .bar i.mid{background:var(--kin)}
  .bar i.low{background:var(--dai)}
  td.unit{text-align:left;white-space:normal}
  td.unit small{display:block;color:var(--ink-soft);font-size:11px}
  td.no{width:34px;text-align:center;font-family:'Zen Maru Gothic',sans-serif;font-weight:900;color:var(--ink-soft)}
  tr.top1 td.no{color:var(--kin)}
  .cols{display:flex;gap:24px;flex-wrap:wrap}
  .cols > div{flex:1 1 260px;min-width:0}
  .empty{font-size:13px;color:var(--ink-soft);padding:14px 0}
  .foot{font-size:11px;color:var(--ink-soft);line-height:1.8}
  .foot b{color:var(--ink)}
  .btnprint{background:var(--white);border:1px solid #cbd5e1;border-radius:8px;padding:6px 14px;
    font-size:12px;font-weight:700;color:var(--ai);cursor:pointer;font-family:'Zen Maru Gothic',sans-serif}

  @media print{
    body{background:#fff;padding:0;zoom:.85}
    .nav,.tabs,.rangeform,.btnprint,.divp-header{display:none!important}
    section{box-shadow:none;border:1px solid var(--grid);break-inside:avoid}
  }
</style>
</head>
<body>
<div class="wrap">

<div class="nav">
  <a href="/teacher.php">← 講師ページ</a>
  <a href="/teacher.php?view=ranking">生徒ランキング</a>
  <button class="btnprint" type="button" onclick="window.print()">この資料を印刷</button>
</div>

<header class="rep">
  <div>
    <h1>学習サイト 利用状況レポート</h1>
    <div class="meta" style="text-align:left">対象期間: <b><?= h($periodLabel) ?></b> ／ 小学生・中学生のみ（高校生・テスト生は除外）</div>
  </div>
  <div class="meta">
    作成日 <?= h($today) ?><br>
    <?= h((string)$me['teacher_name']) ?><br>
    <?= $role === 'super_admin' ? '全教室' : '担当教室（' . count($classrooms) . '教室）' ?>
  </div>
</header>

<div class="tabs">
<?php foreach ($periodLabels as $k => $label): ?>
  <a class="<?= (!$isCustom && $period === $k) ? 'on' : '' ?>" href="?period=<?= h($k) ?>"><?= h($label) ?></a>
<?php endforeach; ?>
</div>
<form class="rangeform" method="get">
  <input type="hidden" name="period" value="<?= h($period) ?>">
  期間を指定
  <input type="date" name="from" value="<?= h((string)$customFrom) ?>">〜
  <input type="date" name="to" value="<?= h((string)$customTo) ?>">
  <button type="submit">この期間で見る</button>
  <?php if ($isCustom): ?><a class="clear" href="?period=<?= h($period) ?>">指定をやめる</a><?php endif; ?>
</form>

<!-- ================= ① 教室別利用状況 ================= -->
<section>
  <h2><span class="no">1</span>教室別 利用状況（1問以上つかった生徒の割合）</h2>
  <p class="note">利用率 ＝ 期間内に1問以上解いた生徒 ÷ 在籍生徒 × 100</p>

<?php if (!$classrooms): ?>
  <div class="empty">担当教室が設定されていません。統括管理者に担当教室の登録を依頼してください。</div>
<?php else: ?>
  <table>
    <thead>
      <tr class="grp">
        <th></th>
        <th colspan="3" class="g">小学生</th>
        <th colspan="3" class="g">中学生</th>
        <th colspan="3" class="g">小中 合計</th>
        <th class="g"></th>
      </tr>
      <tr>
        <th>教室</th>
        <th class="g">在籍</th><th>利用</th><th>利用率</th>
        <th class="g">在籍</th><th>利用</th><th>利用率</th>
        <th class="g">在籍</th><th>利用</th><th>利用率</th>
        <th class="g">総解答数</th>
      </tr>
    </thead>
    <tbody>
<?php
    // 利用率（小中合計）の高い順に並べる。在籍0の教室は末尾
    $rowsOut = [];
    foreach ($classrooms as $c) {
        $cid = (int)$c['classroom_id'];
        $t = $cell[$cid]['小']['total'] + $cell[$cid]['中']['total'];
        $u = $cell[$cid]['小']['used']  + $cell[$cid]['中']['used'];
        $rowsOut[] = ['c' => $c, 'cid' => $cid, 'total' => $t, 'used' => $u, 'rate' => pct($u, $t)];
    }
    usort($rowsOut, fn($a, $b) => [$b['rate'] === null ? -1 : $b['rate'], $b['total']] <=> [$a['rate'] === null ? -1 : $a['rate'], $a['total']]);

    $cellHtml = function (int $used, int $total): string {
        $r = pct($used, $total);
        $cls = rate_class($r);
        $w = $r === null ? 0 : $r;
        $bar = '<span class="bar"><i class="' . $cls . '" style="width:' . $w . '%"></i></span>';
        $txt = $r === null ? '—' : number_format($r, 1) . '%';
        return '<td class="g">' . $total . '</td><td>' . $used . '</td>'
             . '<td><span class="rate ' . $cls . '">' . $txt . '</span>' . ($r === null ? '' : $bar) . '</td>';
    };

    foreach ($rowsOut as $r):
        $cid = $r['cid'];
        $ans = $cell[$cid]['小']['ans'] + $cell[$cid]['中']['ans'];
?>
      <tr>
        <td><?= h((string)$r['c']['classroom_name']) ?></td>
        <?= $cellHtml($cell[$cid]['小']['used'], $cell[$cid]['小']['total']) ?>
        <?= $cellHtml($cell[$cid]['中']['used'], $cell[$cid]['中']['total']) ?>
        <?= $cellHtml($r['used'], $r['total']) ?>
        <td class="g"><?= number_format($ans) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
    <tbody>
<?php
    $allTotal = $sumAll['小']['total'] + $sumAll['中']['total'];
    $allUsed  = $sumAll['小']['used']  + $sumAll['中']['used'];
    $allAns   = $sumAll['小']['ans']   + $sumAll['中']['ans'];
?>
      <tr class="sum">
        <td>全体</td>
        <?= $cellHtml($sumAll['小']['used'], $sumAll['小']['total']) ?>
        <?= $cellHtml($sumAll['中']['used'], $sumAll['中']['total']) ?>
        <?= $cellHtml($allUsed, $allTotal) ?>
        <td class="g"><?= number_format($allAns) ?></td>
      </tr>
    </tbody>
  </table>
<?php endif; ?>

  <p class="note">
    集計から外している生徒:
    高校生 <b><?= (int)$excluded['高'] ?></b> 人 ／ 学年が未設定・判別不能 <b><?= (int)$excluded['未設定'] ?></b> 人。
    <?php if ($excluded['未設定'] > 0): ?>
      学年未設定の生徒は小中どちらの分母にも入りません（<a href="/admin.php" style="color:var(--ai)">アカウント管理</a>の学年欄を es4 / js1 の形で埋めると数字が正しくなります）。
    <?php endif; ?>
  </p>
</section>

<!-- ================= ② 人気のサイトランキング ================= -->
<section class="rank">
  <h2><span class="no">2</span>人気のサイト ランキング</h2>
  <p class="note">つかった生徒の人数が多い順（同数なら解答数の多い順）。小学生・中学生の解答のみで集計</p>

<?php if (!$ranking): ?>
  <div class="empty">この期間の解答記録がありません。期間を広げてみてください。</div>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>#</th><th>ツール</th>
        <th class="g">つかった生徒</th><th>小</th><th>中</th>
        <th class="g">解答数</th><th>正答率</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($ranking as $i => $u):
        $rate = $u['ans'] > 0 ? round(100 * $u['ok'] / $u['ans'], 1) : null; ?>
      <tr class="<?= $i === 0 ? 'top1' : '' ?>">
        <td class="no"><?= $i + 1 ?></td>
        <td class="unit"><?= h($u['title']) ?><?php if ($u['sub'] !== ''): ?><small><?= h($u['sub']) ?></small><?php endif; ?></td>
        <td class="g"><b><?= $u['users'] ?></b> 人</td>
        <td><?= $u['users_es'] ?: '—' ?></td>
        <td><?= $u['users_js'] ?: '—' ?></td>
        <td class="g"><?= number_format($u['ans']) ?></td>
        <td><?= $rate === null ? '—' : number_format($rate, 1) . '%' ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>

  <div class="cols">
<?php foreach ($STAGES as $st): ?>
    <div>
      <h3><?= h($st) ?>学生の人気ベスト5</h3>
<?php if (!$rankByStage[$st]): ?>
      <div class="empty">この期間の記録はありません</div>
<?php else: ?>
      <table>
        <thead><tr><th>#</th><th>ツール</th><th class="g">生徒</th><th>解答数</th></tr></thead>
        <tbody>
<?php foreach (array_slice($rankByStage[$st], 0, 5) as $i => $u): ?>
          <tr class="<?= $i === 0 ? 'top1' : '' ?>">
            <td class="no"><?= $i + 1 ?></td>
            <td class="unit"><?= h($u['title']) ?></td>
            <td class="g"><?= $u['users'] ?> 人</td>
            <td><?= number_format($u['ans']) ?></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
<?php endif; ?>
    </div>
<?php endforeach; ?>
  </div>
<?php endif; ?>
</section>

<div class="foot">
  <b>この資料の集計ルール</b><br>
  ・分母（在籍）＝ 在籍中（アカウント有効）で、氏名に「テスト」を含まない小学生・中学生。<b>高校生と退塾者は分母・分子とも除外</b>。<br>
  ・分子（利用）＝ 対象期間内に answer_logs に記録が1件以上ある生徒。ログインしただけ・見ただけの生徒は入りません。<br>
  ・学年は生徒アカウントの学年欄（es4 / js1 など）から判定。空欄の生徒はどちらにも入らず、上の注記に人数が出ます。<br>
  ・ツール名は api/units.php の台帳から表示。台帳に無い unit_key はキーのまま出ます（＝台帳に1行足すと名前が出ます）。<br>
  ・同じ集計を phpMyAdmin で確認する SQL: db/reports/kadouritsu_by_classroom.sql
</div>

</div>
</body>
</html>
