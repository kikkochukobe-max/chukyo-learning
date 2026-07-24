<?php
declare(strict_types=1);

// 保護者閲覧ページ。ひもづく子ども全員の学習サマリー（学習時間・種類別の解答数/正解数/正答率）を表示。
// 設計ルール: 誤解答の詳細・端末情報は出さない（それらは講師画面専用）。
// 保護者ログインは login_id = g+代表の子の生徒コード / パスワード = お子さまの生徒PIN（4桁）。
// 保護者は自前のパスワードを持たず、ひもづくお子さまのうち誰かの生徒PINが合えばログインできる
// （認証は auth.php の actor_type=guardian が生徒側の password_hash と照合）。
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
if (!in_array($period, ['today', 'yesterday', 'week', 'last_week', 'month', 'all'], true)) {
    $period = 'week';
}
$thisMonday = new DateTimeImmutable('monday this week');
switch ($period) {
    case 'today':     $from = new DateTimeImmutable('today'); $to = $from->modify('+1 day'); break;
    case 'yesterday': $from = new DateTimeImmutable('yesterday'); $to = $from->modify('+1 day'); break;
    case 'last_week': $from = $thisMonday->modify('-7 days'); $to = $thisMonday; break;
    case 'month':     $from = new DateTimeImmutable('first day of this month 00:00:00'); $to = $from->modify('+1 month'); break;
    case 'all':       $from = null; $to = null; break;
    default:          $from = $thisMonday; $to = $thisMonday->modify('+7 days'); break;
}
$periodLabels = ['today' => '今日', 'yesterday' => '昨日', 'week' => '今週', 'last_week' => '先週', 'month' => '今月', 'all' => 'これまで'];

