<?php
declare(strict_types=1);

// 講師確認ページ: 生徒一覧（教室・期間フィルタ付き）→ 生徒詳細（単元カルテ+誤答一覧）
// デザインは生徒マイページと同じトークンで基調を朱→藍に反転、情報密度を上げる（テーブル可）。
// 誤解答の詳細・端末情報はこの講師画面だけに出す（マイページには出さない）。
// 権限: super_admin=全教室 / classroom_admin・teacher=担当教室(teacher_classrooms)のみ
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';

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

$actor = current_actor();

// ---- 未ログイン時: 講師ログインフォーム ----
if (!$actor || $actor['type'] !== 'teacher') {
    ?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>講師ページ | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root{--paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;--ai:#2C5F8A;--white:#fff;
    --radius:14px;--shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06)}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6}
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
  <h1>講師ページ</h1>
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

// ---- 講師の権限と担当教室 ----
$stmt = $pdo->prepare('SELECT role, teacher_name FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $teacherId]);
$me = $stmt->fetch();
$role = $me['role'];

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
$allowedClassroomIds = array_map(fn($c) => (int)$c['classroom_id'], $classrooms);

// ---- 期間 ----
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
$periodLabels = ['week' => '今週', 'last_week' => '先週', 'month' => '今月', 'all' => '全期間'];
$fromStr = $from ? $from->format('Y-m-d 00:00:00') : null;
$toStr = $to ? $to->format('Y-m-d 00:00:00') : null;

// 期間フィルタSQL片（プレースホルダ名を変えて複数回使えるように）
function pf(string $col, ?string $fromStr, string $tag, array &$params): string
{
    global $toStr;
    if ($fromStr === null) return '';
    $params["from{$tag}"] = $fromStr;
    $params["to{$tag}"] = $toStr;
    return " AND {$col} >= :from{$tag} AND {$col} < :to{$tag}";
}

$unitMeta = require __DIR__ . '/api/units.php';
$detailStudentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

// ============================================================
// 生徒詳細ビュー
// ============================================================
$detail = null;
if ($detailStudentId > 0) {
    $stmt = $pdo->prepare(
        'SELECT s.*, c.classroom_name FROM students s
         JOIN classrooms c ON c.classroom_id = s.classroom_id
         WHERE s.student_id = :id'
    );
    $stmt->execute(['id' => $detailStudentId]);
    $detail = $stmt->fetch();
    if (!$detail || ($role !== 'super_admin' && !in_array((int)$detail['classroom_id'], $allowedClassroomIds, true))) {
        header('Location: /teacher.php');
        exit;
    }

    // 期間サマリー
    $params = ['id' => $detailStudentId];
    $w = pf('started_at', $fromStr, 'a', $params);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(duration_sec),0) FROM study_sessions WHERE student_id = :id{$w}");
    $stmt->execute($params);
    $dMinutes = (int)floor(((int)$stmt->fetchColumn()) / 60);

    $params = ['id' => $detailStudentId];
    $w = pf('answered_at', $fromStr, 'b', $params);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(is_correct),0) AS correct FROM answer_logs WHERE student_id = :id{$w}");
    $stmt->execute($params);
    $dAns = $stmt->fetch();
    $dSolved = (int)$dAns['total'];
    $dRate = $dSolved > 0 ? (int)round(100 * (int)$dAns['correct'] / $dSolved) : 0;

    // 単元カルテ
    $params = ['id' => $detailStudentId];
    $w = pf('al.answered_at', $fromStr, 'c', $params);
    $stmt = $pdo->prepare(
        "SELECT al.unit_key, COALESCE(qc.label, al.question_key) AS label,
                COUNT(*) AS solved, COALESCE(SUM(al.is_correct),0) AS correct,
                MIN(al.answer_id) AS first_seen
         FROM answer_logs al
         LEFT JOIN question_catalog qc ON qc.unit_key = al.unit_key AND qc.question_key = al.question_key
         WHERE al.student_id = :id{$w}
         GROUP BY al.unit_key, al.question_key
         ORDER BY al.unit_key, first_seen"
    );
    $stmt->execute($params);
    $dUnits = [];
    foreach ($stmt->fetchAll() as $row) {
        $dUnits[$row['unit_key']][] = $row;
    }

    // 直近の誤答（講師のみ閲覧可の情報）
    $params = ['id' => $detailStudentId];
    $w = pf('al.answered_at', $fromStr, 'd', $params);
    $stmt = $pdo->prepare(
        "SELECT al.answered_at, al.unit_key, COALESCE(qc.label, al.question_key) AS label,
                al.question_text, al.correct_answer, al.student_answer
         FROM answer_logs al
         LEFT JOIN question_catalog qc ON qc.unit_key = al.unit_key AND qc.question_key = al.question_key
         WHERE al.student_id = :id AND al.is_correct = 0{$w}
         ORDER BY al.answer_id DESC LIMIT 30"
    );
    $stmt->execute($params);
    $dWrongs = $stmt->fetchAll();

    // 直近の学習セッション（端末情報つき・講師のみ）
    $params = ['id' => $detailStudentId];
    $w = pf('ss.started_at', $fromStr, 'e', $params);
    $stmt = $pdo->prepare(
        "SELECT ss.started_at, ss.duration_sec, ss.total_questions, ss.correct_count, ss.unit_key,
                COALESCE(d.label, LEFT(ss.device_id, 8)) AS device_label
         FROM study_sessions ss
         LEFT JOIN devices d ON d.device_id = ss.device_id
         WHERE ss.student_id = :id{$w}
           AND (ss.total_questions > 0 OR COALESCE(ss.duration_sec, 0) >= 60)
         ORDER BY ss.session_id DESC LIMIT 20"
    );
    $stmt->execute($params);
    $dSessions = $stmt->fetchAll();
}

// ============================================================
// 生徒一覧ビュー
// ============================================================
$students = [];
if (!$detail) {
    $filterClassroom = isset($_GET['classroom_id']) ? (int)$_GET['classroom_id'] : 0;
    if ($filterClassroom > 0 && $role !== 'super_admin' && !in_array($filterClassroom, $allowedClassroomIds, true)) {
        $filterClassroom = 0;
    }

    // 同名プレースホルダは再利用できない(エミュレーション無効)ため、サブクエリごとに別名にする
    $params = [];
    $wSess = pf('ss.started_at', $fromStr, 's', $params);
    $wAns1 = pf('al.answered_at', $fromStr, 'n1', $params);
    $wAns2 = pf('al.answered_at', $fromStr, 'n2', $params);

    $sql =
        "SELECT s.student_id, s.login_id, s.student_name, s.grade, c.classroom_name,
                (SELECT COALESCE(SUM(ss.duration_sec),0) FROM study_sessions ss
                  WHERE ss.student_id = s.student_id{$wSess}) AS sec,
                (SELECT COUNT(*) FROM answer_logs al
                  WHERE al.student_id = s.student_id{$wAns1}) AS solved,
                (SELECT COALESCE(SUM(al.is_correct),0) FROM answer_logs al
                  WHERE al.student_id = s.student_id{$wAns2}) AS correct,
                (SELECT COUNT(*) FROM retry_queue rq
                  WHERE rq.student_id = s.student_id AND rq.status = 'pending') AS retries,
                (SELECT MAX(al.answered_at) FROM answer_logs al
                  WHERE al.student_id = s.student_id) AS last_at
         FROM students s
         JOIN classrooms c ON c.classroom_id = s.classroom_id
         WHERE s.is_active = 1";

    if ($role !== 'super_admin') {
        if (count($allowedClassroomIds) === 0) {
            $sql .= ' AND 1=0';
        } else {
            $sql .= ' AND s.classroom_id IN (' . implode(',', $allowedClassroomIds) . ')';
        }
    }
    if ($filterClassroom > 0) {
        $sql .= ' AND s.classroom_id = :cid';
        $params['cid'] = $filterClassroom;
    }
    $sql .= ' ORDER BY c.classroom_id, s.login_id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
}