// 学習の足あと（講師ページと同じ日別カレンダー）: 期間タブとは独立に直近35日を集計する。
// 期間タブは4カードの初期値(＝そのまとめ)を決め、カレンダーの日付タップで一時的に上書きする。
$todayStr = (new DateTimeImmutable('today'))->format('Y-m-d');
$footFrom = (new DateTimeImmutable('today'))->modify('-34 days')->format('Y-m-d 00:00:00');

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
    $g = $stmt->fetch();
    $guardianName = (string)($g['guardian_name'] ?? '');
}
if ($isGuardian) {
    $pdo = db();
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

        // 学習の足あと（直近35日・日別）: 分＝学習時間 / 問＝解答数 / 正解数を日別に。
        // 講師ページと同じカレンダー用。日付タップで4カードをその日の値に差し替える。
        $daily = [];   // 'Y-m-d' => ['min'=>, 'solved'=>, 'correct'=>]
        $touchDay = function (string $d) use (&$daily) {
            if (!isset($daily[$d])) $daily[$d] = ['min' => 0, 'solved' => 0, 'correct' => 0];
        };
        $st = $pdo->prepare(
            'SELECT DATE(started_at) AS d, COALESCE(SUM(duration_sec),0) AS sec FROM study_sessions
             WHERE student_id = :id AND started_at >= :ff GROUP BY DATE(started_at)'
        );
        $st->execute(['id' => $sid, 'ff' => $footFrom]);
        foreach ($st->fetchAll() as $row) { $touchDay($row['d']); $daily[$row['d']]['min'] = (int)floor((int)$row['sec'] / 60); }
        $st = $pdo->prepare(
            'SELECT DATE(answered_at) AS d, COUNT(*) AS total, COALESCE(SUM(is_correct),0) AS correct FROM answer_logs
             WHERE student_id = :id AND answered_at >= :ff GROUP BY DATE(answered_at)'
        );
        $st->execute(['id' => $sid, 'ff' => $footFrom]);
        foreach ($st->fetchAll() as $row) { $touchDay($row['d']); $daily[$row['d']]['solved'] = (int)$row['total']; $daily[$row['d']]['correct'] = (int)$row['correct']; }

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
  /* Androidブラウザが端末のフォント設定で本文を勝手に縮小するのを防ぎ、指定サイズで表示する */
  html{-webkit-text-size-adjust:100%;text-size-adjust:100%}
  body{
    font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);
    background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px, transparent 1px),linear-gradient(90deg, var(--grid) 1px, transparent 1px);
    background-size:24px 24px;line-height:1.6;-webkit-font-smoothing:antialiased;
    -webkit-text-size-adjust:100%;text-size-adjust:100%;
  }
  .wrap{max-width:680px;margin:0 auto;padding:0 16px 64px}
  header{display:flex;align-items:center;justify-content:space-between;padding:14px 2px 10px;gap:10px}
  header img.logo{height:34px;width:auto;display:block}
  .who{text-align:right;font-size:13px;color:var(--ink-soft)}
  .who b{display:block;font-size:16px;color:var(--ink);font-family:'Zen Maru Gothic',sans-serif;font-weight:700}
  .who a{color:var(--ai);text-decoration:none;font-size:13px}
  .tolist{display:inline-block;margin:0 2px 6px;font-size:14px;color:var(--ai);text-decoration:none;font-family:'Zen Maru Gothic',sans-serif;font-weight:700}

  .ptabs{display:flex;gap:6px;flex-wrap:wrap;margin:4px 0 12px}
  .ptab{font-size:14px;padding:6px 15px;border-radius:999px;border:1.5px solid var(--grid);
    background:var(--white);color:var(--ink-soft);text-decoration:none;font-weight:700}
  .ptab.active{background:var(--ai);border-color:var(--ai);color:#fff}

  .child{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    border-top:4px solid var(--ai);padding:18px;margin-bottom:16px}
  .child h2{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:20px;color:var(--ai)}
  .child h2 small{font-size:13px;font-weight:500;color:var(--ink-soft);margin-left:6px}
  .stats{display:flex;flex-wrap:wrap;gap:12px 22px;margin-top:12px}
  .stat .num{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:32px;line-height:1;font-feature-settings:'tnum'}
  .stat .num small{font-size:14px;font-weight:700;margin-left:2px;color:var(--ink-soft)}
  .stat .lbl{font-size:12px;color:var(--ink-soft);margin-top:4px}
  .lv{color:var(--kin)}
  .pending{margin-top:10px;font-size:14px;color:var(--shu);font-weight:700}

  /* 学習の足あと（講師ページと同じ日別カレンダー） */
  .foot{margin-top:14px;border-top:1px dashed var(--grid);padding-top:12px}
  .foot-head{display:flex;align-items:center;gap:8px;margin-bottom:8px}
  .foot-title{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:13px;color:var(--ink)}
  .foot-scope{font-size:12px;color:var(--ink-soft);flex:1 1 auto}
  .foot-scope.on{color:var(--ai);font-weight:700}
  .foot-more{flex:0 0 auto;font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:12px;
    color:var(--ai);background:none;border:1.5px solid var(--ai);border-radius:999px;padding:3px 12px;cursor:pointer}
  .foot-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px 4px}
  .foot-cell{border:none;background:none;padding:2px 0 3px;cursor:pointer;text-align:center;font:inherit;border-radius:8px}
  .foot-cell .fd{font-size:10px;color:var(--ink-soft);margin-bottom:3px;
    font-family:system-ui,'Segoe UI',Arial,sans-serif;font-variant-numeric:tabular-nums;white-space:nowrap}
  .foot-cell .fd.sun{color:var(--shu)}
  .foot-cell .fd.sat{color:var(--ai)}
  .foot-cell .sq{width:100%;aspect-ratio:1/1;max-width:26px;margin:0 auto 4px;border-radius:6px;
    background:var(--grid);border:1.5px solid transparent}
  .foot-cell .sq.l1{background:#F3D2CC}
  .foot-cell .sq.l2{background:#E59C8F}
  .foot-cell .sq.l3{background:#D4614E}
  .foot-cell .sq.l4{background:var(--shu)}
  /* 分＝学習時間 / 問＝解いた問題数 を各日に表示（0はグレー） */
  .foot-cell .fm,.foot-cell .fs{font-size:10px;line-height:1.35;color:var(--ink-soft);
    font-family:system-ui,'Segoe UI',Arial,sans-serif;font-variant-numeric:tabular-nums;white-space:nowrap}
  .foot-cell .fm b,.foot-cell .fs b{font-weight:900;color:var(--ink)}
  .foot-cell .fm.z,.foot-cell .fm.z b,.foot-cell .fs.z,.foot-cell .fs.z b{color:#C7C2B6}
  .foot-cell.today .sq{border-color:var(--ink-soft)}
  .foot-cell.today .fd{color:var(--ink);font-weight:700}
  .foot-cell.sel .sq{border-color:var(--ai);box-shadow:0 0 0 2px var(--ai)}
  .foot-cell.sel .fd{color:var(--ai);font-weight:700}

  .unit{margin-top:14px}
  .unit .ut{font-size:15px;font-weight:700;font-family:'Zen Maru Gothic',sans-serif}
  .unit .ut small{font-size:12px;font-weight:500;color:var(--ink-soft);margin-left:4px}
  table{border-collapse:collapse;width:100%;font-size:15px;margin-top:6px}
  th{font-size:12px;color:var(--ink-soft);font-weight:700;text-align:left;border-bottom:2px solid var(--grid);padding:5px 6px}
  td{border-bottom:1px solid #F3F0E8;padding:7px 6px}
  .num{text-align:right;font-feature-settings:'tnum';white-space:nowrap}
  .rate-ok{color:var(--kin);font-weight:700}
  .rate-low{color:#D89A45}
  .empty{color:var(--ink-soft);font-size:14px;padding:6px 0}

  /* ログインフォーム */
  .box{max-width:360px;margin:64px auto;background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);padding:26px 22px;border-top:4px solid var(--ai)}
  .box h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:20px;color:var(--ai);text-align:center}
  .box p.sub{font-size:12px;color:var(--ink-soft);text-align:center;margin-top:4px}
  .box label{display:block;font-size:12px;font-weight:700;margin-top:14px}
  .box input{width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;margin-top:4px}
  .box button{margin-top:18px;width:100%;background:var(--ai);color:#fff;border:none;border-radius:8px;padding:11px;font-size:15px;font-weight:700;cursor:pointer;font-family:'Zen Maru Gothic',sans-serif}
  .box .err{color:var(--shu);font-size:13px;margin-top:10px;text-align:center;min-height:18px}
  footer{margin-top:28px;text-align:center;font-size:12px;color:var(--ink-soft)}
</style>
</head>
<body>
<?php if (!$isGuardian): ?>
<div class="box">
  <h1>保護者ページ</h1>
  <p class="sub">保護者IDと、お子さまのPIN（4桁）でログインしてください</p>
  <label>保護者ID（例: g260038）<input type="text" id="lid" autocomplete="username" autocapitalize="off" autocorrect="off" spellcheck="false"></label>
  <label>お子さまのPIN（4桁）<input type="password" id="lpin" inputmode="numeric" maxlength="4" autocomplete="current-password"></label>
  <button id="login-btn" type="button">ログイン</button>
  <div class="err" id="login-err"></div>
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
<?php else: ?>
<div class="wrap">
  <header>
    <img class="logo" src="https://chukyokobetsu.com/manage/wp-content/themes/chukyo/images/common/logo_chukyo.png" alt="中京個別指導学院">
    <div class="who"><b><?= h($guardianName) ?></b><a href="#" id="logout">ログアウト</a></div>
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
      <div class="stat"><div class="num js-min"><?= $c['minutes'] ?><small>分</small></div><div class="lbl">学習時間</div></div>
      <div class="stat"><div class="num js-solved"><?= $c['solved'] ?><small>問</small></div><div class="lbl">解いた問題</div></div>
      <div class="stat"><div class="num js-correct"><?= $c['correct'] ?><small>問</small></div><div class="lbl">正解</div></div>
      <div class="stat"><div class="num js-rate"><?= $c['rate'] ?><small>%</small></div><div class="lbl">正答率</div></div>
      <div class="stat"><div class="num lv">Lv.<?= $c['level'] ?></div><div class="lbl">レベル（累計）</div></div>
    </div>
<?php if ($c['pending'] > 0): ?>
    <div class="pending">解き直しが <?= $c['pending'] ?>問 のこっています</div>
<?php endif; ?>

    <!-- 足あと（直近2週間、「さらに見る」で1か月。日付タップで上のカードがその日に切替） -->
    <div class="foot">
      <div class="foot-head">
        <span class="foot-title">足あと</span>
        <span class="foot-scope js-foot-scope"><?= h($periodLabels[$period]) ?>のまとめ</span>
        <button type="button" class="foot-more js-foot-more" aria-expanded="false">さらに見る ▼</button>
      </div>
      <div class="foot-grid js-foot-grid"></div>
      <script type="application/json" class="js-foot-data"><?= json_encode([
          'today'  => $todayStr,
          'period' => $periodLabels[$period],
          'daily'  => $c['daily'],
      ], JSON_UNESCAPED_UNICODE) ?></script>
    </div>

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

// ===== 学習の足あと（講師ページと同じ日別カレンダー。日付タップでその子の4カードをその日に切替） =====
// 子どもが複数いるので .foot ごとに独立して動かす（IDでなくクラス＋closest('.child')でスコープ）。
(function () {
  var WD = ['日', '月', '火', '水', '木', '金', '土'];
  function pad(x) { return x < 10 ? '0' + x : '' + x; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function level(s) { return s <= 0 ? 0 : (s < 5 ? 1 : (s < 15 ? 2 : (s < 40 ? 3 : 4))); }

  document.querySelectorAll('.foot').forEach(function (foot) {
    var grid = foot.querySelector('.js-foot-grid');
    var dataEl = foot.querySelector('.js-foot-data');
    var moreBtn = foot.querySelector('.js-foot-more');
    var scopeEl = foot.querySelector('.js-foot-scope');
    if (!grid || !dataEl) return;
    var data;
    try { data = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
    var daily = data.daily || {};
    var today = data.today;
    if (!today) return;
    var periodLabel = data.period || '';

    // この子の4カード（＝期間のまとめ）。日付タップで一時的に上書き、解除で戻す。レベルは累計なので触らない。
    var section = foot.closest('.child');
    var cards = {
      min: section && section.querySelector('.js-min'),
      solved: section && section.querySelector('.js-solved'),
      correct: section && section.querySelector('.js-correct'),
      rate: section && section.querySelector('.js-rate')
    };
    var defHTML = {};
    Object.keys(cards).forEach(function (k) { if (cards[k]) defHTML[k] = cards[k].innerHTML; });

    var expanded = false, selKey = null;

    function dateList(n) {                       // 左上=今日、そこから過去へ（新しい→古い）
      var base = new Date(today + 'T00:00:00');  // ローカル時刻で解釈＝日付ずれを防ぐ
      var arr = [];
      for (var i = 0; i < n; i++) {
        var d = new Date(base.getTime());
        d.setDate(d.getDate() - i);
        arr.push(d);
      }
      return arr;
    }

    function applyScope() {
      if (selKey) {
        var d = new Date(selKey + 'T00:00:00');
        var r = daily[selKey] || { min: 0, solved: 0, correct: 0 };
        var rate = r.solved > 0 ? Math.round(100 * r.correct / r.solved) : 0;
        if (cards.min) cards.min.innerHTML = (r.min || 0) + '<small>分</small>';
        if (cards.solved) cards.solved.innerHTML = (r.solved || 0) + '<small>問</small>';
        if (cards.correct) cards.correct.innerHTML = (r.correct || 0) + '<small>問</small>';
        if (cards.rate) cards.rate.innerHTML = rate + '<small>%</small>';
        if (scopeEl) { scopeEl.textContent = (d.getMonth() + 1) + '/' + d.getDate() + '（' + WD[d.getDay()] + '）の記録'; scopeEl.classList.add('on'); }
      } else {
        Object.keys(cards).forEach(function (k) { if (cards[k]) cards[k].innerHTML = defHTML[k]; });
        if (scopeEl) { scopeEl.textContent = periodLabel + 'のまとめ'; scopeEl.classList.remove('on'); }
      }
    }

    function render() {
      grid.innerHTML = '';
      dateList(expanded ? 35 : 14).forEach(function (d) {
        var key = ymd(d);
        var r = daily[key] || { min: 0, solved: 0 };
        var wd = d.getDay(), lv = level(r.solved || 0);
        var mn = r.min || 0, sv = r.solved || 0;
        var cell = document.createElement('button');
        cell.type = 'button';
        cell.className = 'foot-cell' + (key === today ? ' today' : '') + (key === selKey ? ' sel' : '');
        cell.setAttribute('data-key', key);
        cell.innerHTML = '<div class="fd' + (wd === 0 ? ' sun' : (wd === 6 ? ' sat' : '')) + '">'
          + (d.getMonth() + 1) + '/' + d.getDate() + '</div>'
          + '<div class="sq' + (lv ? ' l' + lv : '') + '"></div>'
          + '<div class="fm' + (mn > 0 ? '' : ' z') + '"><b>' + mn + '</b>分</div>'
          + '<div class="fs' + (sv > 0 ? '' : ' z') + '"><b>' + sv + '</b>問</div>';
        cell.addEventListener('click', function () {
          selKey = (selKey === key) ? null : key;
          applyScope();
          grid.querySelectorAll('.foot-cell').forEach(function (c) {
            c.classList.toggle('sel', c.getAttribute('data-key') === selKey);
          });
        });
        grid.appendChild(cell);
      });
    }

    if (moreBtn) {
      moreBtn.addEventListener('click', function () {
        expanded = !expanded;
        moreBtn.textContent = expanded ? 'とじる ▲' : 'さらに見る ▼';
        moreBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        render();
      });
    }
    render();
  });
})();
</script>
<?php endif; ?>
</body>
</html>