function qtab(array $extra): string
{
    return '?' . http_build_query(array_merge($_GET, $extra));
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>講師ページ | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@500;700;900&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
<style>
  :root{
    --paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;
    --shu:#C73E2E;--ai:#2C5F8A;--ai-soft:#E3ECF4;--kin:#C9A227;--white:#fff;
    --radius:14px;--shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06);
  }
  *{margin:0;padding:0;box-sizing:border-box}
  body{
    font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6;-webkit-font-smoothing:antialiased;
  }
  .wrap{max-width:880px;margin:0 auto;padding:0 16px 64px}
  header{display:flex;align-items:center;justify-content:space-between;padding:14px 2px 10px;flex-wrap:wrap;gap:8px}
  header img.logo{height:34px;width:auto;display:block}
  .who{font-size:12px;color:var(--ink-soft);display:flex;align-items:center;gap:10px}
  .who b{font-size:14px;color:var(--ai);font-family:'Zen Maru Gothic',sans-serif}
  .logout{font-size:11px;color:var(--ai);border:1px solid var(--ai);border-radius:999px;
    padding:3px 12px;background:none;cursor:pointer;font-family:'Zen Maru Gothic',sans-serif;font-weight:700}

  .bar-row{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;align-items:center}
  .ptab{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:12px;
    padding:4px 14px;border-radius:999px;text-decoration:none;
    background:var(--white);color:var(--ink-soft);border:1.5px solid var(--grid)}
  .ptab.active{background:var(--ai);color:#fff;border-color:var(--ai)}

  .card{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    border-top:4px solid var(--ai);padding:18px;margin-top:14px}
  .card h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:18px;color:var(--ai)}
  .card h2{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:15px;margin-bottom:8px}

  table{border-collapse:collapse;width:100%;font-size:13px;margin-top:8px}
  th{font-size:11px;color:var(--ink-soft);font-weight:700;text-align:left;
    border-bottom:2px solid var(--ai-soft);padding:6px 8px;white-space:nowrap}
  td{border-bottom:1px solid #F3F0E8;padding:7px 8px;vertical-align:top}
  tr:last-child td{border-bottom:none}
  .num{text-align:right;font-feature-settings:'tnum';white-space:nowrap}
  a.sname{color:var(--ai);font-weight:700;text-decoration:none}
  .lowrate{color:#B07B2E;font-weight:700}
  .okrate{color:#166534;font-weight:700}
  .chip{display:inline-block;font-size:11px;font-weight:700;color:var(--ai);
    background:var(--ai-soft);border-radius:999px;padding:0 10px;white-space:nowrap;
    font-family:'Zen Maru Gothic',sans-serif}
  .stats{display:flex;gap:26px;margin-top:8px}
  .stat .n{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:30px;line-height:1}
  .stat .n small{font-size:13px;color:var(--ink-soft);margin-left:2px}
  .stat .l{font-size:11px;color:var(--ink-soft);margin-top:2px}
  .back{font-size:13px;color:var(--ai);text-decoration:none;font-family:'Zen Maru Gothic',sans-serif;font-weight:700}
  .math{overflow-x:auto}
  .wrong-ans{color:var(--shu);font-weight:700}
  .scroll{overflow-x:auto}
  footer{margin-top:28px;text-align:center;font-size:11px;color:var(--ink-soft)}
</style>
</head>
<body>
<div class="wrap">

  <header>
    <img class="logo" src="https://chukyokobetsu.com/manage/wp-content/themes/chukyo/images/common/logo_chukyo.png"
         alt="中京個別指導学院">
    <div class="who">
      <b><?= h($me['teacher_name']) ?> 先生</b>
      <span><?= h($role) ?></span>
      <button class="logout" id="logout-btn" type="button">ログアウト</button>
    </div>
  </header>

<?php if ($detail): ?>
  <!-- ============ 生徒詳細 ============ -->
  <div class="bar-row">
    <a class="back" href="<?= h(qtab(['student_id' => null])) ?>">← 生徒一覧へ</a>
<?php foreach ($periodLabels as $key => $label): ?>
    <a class="ptab<?= $period === $key ? ' active' : '' ?>" href="<?= h(qtab(['period' => $key])) ?>"><?= h($label) ?></a>
<?php endforeach; ?>
  </div>

  <div class="card">
    <h1><?= h($detail['student_name']) ?> <span style="font-size:12px;color:var(--ink-soft);font-weight:500;">
      <?= h($detail['login_id']) ?> ・ <?= h($detail['classroom_name']) ?>教室<?= $detail['grade'] ? '・' . h(grade_label($detail['grade'])) : '' ?></span></h1>
    <div class="stats">
      <div class="stat"><div class="n"><?= $dMinutes ?><small>分</small></div><div class="l">学習時間</div></div>
      <div class="stat"><div class="n"><?= $dSolved ?><small>問</small></div><div class="l">解いた問題</div></div>
      <div class="stat"><div class="n"><?= $dRate ?><small>%</small></div><div class="l">正答率</div></div>
    </div>
  </div>

  <div class="card">
    <h2>単元カルテ（<?= h($periodLabels[$period]) ?>）</h2>
<?php if (count($dUnits) === 0): ?>
    <p style="font-size:13px;color:var(--ink-soft);">この期間の解答記録はありません</p>
<?php else: ?>
<?php foreach ($dUnits as $unitKey => $rows):
    $meta = $unitMeta[$unitKey] ?? ['title' => $unitKey, 'sub' => ''];
?>
    <p style="font-size:13px;font-weight:700;margin-top:8px;"><?= h($meta['title']) ?> <span style="font-size:11px;color:var(--ink-soft);font-weight:500;"><?= h($meta['sub']) ?></span></p>
    <div class="scroll">
    <table>
      <tr><th>種類</th><th class="num">解答数</th><th class="num">正解</th><th class="num">正答率</th></tr>
<?php foreach ($rows as $row):
    $solved = (int)$row['solved'];
    $correct = (int)$row['correct'];
    $rate = $solved > 0 ? (int)round(100 * $correct / $solved) : 0;
?>
      <tr>
        <td><?= h($row['label']) ?></td>
        <td class="num"><?= $solved ?></td>
        <td class="num"><?= $correct ?></td>
        <td class="num <?= $rate < 60 ? 'lowrate' : ($rate >= 90 ? 'okrate' : '') ?>"><?= $rate ?>%</td>
      </tr>
<?php endforeach; ?>
    </table>
    </div>
<?php endforeach; ?>
<?php endif; ?>
  </div>

  <div class="card">
    <h2>直近の誤答（最大30件）</h2>
<?php if (count($dWrongs) === 0): ?>
    <p style="font-size:13px;color:var(--ink-soft);">この期間の誤答はありません</p>
<?php else: ?>
    <div class="scroll">
    <table>
      <tr><th>日時</th><th>種類</th><th>問題</th><th>正解</th><th>生徒の答え</th></tr>
<?php foreach ($dWrongs as $wr): ?>
      <tr>
        <td style="white-space:nowrap;"><?= h(substr($wr['answered_at'], 5, 11)) ?></td>
        <td><span class="chip"><?= h($wr['label']) ?></span></td>
        <td class="math" data-math="<?= h($wr['question_text']) ?>"><?= h($wr['question_text']) ?></td>
        <td class="math" data-math="<?= h($wr['correct_answer']) ?>"><?= h($wr['correct_answer']) ?></td>
        <td class="math wrong-ans" data-math="<?= h($wr['student_answer']) ?>"><?= h($wr['student_answer']) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    </div>
<?php endif; ?>
  </div>

  <div class="card">
    <h2>学習セッション（最大20件）</h2>
<?php if (count($dSessions) === 0): ?>
    <p style="font-size:13px;color:var(--ink-soft);">この期間の学習セッションはありません</p>
<?php else: ?>
    <div class="scroll">
    <table>
      <tr><th>開始日時</th><th>単元</th><th class="num">時間</th><th class="num">解答数</th><th class="num">正解</th><th>端末</th></tr>
<?php foreach ($dSessions as $ss):
    $unitTitle = ($unitMeta[$ss['unit_key']] ?? null)['title'] ?? $ss['unit_key'];
?>
      <tr>
        <td style="white-space:nowrap;"><?= h(substr($ss['started_at'], 5, 11)) ?></td>
        <td><?= h($unitTitle) ?></td>
        <td class="num"><?= $ss['duration_sec'] !== null ? floor((int)$ss['duration_sec'] / 60) . '分' : '-' ?></td>
        <td class="num"><?= (int)$ss['total_questions'] ?></td>
        <td class="num"><?= (int)$ss['correct_count'] ?></td>
        <td><?= h($ss['device_label']) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    </div>
<?php endif; ?>
  </div>

<?php else: ?>
  <!-- ============ 生徒一覧 ============ -->
  <div class="bar-row">
<?php foreach ($periodLabels as $key => $label): ?>
    <a class="ptab<?= $period === $key ? ' active' : '' ?>" href="<?= h(qtab(['period' => $key])) ?>"><?= h($label) ?></a>
<?php endforeach; ?>
    <span style="flex:1"></span>
<?php if (count($classrooms) > 1): ?>
    <a class="ptab<?= empty($_GET['classroom_id']) ? ' active' : '' ?>" href="<?= h(qtab(['classroom_id' => null])) ?>">全教室</a>
<?php foreach ($classrooms as $c): ?>
    <a class="ptab<?= (int)($_GET['classroom_id'] ?? 0) === (int)$c['classroom_id'] ? ' active' : '' ?>"
       href="<?= h(qtab(['classroom_id' => $c['classroom_id']])) ?>"><?= h($c['classroom_name']) ?></a>
<?php endforeach; ?>
<?php endif; ?>
  </div>

  <div class="card">
    <h1>生徒一覧 <span style="font-size:12px;color:var(--ink-soft);font-weight:500;">（<?= h($periodLabels[$period]) ?>の学習状況）</span></h1>
<?php if (count($students) === 0): ?>
    <p style="font-size:13px;color:var(--ink-soft);margin-top:8px;">表示できる生徒がいません</p>
<?php else: ?>
    <div class="scroll">
    <table>
      <tr><th>生徒</th><th>コード</th><th>教室</th><th>学年</th>
        <th class="num">学習時間</th><th class="num">解答数</th><th class="num">正答率</th>
        <th class="num">解き直し</th><th>最終学習</th></tr>
<?php foreach ($students as $s):
    $solved = (int)$s['solved'];
    $rate = $solved > 0 ? (int)round(100 * (int)$s['correct'] / $solved) : null;
?>
      <tr>
        <td><a class="sname" href="<?= h(qtab(['student_id' => $s['student_id']])) ?>"><?= h($s['student_name']) ?></a></td>
        <td><?= h($s['login_id']) ?></td>
        <td><?= h($s['classroom_name']) ?></td>
        <td><?= h(grade_label($s['grade'])) ?></td>
        <td class="num"><?= floor((int)$s['sec'] / 60) ?>分</td>
        <td class="num"><?= $solved ?></td>
        <td class="num <?= $rate !== null && $rate < 60 ? 'lowrate' : ($rate !== null && $rate >= 90 ? 'okrate' : '') ?>">
          <?= $rate !== null ? $rate . '%' : '-' ?></td>
        <td class="num"><?= (int)$s['retries'] ?></td>
        <td style="white-space:nowrap;"><?= $s['last_at'] ? h(substr($s['last_at'], 0, 16)) : '-' ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    </div>
<?php endif; ?>
  </div>
<?php endif; ?>

  <footer>中京個別指導学院 講師ページ</footer>
</div>
<script>
document.getElementById('logout-btn').addEventListener('click', async () => {
  await fetch('/api/logout.php', { method: 'POST', credentials: 'same-origin' });
  location.reload();
});
// LaTeX形式のセルをKaTeXで整形（数式でなければそのまま）
document.querySelectorAll('.math').forEach(function (el) {
  var src = el.getAttribute('data-math') || '';
  if (!/[\\^_{}]/.test(src)) return;
  try { katex.render(src, el, { throwOnError: true, displayMode: false }); } catch (e) {}
});
</script>
</body>
</html>
