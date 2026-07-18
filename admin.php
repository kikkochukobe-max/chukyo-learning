<?php
declare(strict_types=1);

// アカウント管理ページ: 生徒登録・生徒一括登録・保護者登録・講師登録・登録一覧 をタブで切替。
// デザインは講師ページと同じ藍基調。権限で表示を出し分ける:
//   super_admin     = 全機能(講師登録・講師一覧はここだけ)
//   classroom_admin = 担当教室の生徒登録・保護者登録・一覧
//   teacher         = 登録権限なし(案内のみ)
// 登録一覧では、このページを開いている間に登録・変更した行を色違いで表示する。
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$actor = current_actor();

// ---- 未ログイン時: 講師ログインフォーム(teacher.phpと同じ) ----
if (!$actor || $actor['type'] !== 'teacher') {
    ?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アカウント管理（登録・変更・削除） | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root{--paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;--ai:#2C5F8A;--white:#fff;
    --radius:14px;--shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06)}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6}
  .box{max-width:440px;margin:80px auto;background:var(--white);border-radius:var(--radius);
    box-shadow:var(--shadow);border-top:4px solid var(--ai);padding:28px}
  h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:18px;color:var(--ai);white-space:nowrap}
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
  <h1>アカウント管理（登録・変更・削除）</h1>
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

$stmt = $pdo->prepare('SELECT role, teacher_name, login_id, must_change_password FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $teacherId]);
$me = $stmt->fetch();
$role = $me['role'];

// 初期パスワードのままなら、変更するまで先に進ませない
if ((int)$me['must_change_password'] === 1) {
    header('Location: /password.php');
    exit;
}
$canRegister = in_array($role, ['super_admin', 'classroom_admin'], true);

// 登録フォームの教室プルダウン(権限内のみ)
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
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アカウント管理（登録・変更・削除） | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@500;700;900&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<style>
  :root{
    --paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;
    --shu:#C73E2E;--ai:#2C5F8A;--ai-soft:#E3ECF4;--kin:#C9A227;--kin-soft:#FBF3D9;--white:#fff;
    --radius:14px;--shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06);
  }
  *{margin:0;padding:0;box-sizing:border-box}
  body{
    font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6;-webkit-font-smoothing:antialiased;
    -webkit-text-size-adjust:100%;text-size-adjust:100%;
  }
  .wrap{max-width:1100px;margin:0 auto;padding:0 16px 64px}
  header{display:flex;align-items:center;justify-content:space-between;padding:14px 2px 10px;flex-wrap:wrap;gap:8px}
  header img.logo{height:34px;width:auto;display:block}
  .who{font-size:12px;color:var(--ink-soft);display:flex;align-items:center;gap:10px}
  .who b{font-size:14px;color:var(--ai);font-family:'Zen Maru Gothic',sans-serif}
  .logout{font-size:11px;color:var(--ai);border:1px solid var(--ai);border-radius:999px;
    padding:3px 12px;background:none;cursor:pointer;font-family:'Zen Maru Gothic',sans-serif;font-weight:700}
  .nav{font-size:13px;margin-top:4px}
  .nav a{color:var(--ai);font-family:'Zen Maru Gothic',sans-serif;font-weight:700;text-decoration:none}

  /* 機能タブ */
  .tabs{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}
  .tab{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:13px;
    padding:6px 18px;border-radius:999px;cursor:pointer;
    background:var(--white);color:var(--ink-soft);border:1.5px solid var(--grid)}
  .tab.active{background:var(--ai);color:#fff;border-color:var(--ai)}

  .card{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    border-top:4px solid var(--ai);padding:18px;margin-top:14px}
  .card h2{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:16px;color:var(--ai)}
  .card p.note{font-size:12px;color:var(--ink-soft);margin-top:2px}
  label{display:block;font-size:12px;font-weight:700;margin-top:12px}
  input,select,textarea{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;
    font-size:14px;margin-top:4px;font-family:inherit;background:var(--white)}
  textarea{height:140px;font-family:monospace}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}
  @media(max-width:520px){.row2{grid-template-columns:1fr}}
  button.go{margin-top:14px;background:var(--ai);color:#fff;border:none;border-radius:8px;
    padding:10px 22px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Zen Maru Gothic',sans-serif}
  button.go.sub{background:var(--white);color:var(--ai);border:1.5px solid var(--ai)}
  .msg{font-size:13px;margin-top:10px;min-height:18px;white-space:pre-wrap;word-break:break-all}
  .msg.ok{color:#166534;font-weight:700}
  .msg.ng{color:var(--shu);font-weight:700}
  .chks{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
  .chks label{display:inline-flex;align-items:center;gap:4px;font-size:13px;font-weight:500;margin:0;
    background:var(--white);border:1.5px solid var(--grid);border-radius:999px;padding:3px 12px;cursor:pointer}
  .chks input{width:auto;margin:0}
  table{border-collapse:collapse;width:100%;font-size:13px;margin-top:10px}
  th{font-size:11px;color:var(--ink-soft);font-weight:700;text-align:left;
    border-bottom:2px solid var(--ai-soft);padding:6px 8px;white-space:nowrap}
  th.sortable{cursor:pointer;user-select:none}
  th.sortable:hover{color:var(--ai,#2C5F8A)}
  th.sortable .arw{font-size:9px;margin-left:3px;color:var(--ai,#2C5F8A)}
  td{border-bottom:1px solid #F3F0E8;padding:6px 8px}
  .ok-cell{color:#166534;font-weight:700}
  .ng-cell{color:var(--shu);font-weight:700}
  tr.new-row td{background:var(--kin-soft)}
  .newmark{display:inline-block;font-size:10px;font-weight:900;color:var(--kin);
    font-family:'Zen Maru Gothic',sans-serif;margin-left:4px}
  .inactive{color:var(--ink-soft)}
  .state-on{color:#166534;font-weight:700;white-space:nowrap}
  .state-off{color:var(--ink-soft);font-weight:700;white-space:nowrap}
  button.mini{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:11px;
    border-radius:999px;padding:2px 12px;cursor:pointer;white-space:nowrap;background:var(--white)}
  button.mini.off{color:var(--shu);border:1.5px solid var(--shu)}
  button.mini.on{color:var(--ai);border:1.5px solid var(--ai)}
  button.mini.del{color:#fff;background:var(--shu);border:1.5px solid var(--shu);margin-left:6px}
  .scroll{overflow-x:auto}
  .list-count{margin:6px 0;font-size:13px;font-weight:700;color:var(--ink-soft)}
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
      <a class="logout" href="/password.php" style="text-decoration:none;">パスワード変更</a>
      <button class="logout" id="logout-btn" type="button">ログアウト</button>
    </div>
  </header>
  <div class="nav"><a href="/teacher.php">← 講師ページ（学習状況の確認）へ</a></div>

<?php if (!$canRegister): ?>
  <div class="card">
    <h2>アカウント管理</h2>
    <p class="note">登録の権限がありません。アカウントの発行は教室管理者または統括にご依頼ください。</p>
  </div>
<?php else: ?>

  <!-- ============ 機能タブ ============ -->
  <div class="tabs">
    <button class="tab active" data-tab="student" type="button">生徒登録</button>
    <button class="tab" data-tab="bulk" type="button">生徒一括登録</button>
<?php if ($role === 'super_admin'): ?>
    <button class="tab" data-tab="school" type="button">志望校</button>
<?php endif; ?>
    <button class="tab" data-tab="guardian" type="button">保護者登録</button>
<?php if ($role === 'super_admin'): ?>
    <button class="tab" data-tab="teacher" type="button">講師登録</button>
<?php endif; ?>
    <button class="tab" data-tab="list" type="button">生徒・保護者・講師一覧＆修正</button>
  </div>

  <!-- ============ 生徒登録 ============ -->
  <div class="pane" id="pane-student">
  <div class="card">
    <h2>生徒登録</h2>
    <p class="note">生徒コードは自動採番（入塾年度2桁+通し番号4桁）。<strong>保護者アカウント（ID=生徒コードにg、仮パスワード自動発行）も同時に自動発行</strong>されます。登録後に下に出る案内文をコピーしてご家庭に渡してください。<br>
      ※兄弟が後から入塾した場合も自動発行されます。上の子の保護者IDにまとめたいときは「保護者登録」タブの「兄弟・姉妹の追加」で付け替えてください（自動発行された方は無効化されます）。</p>
    <form id="student-form">
      <div class="row2">
        <label>教室
          <select name="classroom_id" required>
<?php foreach ($classrooms as $c): ?>
            <option value="<?= (int)$c['classroom_id'] ?>"><?= h($c['classroom_name']) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <label>生徒氏名<input type="text" name="student_name" required maxlength="50"></label>
        <label>学年（例: es4, js1。空欄可）<input type="text" name="grade" maxlength="10" placeholder="js1"></label>
        <label>PIN（4桁数字）<input type="text" name="pin" pattern="\d{4}" maxlength="4" inputmode="numeric" required autocomplete="off"></label>
        <label>私立志望校（任意）
          <select name="target_private_id" data-school="private"><option value="">（未設定）</option></select>
        </label>
        <label>公立志望校（任意）
          <select name="target_public_id" data-school="public"><option value="">（未設定）</option></select>
        </label>
      </div>
      <button class="go" type="submit">生徒を登録</button>
      <div class="msg" id="student-msg"></div>
    </form>
    <div id="student-guide" style="display:none;margin-top:14px;">
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
        <button class="go" type="button" id="student-guide-copy" style="width:auto;padding:6px 14px;">案内文をコピー</button>
        <span id="student-guide-note" style="font-size:12px;color:var(--ink-soft);"></span>
      </div>
      <textarea id="student-guide-text" readonly style="height:280px;"></textarea>
    </div>
  </div>
  </div>

  <!-- ============ 生徒一括登録 ============ -->
  <div class="pane" id="pane-bulk" style="display:none;">
  <div class="card">
    <h2>生徒一括登録</h2>
    <p class="note">1行1人、「教室ID・生徒名・学年・PIN」の4列（学年は空欄可）。Excelで4列を範囲コピーしてそのまま貼り付けるか、UTF-8のCSVを読み込みます。<strong>保護者アカウント（ID=生徒コードにg、仮パスワード自動発行）も1人ずつ同時に自動発行</strong>されます。登録後に「案内文をまとめて生成」でご家庭向けの案内文（生徒のコード/PIN・保護者のID/仮パスワード入り）を作成・コピーできます（保護者の仮パスワードはこの画面でしか確認できません）。<br>
      教室ID: <?= h(implode(' / ', array_map(fn($c) => $c['classroom_id'] . '=' . $c['classroom_name'], $classrooms))) ?></p>
    <label>CSVファイルを読み込む<input type="file" id="csv-file" accept=".csv,.txt"></label>
    <form id="bulk-form">
      <label>貼り付け欄（エクセルからコピー&amp;ペースト可）<textarea name="rows" placeholder="1&#9;山田太郎&#9;es4&#9;1234&#10;2&#9;鈴木花子&#9;&#9;5678"></textarea></label>
      <button class="go" type="submit">一括登録を実行</button>
    </form>
    <div class="scroll">
    <table id="bulk-table" style="display:none;">
      <thead><tr><th>#</th><th>入力</th><th>結果</th><th>生徒コード・保護者 / エラー</th></tr></thead>
      <tbody></tbody>
    </table>
    </div>
    <div id="bulk-guide-wrap" style="display:none;margin-top:14px;">
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
        <button class="go" type="button" id="bulk-guide-btn" style="width:auto;padding:6px 14px;">案内文をまとめて生成</button>
        <button class="go" type="button" id="bulk-guide-copy" style="width:auto;padding:6px 14px;display:none;">案内文をコピー</button>
        <span id="bulk-guide-note" style="font-size:12px;color:var(--ink-soft);"></span>
      </div>
      <textarea id="bulk-guide-text" readonly style="height:320px;display:none;"></textarea>
    </div>
  </div>
  </div>

  <!-- ============ 保護者登録 ============ -->
  <div class="pane" id="pane-guardian" style="display:none;">
  <div class="card">
    <h2>保護者登録</h2>
    <p class="note"><strong>新しく登録する生徒は「生徒登録」時に保護者アカウントが自動発行されるため、このフォームは不要です。</strong>保護者アカウントがまだ無い既存の生徒に後から発行するときに使ってください。<br>
      お子さまの生徒コードと紐づけて登録します（兄弟はカンマ区切りで複数可）。<strong>保護者IDは「最初に入力したお子さまの生徒コードに g を付けたもの」を自動発行</strong>します（例: 260038 → g260038）。保護者氏名の登録は不要で、<strong>「代表のお子さまの生徒名＋保護者様」が表示名</strong>になります。<strong>仮パスワード（8文字の英数）も自動発行され、登録直後に一度だけ表示</strong>されるので、保護者に伝えてください。保護者は初回ログイン時にご自身のパスワード（8〜15文字の半角英数）へ変更します。保護者ページ: <a href="/guardian.php" target="_blank">/guardian.php</a></p>
    <form id="guardian-form">
      <div class="row2">
        <label>お子さまの生徒コード（例: 260001, 260002 ／ 先頭が代表）<input type="text" name="student_codes" required placeholder="260001, 260002"></label>
      </div>
      <button class="go" type="submit">保護者を登録</button>
      <div class="msg" id="guardian-msg"></div>
    </form>
    <div id="guardian-guide" style="display:none;margin-top:14px;">
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
        <button class="go" type="button" id="guardian-guide-copy" style="width:auto;padding:6px 14px;">案内文をコピー</button>
        <span id="guardian-guide-note" style="font-size:12px;color:var(--ink-soft);"></span>
      </div>
      <textarea id="guardian-guide-text" readonly style="height:280px;"></textarea>
    </div>
  </div>

  <div class="card">
    <h2>保護者一括登録</h2>
    <p class="note"><strong>保護者アカウントがまだ無い既存の生徒への後追い発行用</strong>です（新規生徒は生徒登録時に自動発行されます）。<br>
      1行=1家庭。<strong>お子さまの生徒コード（6桁）を並べて書くだけ</strong>です（区切りはスペース・カンマ・タブどれでも可。Excelからの貼り付けもそのまま使えます。数字6桁以外の文字は無視されます）。兄弟は同じ行にまとめてください（先頭が代表→保護者IDになります）。保護者の表示名は「代表のお子さまの生徒名＋保護者様」が自動で付きます。<br>
      例: <code>260001 260002</code>（兄弟） ／ <code>260003</code><br>
      <strong>仮パスワード（8文字の英数）を自動発行し、下の結果一覧にだけ表示します</strong>（画面を離れると再表示できません。保護者は初回ログイン時にご自身のパスワードへ変更します）。「結果をコピー」で Excel に貼り付けて各教室へ配布してください。<br>
      すでに保護者が登録されているお子さまを含む行はエラーになります（下の子の追加は「兄弟・姉妹の追加」を使ってください）。</p>
    <form id="gbulk-form">
      <label>貼り付け欄<textarea name="rows" placeholder="260001, 260002&#10;260003"></textarea></label>
      <button class="go" type="submit">一括登録を実行</button>
    </form>
    <div class="msg" id="gbulk-msg"></div>
    <div id="gbulk-result" style="display:none;margin-top:14px;">
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
        <button class="go" type="button" id="gbulk-copy-btn" style="width:auto;padding:6px 14px;">結果をコピー（Excel貼付用）</button>
        <button class="go" type="button" id="gbulk-guide-btn" style="width:auto;padding:6px 14px;">案内文をまとめて生成</button>
        <button class="go" type="button" id="gbulk-guide-copy" style="width:auto;padding:6px 14px;display:none;">案内文をコピー</button>
        <span id="gbulk-copy-note" style="font-size:12px;color:var(--ink-soft);"></span>
      </div>
      <div class="scroll">
      <table>
        <thead><tr><th>#</th><th>結果</th><th>教室</th><th>保護者ID</th><th>仮パスワード</th><th>表示名</th><th>お子さま</th></tr></thead>
        <tbody id="gbulk-tbody"></tbody>
      </table>
      </div>
      <textarea id="gbulk-guide-text" readonly style="height:320px;display:none;margin-top:10px;"></textarea>
    </div>
  </div>

  <div class="card">
    <h2>兄弟・姉妹の追加</h2>
    <p class="note">後から下のお子さまが入塾したときに、既存の保護者アカウントへ追加でひもづけます（保護者ID・PINはそのまま）。保護者の指定は「保護者ID（g〜）」でも「すでに登録済みのお兄さん・お姉さんの生徒コード」でもかまいません（登録一覧の保護者タブにある「兄弟追加」ボタンからも呼び出せます）。<br>
      <strong>別々に登録されていた兄弟の統合もここでできます</strong>: 追加する子がすでに別の保護者アカウントに登録されている場合は確認が出て、OKするとこの保護者に付け替えます（空になった元のアカウントは自動で無効化）。</p>
    <form id="sibling-form">
      <div class="row2">
        <label>保護者ID または 登録済みのお子さまの生徒コード<input type="text" name="guardian_key" required placeholder="g260001 または 260001"></label>
        <label>追加するお子さまの生徒コード（複数はカンマ区切り）<input type="text" name="student_codes" required placeholder="260123"></label>
      </div>
      <button class="go" type="submit">兄弟を追加</button>
      <div class="msg" id="sibling-msg"></div>
    </form>
  </div>
  </div>

<?php if ($role === 'super_admin'): ?>
  <!-- ============ 講師登録(統括のみ) ============ -->
  <div class="pane" id="pane-teacher" style="display:none;">
  <div class="card">
    <h2>講師登録 <span style="font-size:11px;color:var(--ink-soft);font-weight:500;">（統括のみ）</span></h2>
    <p class="note">仮パスワードは登録時に自動生成されます。本人が初回ログインで8〜15文字の半角英数に設定し直します。</p>
    <form id="teacher-form">
      <div class="row2">
        <label>ログインID<input type="text" name="login_id" required maxlength="50"></label>
        <label>氏名<input type="text" name="teacher_name" required maxlength="50"></label>
        <label>役割
          <select name="role">
            <option value="teacher">teacher（閲覧のみ）</option>
            <option value="classroom_admin">classroom_admin（教室管理者・生徒登録可）</option>
            <option value="super_admin">super_admin（統括・全教室）</option>
          </select>
        </label>
      </div>
      <label>担当教室（super_admin 以外は1つ以上チェック。兼任は複数可）</label>
      <div class="chks">
<?php foreach ($classrooms as $c): ?>
        <label><input type="checkbox" name="classroom_ids" value="<?= (int)$c['classroom_id'] ?>"><?= h($c['classroom_name']) ?></label>
<?php endforeach; ?>
      </div>
      <button class="go" type="submit">講師を登録</button>
      <div class="msg" id="teacher-msg"></div>
    </form>
    <div id="teacher-guide" style="display:none;margin-top:14px;">
      <p class="note" style="margin-bottom:8px;">この案内メールをコピーして先生にお送りください（仮パスワードはこの画面でしか確認できません）。</p>
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
        <button class="go" type="button" id="teacher-guide-copy" style="width:auto;padding:6px 14px;">メール文をコピー</button>
        <span id="teacher-guide-note" style="font-size:12px;color:var(--ink-soft);"></span>
      </div>
      <textarea id="teacher-guide-text" readonly style="height:300px;"></textarea>
    </div>
  </div>

  <div class="card">
    <h2>既存講師の一括初期化 <span style="font-size:11px;color:var(--ink-soft);font-weight:500;">（統括のみ）</span></h2>
    <p class="note">まだ本パスワードを設定していない講師（＝初回変更が済んでいない講師）全員に、新しい仮パスワードをまとめて発行します。<strong>すでにログインして本人がパスワードを設定した講師は対象外</strong>なので巻き込みません。発行された一覧はこの画面でのみ確認・コピーできます（再表示不可）。</p>
    <button class="go" type="button" id="bulk-reset-btn">未設定の講師をまとめて初期化</button>
    <div class="msg" id="bulk-reset-msg"></div>
    <div id="bulk-reset-result" style="display:none;margin-top:14px;">
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
        <button class="go" type="button" id="bulk-copy-btn" style="width:auto;padding:6px 14px;">一覧をコピー（Excel貼付用）</button>
        <span id="bulk-copy-note" style="font-size:12px;color:var(--ink-soft);"></span>
      </div>
      <table>
        <thead><tr><th>氏名</th><th>ログインID</th><th>仮パスワード</th></tr></thead>
        <tbody id="bulk-reset-tbody"></tbody>
      </table>
      <div style="display:flex;gap:8px;align-items:center;margin:14px 0 8px;">
        <button class="go" type="button" id="bulk-mail-btn" style="width:auto;padding:6px 14px;">案内メールをまとめて生成</button>
        <button class="go" type="button" id="bulk-mail-copy" style="width:auto;padding:6px 14px;display:none;">メール文をコピー</button>
        <span id="bulk-mail-note" style="font-size:12px;color:var(--ink-soft);"></span>
      </div>
      <textarea id="bulk-mail-text" readonly style="height:320px;display:none;"></textarea>
    </div>
  </div>
  </div>
<?php endif; ?>

  <!-- ============ 志望校マスター（統括のみ） ============ -->
<?php if ($role === 'super_admin'): ?>
  <div class="pane" id="pane-school" style="display:none;">
  <div class="card">
    <h2>志望校マスター <span style="font-size:11px;color:var(--ink-soft);font-weight:500;">（統括のみ）</span></h2>
    <p class="note">私立・公立それぞれ学校名を登録します。ここで登録した学校が、生徒登録・生徒情報の修正の志望校プルダウンと、講師ページの志望校ランキングに出ます。<br>
      使わなくなった学校は「無効にする」で隠せます（生徒にひもづけた記録は残り、いつでも「有効に戻す」で復活できます）。同じ名前でも私立・公立は別々に登録できます。</p>
    <form id="school-form" class="row2" style="align-items:end;">
      <label>学校名<input type="text" name="name" maxlength="80" required></label>
      <label>種別
        <div style="display:flex;gap:8px;">
          <select name="kind"><option value="private">私立</option><option value="public">公立</option></select>
          <button class="go" type="submit" style="margin-top:4px;white-space:nowrap;">追加</button>
        </div>
      </label>
    </form>
    <div class="msg" id="school-msg"></div>
    <div class="scroll">
    <table id="school-table">
      <thead><tr><th>学校名</th><th>種別</th><th>状態</th><th>操作</th></tr></thead>
      <tbody></tbody>
    </table>
    </div>
  </div>
  </div>
<?php endif; ?>

  <!-- ============ 登録一覧 ============ -->
  <div class="pane" id="pane-list" style="display:none;">
  <div class="card">
    <h2>登録一覧</h2>
    <p class="note">このページを開いている間に登録・変更した行は<span style="background:var(--kin-soft);padding:0 8px;border-radius:4px;">この色</span>で表示されます（再読み込みすると消えます）。<br>
      退塾などは「無効にする」を使ってください（ログイン不可になるだけで学習記録は残り、いつでも「有効に戻す」で復活できます）。<br>
      「完全削除」は登録間違い・テストデータ専用です（統括のみ・学習記録ごと消え、元に戻せません）。</p>
    <!-- 講師のPW初期化で発行した仮パスワードの案内メールをここに表示（既存講師への送付用） -->
    <div id="list-mail" style="display:none;margin:12px 0;padding:12px;border:1.5px solid var(--ai);border-radius:8px;background:#fff;">
      <p id="list-mail-head" class="note" style="margin:0 0 8px;"></p>
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
        <button class="go" type="button" id="list-mail-copy" style="width:auto;padding:6px 14px;">メール文をコピー</button>
        <span id="list-mail-note" style="font-size:12px;color:var(--ink-soft);"></span>
      </div>
      <textarea id="list-mail-text" readonly style="height:300px;"></textarea>
    </div>
    <div class="tabs" style="margin-top:8px;">
      <button class="tab active" data-kind="students" type="button">生徒</button>
      <button class="tab" data-kind="guardians" type="button">保護者</button>
<?php if ($role === 'super_admin'): ?>
      <button class="tab" data-kind="teachers" type="button">講師</button>
<?php endif; ?>
    </div>

    <!-- 生徒一覧 + 名前変更 -->
    <div id="list-students">
<?php if (count($classrooms) > 1): ?>
      <div class="tabs" style="margin-top:10px;">
        <button class="tab active" data-cls="" type="button">全教室</button>
<?php foreach ($classrooms as $c): ?>
        <button class="tab" data-cls="<?= h($c['classroom_name']) ?>" type="button"><?= h($c['classroom_name']) ?></button>
<?php endforeach; ?>
      </div>
<?php endif; ?>
      <h3 style="margin:14px 0 6px;font-family:'Zen Maru Gothic',sans-serif;color:var(--ai,#2C5F8A);font-size:15px;">生徒情報の修正（氏名・学年・志望校）</h3>
      <p class="note">生徒コードを入力すると、現在の氏名・学年・志望校が自動で読み込まれます（下の一覧の「編集」ボタンでも読み込めます）。直して「保存」を押してください。志望校を外すときは「（未設定）」を選びます。</p>
      <form id="rename-form">
        <div class="row2">
          <label>生徒コード<input type="text" name="login_id" maxlength="6" inputmode="numeric"></label>
          <label>氏名<input type="text" name="student_name" maxlength="50"></label>
          <label>学年（例: es4, js1。空欄可）<input type="text" name="grade" maxlength="10" placeholder="js1"></label>
          <label>私立志望校
            <select name="target_private_id" data-school="private"><option value="">（未設定）</option></select>
          </label>
          <label>公立志望校
            <select name="target_public_id" data-school="public"><option value="">（未設定）</option></select>
          </label>
        </div>
        <button class="go" type="submit">保存</button>
      </form>
      <div class="msg" id="rename-msg"></div>
      <label style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;font-size:13px;font-weight:700;color:var(--ink,#33312B);cursor:pointer;white-space:nowrap;">
        <input type="checkbox" class="hide-test" checked style="width:auto;flex:none;margin:0;">テスト生（名前に「テスト」を含む）を非表示
      </label>
      <p style="margin:8px 0 4px;color:var(--ai,#2C5F8A);font-weight:700;font-size:13px;">列の見出し（生徒コード・教室・氏名など）をクリックすると、その項目で並び替えできます（もう一度クリックで昇順⇄降順、▲▼が今の並び順）。</p>
      <div class="list-count" id="students-count"></div>
      <div class="scroll">
      <table id="students-table">
        <thead><tr><th class="sortable" data-key="login_id">生徒コード</th><th class="sortable" data-key="classroom_name">教室</th><th class="sortable" data-key="grade">学年</th><th class="sortable" data-key="student_name">氏名</th><th class="sortable" data-key="target_private_name">私立志望</th><th class="sortable" data-key="target_public_name">公立志望</th><th class="sortable" data-key="is_active">状態</th><th class="sortable" data-key="created_at">登録日</th><th>操作</th></tr></thead>
        <tbody></tbody>
      </table>
      </div>
    </div>

    <!-- 保護者一覧 -->
    <div id="list-guardians" style="display:none;">
      <label style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;font-size:13px;font-weight:700;color:var(--ink,#33312B);cursor:pointer;white-space:nowrap;">
        <input type="checkbox" class="hide-test" checked style="width:auto;flex:none;margin:0;">テスト生（名前に「テスト」を含む）を非表示
      </label>
      <p style="margin:8px 0 4px;color:var(--ai,#2C5F8A);font-weight:700;font-size:13px;">列の見出し（ログインID・氏名など）をクリックすると、その項目で並び替えできます（もう一度クリックで昇順⇄降順、▲▼が今の並び順）。</p>
      <div class="list-count" id="guardians-count"></div>
      <div class="scroll">
      <table id="guardians-table">
        <thead><tr><th class="sortable" data-key="login_id">ログインID</th><th class="sortable" data-key="guardian_name">氏名</th><th class="sortable" data-key="children">お子さま</th><th class="sortable" data-key="is_active">状態</th><th class="sortable" data-key="created_at">登録日</th><th>操作</th></tr></thead>
        <tbody></tbody>
      </table>
      </div>
    </div>

<?php if ($role === 'super_admin'): ?>
    <!-- 講師一覧 + 講師情報の修正 -->
    <div id="list-teachers" style="display:none;">
      <h3 style="margin:14px 0 6px;font-family:'Zen Maru Gothic',sans-serif;color:var(--ai,#2C5F8A);font-size:15px;">講師情報の修正（氏名・役割・担当教室）</h3>
      <p class="note">下の一覧の「編集」ボタンで現在値を読み込みます。直して「保存」を押してください。役割を super_admin にすると担当教室は不要です（全教室扱い）。パスワードはここでは変更しません（「PW初期化」または本人のパスワード変更を使ってください）。</p>
      <form id="teacher-edit-form">
        <div class="row2">
          <label>ログインID<input type="text" name="login_id" readonly style="background:#F3F0E8;"></label>
          <label>氏名<input type="text" name="teacher_name" maxlength="50"></label>
          <label>役割
            <select name="role">
              <option value="teacher">teacher（閲覧のみ）</option>
              <option value="classroom_admin">classroom_admin（教室管理者・生徒登録可）</option>
              <option value="super_admin">super_admin（統括・全教室）</option>
            </select>
          </label>
        </div>
        <label>担当教室（super_admin 以外は1つ以上チェック。兼任は複数可）</label>
        <div class="chks">
<?php foreach ($classrooms as $c): ?>
          <label><input type="checkbox" name="classroom_ids" value="<?= (int)$c['classroom_id'] ?>"><?= h($c['classroom_name']) ?></label>
<?php endforeach; ?>
        </div>
        <button class="go" type="submit">保存</button>
      </form>
      <div class="msg" id="teacher-edit-msg"></div>
      <label style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;font-size:13px;font-weight:700;color:var(--ink,#33312B);cursor:pointer;white-space:nowrap;">
        <input type="checkbox" class="hide-test" checked style="width:auto;flex:none;margin:0;">テスト生（名前に「テスト」を含む）を非表示
      </label>
      <p style="margin:8px 0 4px;color:var(--ai,#2C5F8A);font-weight:700;font-size:13px;">列の見出し（ログインID・氏名・役割など）をクリックすると、その項目で並び替えできます（もう一度クリックで昇順⇄降順、▲▼が今の並び順）。</p>
      <div class="list-count" id="teachers-count"></div>
      <div class="scroll">
      <table id="teachers-table">
        <thead><tr><th class="sortable" data-key="login_id">ログインID</th><th class="sortable" data-key="teacher_name">氏名</th><th class="sortable" data-key="role">役割</th><th class="sortable" data-key="classroom_names">担当教室</th><th class="sortable" data-key="is_active">状態</th><th class="sortable" data-key="created_at">登録日</th><th>操作</th></tr></thead>
        <tbody></tbody>
      </table>
      </div>
    </div>
<?php endif; ?>
  </div>
  </div>

<?php endif; ?>

  <footer>中京個別指導学院 アカウント管理</footer>
</div>
<script>
document.getElementById('logout-btn').addEventListener('click', async () => {
  await fetch('/api/logout.php', { method: 'POST', credentials: 'same-origin' });
  location.reload();
});

const ERROR_TEXT = {
  invalid_login_id: 'ログインIDが不正です（50文字以内）',
  duplicate_login_id: 'そのログインIDは既に使われています',
  login_id_taken: 'そのログインIDは既に使われています（別のIDにしてください）',
  invalid_password: 'パスワードは8文字以上にしてください',
  invalid_teacher_name: '氏名が不正です',
  invalid_guardian_name: '氏名が不正です',
  invalid_student_name: '生徒氏名が不正です',
  invalid_role: '役割が不正です',
  classroom_ids_required: '担当教室を1つ以上選んでください',
  invalid_classroom_id: '教室の指定が不正です',
  forbidden_classroom: '担当外の教室です',
  invalid_grade: '学年が不正です（10文字以内）',
  invalid_pin: 'PINは4桁の数字にしてください',
  student_codes_required: '生徒コードを入力してください',
  student_not_found: 'その生徒コードの生徒が見つかりません',
  guardian_not_found: 'その保護者が見つかりません',
  rows_required: '登録する行がありません',
  too_many_rows: '一度に登録できるのは300行までです',
  already_has_guardian: 'その生徒にはすでに保護者が登録されています（下の子の追加は「兄弟・姉妹の追加」を使ってください）',
  guardian_key_required: '保護者ID または 生徒コードを入力してください',
  guardian_ambiguous: 'その生徒には複数の保護者が紐づいています。保護者ID（g〜）で指定してください',
  needs_move: 'その生徒は別の保護者アカウントに登録されています（付け替えの確認が必要です）',
  teacher_not_found: 'その講師が見つかりません',
  invalid_kind: '種別の指定が不正です',
  cannot_self: '自分自身を無効にすることはできません',
  forbidden: 'この操作の権限がありません',
  invalid_target_school: '志望校の指定が不正です',
  invalid_name: '学校名が不正です（80文字以内）',
  duplicate_name: 'その種別に同じ名前の学校が既にあります',
  not_found: '対象が見つかりません',
};

const MY_LOGIN_ID = <?= json_encode($me['login_id']) ?>;
const IS_SUPER = <?= json_encode($role === 'super_admin') ?>;
// 登録・変更系のボタンを出すか（teacher=閲覧のみ には出さない。APIも拒否する）
const CAN_MANAGE = <?= json_encode(in_array($role, ['super_admin', 'classroom_admin'], true)) ?>;

function errText(data, status) {
  if (data && data.error) {
    let t = ERROR_TEXT[data.error] || data.error;
    if (data.student_code) t += '（' + data.student_code + '）';
    if (data.guardian_login_id) t += '［登録済みの保護者: ' + data.guardian_login_id + '］';
    return t;
  }
  return '失敗しました（HTTP ' + status + '）';
}

async function post(url, body) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => null);
  return { res, data };
}

function showMsg(id, ok, text) {
  const el = document.getElementById(id);
  el.className = 'msg ' + (ok ? 'ok' : 'ng');
  el.textContent = text;
}

// ---- このページを開いている間に登録・変更したID（一覧で色違い表示する） ----
const recent = { students: new Set(), guardians: new Set(), teachers: new Set() };

// ---- 機能タブ切替 ----
const tabButtons = document.querySelectorAll('.tabs .tab[data-tab]');
tabButtons.forEach((btn) => {
  btn.addEventListener('click', () => {
    tabButtons.forEach((b) => b.classList.toggle('active', b === btn));
    document.querySelectorAll('.pane').forEach((p) => { p.style.display = 'none'; });
    document.getElementById('pane-' + btn.dataset.tab).style.display = '';
    if (btn.dataset.tab === 'list') loadList(currentKind);
    if (btn.dataset.tab === 'school') loadSchools();
  });
});

// ============ 志望校マスター ============
// 種別ごとの選択肢を覚えておき、全ての志望校 <select> に反映する
const SCHOOL_KIND_JA = { private: '私立', public: '公立' };
let schoolOptions = { private: [], public: [] };

// 有効な志望校を取得して、data-school 付きの全 <select> に反映（登録フォーム・修正フォーム）
async function loadTargetSchools() {
  try {
    const res = await fetch('/api/target_schools.php', { credentials: 'same-origin' });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data || !data.ok) return;
    schoolOptions = { private: [], public: [] };
    for (const s of data.schools) {
      if (schoolOptions[s.kind]) schoolOptions[s.kind].push(s);
    }
    document.querySelectorAll('select[data-school]').forEach((sel) => {
      const kind = sel.dataset.school;
      const cur = sel.value;
      sel.innerHTML = '<option value="">（未設定）</option>';
      for (const s of schoolOptions[kind] || []) {
        const opt = document.createElement('option');
        opt.value = s.target_school_id;
        opt.textContent = s.name;
        sel.appendChild(opt);
      }
      sel.value = cur;   // 反映前の選択を保つ（存在しなければ未設定に戻る）
    });
  } catch (err) { /* 取得失敗時はプルダウンは未設定のまま。黙って無視 */ }
}

// 管理タブ用: 無効も含めた一覧を取得して表を描く
async function loadSchools() {
  const tbody = document.querySelector('#school-table tbody');
  tbody.innerHTML = '<tr><td colspan="4" style="color:var(--ink-soft);">読み込み中...</td></tr>';
  try {
    const res = await fetch('/api/target_schools.php?all=1', { credentials: 'same-origin' });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data || !data.ok) {
      tbody.innerHTML = '<tr><td colspan="4" class="ng-cell">一覧の取得に失敗しました</td></tr>';
      return;
    }
    tbody.innerHTML = '';
    if (data.schools.length === 0) {
      tbody.innerHTML = '<tr><td colspan="4" style="color:var(--ink-soft);">まだ登録がありません</td></tr>';
      return;
    }
    for (const s of data.schools) {
      const tr = document.createElement('tr');
      if (!s.is_active) tr.classList.add('inactive');
      const nameTd = document.createElement('td'); nameTd.textContent = s.name;
      const kindTd = document.createElement('td'); kindTd.textContent = SCHOOL_KIND_JA[s.kind] || s.kind;
      const stateTd = document.createElement('td');
      stateTd.innerHTML = s.is_active ? '<span class="state-on">✓ 有効</span>' : '<span class="state-off">停止中</span>';
      const opTd = document.createElement('td');
      const ren = document.createElement('button');
      ren.type = 'button';
      ren.className = 'mini on';
      ren.style.marginRight = '6px';
      ren.textContent = '名前変更';
      ren.addEventListener('click', () => renameSchool(s.target_school_id, s.name));
      opTd.appendChild(ren);
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mini ' + (s.is_active ? 'off' : 'on');
      btn.textContent = s.is_active ? '無効にする' : '有効に戻す';
      btn.addEventListener('click', () => toggleSchool(s.target_school_id, s.name, !s.is_active));
      opTd.appendChild(btn);
      tr.append(nameTd, kindTd, stateTd, opTd);
      tbody.appendChild(tr);
    }
  } catch (err) {
    tbody.innerHTML = '<tr><td colspan="4" class="ng-cell">通信エラーが発生しました</td></tr>';
  }
}

async function renameSchool(id, currentName) {
  const next = prompt('新しい学校名を入力してください（志望している生徒のひもづけはそのまま引き継がれます）', currentName);
  if (next === null) return;                       // キャンセル
  const name = next.trim();
  if (name === '' || name === currentName) return; // 変更なしは何もしない
  try {
    const { res, data } = await post('/api/save_target_school.php', {
      action: 'rename', target_school_id: id, name: name,
    });
    if (res.ok && data && data.ok) {
      showMsg('school-msg', true, '「' + currentName + '」→「' + name + '」に変更しました');
      loadSchools();
      loadTargetSchools();   // 登録・修正フォームのプルダウンにも即反映
    } else {
      showMsg('school-msg', false, errText(data, res.status));
    }
  } catch (err) { showMsg('school-msg', false, '通信エラー: ' + err); }
}

async function toggleSchool(id, name, toActive) {
  const msg = toActive
    ? '志望校「' + name + '」を有効に戻しますか？'
    : '志望校「' + name + '」を無効にしますか？\n（プルダウンとランキングから隠れます。生徒の記録は残ります）';
  if (!confirm(msg)) return;
  try {
    const { res, data } = await post('/api/save_target_school.php', {
      action: 'set_active', target_school_id: id, active: toActive,
    });
    if (res.ok && data && data.ok) {
      loadSchools();
      loadTargetSchools();   // プルダウンにも即反映
    } else {
      alert(errText(data, res.status));
    }
  } catch (err) { alert('通信エラー: ' + err); }
}

const schoolForm = document.getElementById('school-form');
if (schoolForm) {
  schoolForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    try {
      const { res, data } = await post('/api/save_target_school.php', {
        action: 'add', name: f.name.value.trim(), kind: f.kind.value,
      });
      if (res.ok && data && data.ok) {
        showMsg('school-msg', true, data.restored ? '既に登録済みの学校を有効に戻しました' : '追加しました');
        f.name.value = '';
        loadSchools();
        loadTargetSchools();
      } else {
        showMsg('school-msg', false, errText(data, res.status));
      }
    } catch (err) { showMsg('school-msg', false, '通信エラー: ' + err); }
  });
}

// ---- 一覧の種類切替 ----
let currentKind = 'students';
// テスト生非表示チェックボックス（既定ON・各一覧に1つ）: 全一覧で状態を揃えて描き直す
document.querySelectorAll('.hide-test').forEach((cb) => {
  cb.addEventListener('change', () => {
    document.querySelectorAll('.hide-test').forEach((o) => { o.checked = cb.checked; });
    renderRows(currentKind);
  });
});
const kindButtons = document.querySelectorAll('.tab[data-kind]');
kindButtons.forEach((btn) => {
  btn.addEventListener('click', () => {
    currentKind = btn.dataset.kind;
    kindButtons.forEach((b) => b.classList.toggle('active', b === btn));
    document.querySelectorAll('#list-students, #list-guardians, #list-teachers').forEach((d) => { d.style.display = 'none'; });
    document.getElementById('list-' + currentKind).style.display = '';
    loadList(currentKind);
  });
});

// 種別ごとの日本語名（確認ダイアログ・完了メッセージ用）
const KIND_JA = { students: '生徒', guardians: '保護者', teachers: '講師' };
const KIND_API = { students: 'student', guardians: 'guardian', teachers: 'teacher' };

function fillRow(tr, values, isNew, isActive) {
  for (const v of values) {
    const td = document.createElement('td');
    td.textContent = v;
    tr.appendChild(td);
  }
  // 状態セル（values側は空文字で渡し、ここで色付きの表示を入れる）
  const stateTd = tr.children[values.length - 2];
  stateTd.innerHTML = isActive ? '<span class="state-on">✓ 有効</span>' : '<span class="state-off">停止中</span>';
  if (isNew) {
    tr.classList.add('new-row');
    const mark = document.createElement('span');
    mark.className = 'newmark';
    mark.textContent = 'NEW';
    tr.children[0].appendChild(mark);
  }
  if (!isActive) tr.classList.add('inactive');
}

// 操作セル: 有効なら「無効にする」、停止中なら「有効に戻す」ボタン
function actionCell(tr, kind, loginId, name, isActive, row) {
  const td = document.createElement('td');
  // 生徒は「編集」で修正フォームに現在値を読み込む（氏名・学年・志望校）
  if (kind === 'students') {
    const edit = document.createElement('button');
    edit.type = 'button';
    edit.className = 'mini on';
    edit.textContent = '編集';
    edit.style.marginRight = '6px';
    edit.addEventListener('click', () => fillEditForm(row));
    td.appendChild(edit);
  }
  // 講師は「編集」で修正フォームに現在値を読み込む（氏名・役割・担当教室）。統括のみ・自分以外
  if (kind === 'teachers' && IS_SUPER && loginId !== MY_LOGIN_ID) {
    const edit = document.createElement('button');
    edit.type = 'button';
    edit.className = 'mini on';
    edit.textContent = '編集';
    edit.style.marginRight = '6px';
    edit.addEventListener('click', () => fillTeacherEditForm(row));
    td.appendChild(edit);
  }
  // 保護者は「兄弟追加」で保護者登録タブの追加フォームに保護者IDを流し込む。
  // 「PW初期化」は保護者がパスワードを忘れた時用（仮パスワード再発行→本人が再設定）
  if (kind === 'guardians' && CAN_MANAGE) {
    const sib = document.createElement('button');
    sib.type = 'button';
    sib.className = 'mini on';
    sib.style.marginRight = '6px';
    sib.textContent = '兄弟追加';
    sib.addEventListener('click', () => {
      document.querySelector('.tabs .tab[data-tab="guardian"]').click();
      const form = document.getElementById('sibling-form');
      form.guardian_key.value = loginId;
      form.student_codes.value = '';
      form.scrollIntoView({ behavior: 'smooth', block: 'center' });
      form.student_codes.focus();
    });
    td.appendChild(sib);
    const rst = document.createElement('button');
    rst.type = 'button';
    rst.className = 'mini on';
    rst.style.marginRight = '6px';
    rst.textContent = 'PW初期化';
    rst.addEventListener('click', () => resetGuardianPassword(loginId, name));
    td.appendChild(rst);
  }
  // 自分自身の講師アカウントにはボタンを出さない(APIも拒否する)
  if (!(kind === 'teachers' && loginId === MY_LOGIN_ID)) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mini ' + (isActive ? 'off' : 'on');
    btn.textContent = isActive ? '無効にする' : '有効に戻す';
    btn.addEventListener('click', () => toggleActive(kind, loginId, name, !isActive));
    td.appendChild(btn);
  }
  // 完全削除は統括のみ（登録間違い・テストデータの掃除用）
  if (kind === 'students' && IS_SUPER) {
    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'mini del';
    del.textContent = '完全削除';
    del.addEventListener('click', () => deleteStudent(loginId, name));
    td.appendChild(del);
  }
  // 保護者の完全削除（生徒・学習記録には影響しない。同じ代表の子で登録し直せるようになる）
  if (kind === 'guardians' && IS_SUPER) {
    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'mini del';
    del.textContent = '完全削除';
    del.addEventListener('click', () => deleteGuardian(loginId, name));
    td.appendChild(del);
  }
  // パスワード初期化は講師のみ・統括のみ（自分は password.php から変更する）
  if (kind === 'teachers' && IS_SUPER && loginId !== MY_LOGIN_ID) {
    const rst = document.createElement('button');
    rst.type = 'button';
    rst.className = 'mini on';
    rst.style.marginLeft = '6px';
    rst.textContent = 'PW初期化';
    rst.addEventListener('click', () => resetTeacherPassword(loginId, name));
    td.appendChild(rst);
  }
  // 講師の完全削除（統括のみ・自分以外。生徒・学習記録は残り、登録者欄が空欄になるだけ）
  if (kind === 'teachers' && IS_SUPER && loginId !== MY_LOGIN_ID) {
    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'mini del';
    del.textContent = '完全削除';
    del.addEventListener('click', () => deleteTeacher(loginId, name));
    td.appendChild(del);
  }
  tr.appendChild(td);
}

async function resetGuardianPassword(loginId, name) {
  const message = '保護者「' + name + '（' + loginId + '）」のパスワードを初期化しますか？\n\n'
    + '・新しい仮パスワードが自動生成されます\n'
    + '・保護者は次回ログイン時に新しいパスワード（8〜15文字の半角英数）を設定し直します';
  if (!confirm(message)) return;
  try {
    const { res, data } = await post('/api/reset_guardian_password.php', { login_id: loginId });
    if (res.ok && data && data.ok) {
      recent.guardians.add(loginId);
      prompt('初期化しました。この仮パスワードを ' + data.guardian_name + ' にお伝えください\n（この画面を閉じると二度と表示されません）', data.temp_password);
      loadList('guardians');
    } else {
      alert(errText(data, res.status));
    }
  } catch (err) {
    alert('通信エラー: ' + err);
  }
}

async function resetTeacherPassword(loginId, name) {
  const message = '講師「' + name + '（' + loginId + '）」のパスワードを初期化しますか？\n\n'
    + '・新しい仮パスワードが自動生成されます\n'
    + '・本人は次回ログイン時にパスワードの変更を求められます';
  if (!confirm(message)) return;
  try {
    const { res, data } = await post('/api/reset_teacher_password.php', { login_id: loginId });
    if (res.ok && data && data.ok) {
      recent.teachers.add(loginId);
      // 仮パスワードでログイン→本人がパスワード設定、を伝える案内メールを表示（仮パスワードはこの画面でしか確認できない）
      showListMail(
        buildTeacherGuideText(data.teacher_name, loginId, data.temp_password, { reset: true }),
        '仮パスワードを再発行しました。下のメール文をコピーして ' + data.teacher_name + ' 先生に送ってください（この画面でしか確認できません）');
      loadList('teachers');
    } else {
      alert(errText(data, res.status));
    }
  } catch (err) {
    alert('通信エラー: ' + err);
  }
}

// 登録一覧の上部にある案内メール欄に文面を出す（講師PW初期化で使用）
function showListMail(text, headText) {
  document.getElementById('list-mail-head').textContent = headText || '';
  document.getElementById('list-mail-text').value = text;
  document.getElementById('list-mail-note').textContent = '';
  const box = document.getElementById('list-mail');
  box.style.display = '';
  box.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
document.getElementById('list-mail-copy').addEventListener('click', () =>
  copyWithNote(document.getElementById('list-mail-text').value, 'list-mail-note'));

async function deleteTeacher(loginId, name) {
  const message = '講師「' + name + '（' + loginId + '）」を完全に削除しますか？\n\n'
    + '・この講師IDではログインできなくなり、元に戻せません\n'
    + '・担当教室の割り当ても一緒に消えます\n'
    + '・生徒・学習記録・確認テストの結果は消えません（登録者・記録者の欄が空欄になるだけ）\n\n'
    + '※登録間違い・テストデータの掃除用です。退任など通常の停止は「無効にする」を使ってください';
  if (!confirm(message)) return;
  const typed = prompt('確認のため、削除する講師のログインIDを入力してください（' + loginId + '）');
  if (typed === null) return;
  if (typed.trim() !== loginId) {
    alert('ログインIDが一致しないため、削除を中止しました');
    return;
  }
  try {
    const { res, data } = await post('/api/delete_teacher.php', { login_id: loginId });
    if (res.ok && data && data.ok) {
      alert('削除しました: ' + data.teacher_name + ' 先生（' + data.login_id + '）');
      loadList('teachers');
    } else {
      alert(errText(data, res.status));
    }
  } catch (err) {
    alert('通信エラー: ' + err);
  }
}

async function deleteGuardian(loginId, name) {
  const message = '保護者「' + name + '（' + loginId + '）」を完全に削除しますか？\n\n'
    + '・お子さま（生徒）と学習記録はそのまま残ります\n'
    + '・この保護者IDではログインできなくなり、元に戻せません\n'
    + '・同じ代表の子で保護者を登録し直せるようになります\n\n'
    + '※登録間違い・テストデータの掃除用です。退塾など通常の停止は「無効にする」を使ってください';
  if (!confirm(message)) return;
  try {
    const { res, data } = await post('/api/delete_guardian.php', { login_id: loginId });
    if (res.ok && data && data.ok) {
      alert('削除しました: ' + data.guardian_name + '（' + data.login_id + '）');
      loadList('guardians');
    } else {
      alert(errText(data, res.status));
    }
  } catch (err) {
    alert('通信エラー: ' + err);
  }
}

async function deleteStudent(loginId, name) {
  const message = '生徒「' + name + '（' + loginId + '）」を完全に削除しますか？\n\n'
    + '・学習記録（解答・学習時間・XP・解き直し）もすべて消えます\n'
    + '・この操作は元に戻せません\n'
    + '・兄弟がいる保護者は残ります（この生徒だけの保護者は一緒に削除）\n\n'
    + '※退塾など通常の停止は「無効にする」を使ってください';
  if (!confirm(message)) return;
  const typed = prompt('確認のため、削除する生徒コードを入力してください（' + loginId + '）');
  if (typed === null) return;
  if (typed.trim() !== loginId) {
    alert('生徒コードが一致しないため、削除を中止しました');
    return;
  }
  try {
    const { res, data } = await post('/api/delete_student.php', { login_id: loginId });
    if (res.ok && data && data.ok) {
      alert('削除しました。\n生徒: ' + data.student_name + '（' + data.login_id + '）\n'
        + '消えた解答記録: ' + data.deleted_answers + '件'
        + (data.removed_guardians > 0 ? '\n一緒に削除した保護者: ' + data.removed_guardians + '人' : ''));
      loadList('students');
    } else {
      alert(errText(data, res.status));
    }
  } catch (err) {
    alert('通信エラー: ' + err);
  }
}

async function toggleActive(kind, loginId, name, toActive) {
  const who = KIND_JA[kind] + '「' + name + '（' + loginId + '）」';
  const message = toActive
    ? who + ' を有効に戻しますか？\nまたログインできるようになります。'
    : who + ' を無効にしますか？\n\n・ログインできなくなります\n・学習記録は消えません\n・「有効に戻す」でいつでも元に戻せます';
  if (!confirm(message)) return;
  try {
    const { res, data } = await post('/api/set_active.php', {
      kind: KIND_API[kind],
      login_id: loginId,
      active: toActive,
    });
    if (res.ok && data && data.ok) {
      recent[kind].add(loginId);
      loadList(kind);
    } else {
      alert(errText(data, res.status));
    }
  } catch (err) {
    alert('通信エラー: ' + err);
  }
}

// 生徒一覧の教室絞り込み（複数教室の権限がある講師のみタブが表示される）
let classroomFilter = '';
const clsButtons = document.querySelectorAll('.tab[data-cls]');
clsButtons.forEach((btn) => {
  btn.addEventListener('click', () => {
    classroomFilter = btn.dataset.cls;
    clsButtons.forEach((b) => b.classList.toggle('active', b === btn));
    renderRows('students');
  });
});

// 取得済みデータを覚えておき、教室タブの切替は再取得せずに描き直す
const listCache = { students: null, guardians: null, teachers: null };

// 列見出しクリックでの並び替え状態（kindごと）
const sortState = { students: {key: null, dir: 1}, guardians: {key: null, dir: 1}, teachers: {key: null, dir: 1} };
// 数値なら数値順、それ以外は日本語ロケールで文字列比較
function cmpVal(a, b) {
  if (a == null) a = '';
  if (b == null) b = '';
  const na = Number(a), nb = Number(b);
  if (a !== '' && b !== '' && !isNaN(na) && !isNaN(nb)) return na - nb;
  return String(a).localeCompare(String(b), 'ja');
}
['students', 'guardians', 'teachers'].forEach((kind) => {
  const table = document.getElementById(kind + '-table');
  if (!table) return;
  table.querySelectorAll('th.sortable').forEach((th) => {
    th.addEventListener('click', () => {
      const s = sortState[kind];
      if (s.key === th.dataset.key) { s.dir = -s.dir; } else { s.key = th.dataset.key; s.dir = 1; }
      table.querySelectorAll('th .arw').forEach((el) => el.remove());
      const arw = document.createElement('span');
      arw.className = 'arw';
      arw.textContent = s.dir === 1 ? '▲' : '▼';
      th.appendChild(arw);
      renderRows(kind);
    });
  });
});

async function loadList(kind) {
  const urls = {
    students: '/api/list_students.php',
    guardians: '/api/list_guardians.php',
    teachers: '/api/list_teachers.php',
  };
  const tbody = document.querySelector('#' + kind + '-table tbody');
  const cols = document.querySelectorAll('#' + kind + '-table thead th').length;
  tbody.innerHTML = '<tr><td colspan="' + cols + '" style="color:var(--ink-soft);">読み込み中...</td></tr>';
  try {
    const res = await fetch(urls[kind], { credentials: 'same-origin' });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data || !data.ok) {
      tbody.innerHTML = '<tr><td colspan="' + cols + '" class="ng-cell">一覧の取得に失敗しました</td></tr>';
      return;
    }
    listCache[kind] = data[kind];
    renderRows(kind);
  } catch (err) {
    tbody.innerHTML = '<tr><td colspan="' + cols + '" class="ng-cell">通信エラーが発生しました</td></tr>';
  }
}

function renderRows(kind) {
  const tbody = document.querySelector('#' + kind + '-table tbody');
  let rows = listCache[kind] || [];
  if (kind === 'students' && classroomFilter !== '') {
    rows = rows.filter((r) => r.classroom_name === classroomFilter);
  }
  // テスト生（名前に「テスト」を含む）非表示（teacher.php / ranking.php と同じ基準）
  const hideTest = document.querySelector('#list-' + kind + ' .hide-test');
  if (hideTest && hideTest.checked) {
    const nameKey = kind === 'students' ? 'student_name' : (kind === 'guardians' ? 'guardian_name' : 'teacher_name');
    rows = rows.filter((r) => !String(r[nameKey] || '').includes('テスト'));
  }
  const sort = sortState[kind];
  if (sort.key) {
    rows = rows.slice().sort((a, b) => cmpVal(a[sort.key], b[sort.key]) * sort.dir);
  }
  // 表示件数（絞り込み後）。絞り込みで減っているときは全件数も添える
  const countEl = document.getElementById(kind + '-count');
  if (countEl) {
    const total = (listCache[kind] || []).length;
    countEl.textContent = rows.length === total
      ? '表示件数：' + rows.length + '件'
      : '表示件数：' + rows.length + '件（全' + total + '件）';
  }
  tbody.innerHTML = '';
  if (rows.length === 0) {
    const cols = document.querySelectorAll('#' + kind + '-table thead th').length;
    const text = (kind === 'students' && classroomFilter !== '')
      ? 'この教室には生徒がいません' : 'まだ登録がありません';
    tbody.innerHTML = '<tr><td colspan="' + cols + '" style="color:var(--ink-soft);">' + text + '</td></tr>';
    return;
  }
  for (const r of rows) {
    const tr = document.createElement('tr');
    let name;
    if (kind === 'students') {
      name = r.student_name;
      fillRow(tr, [r.login_id, r.classroom_name, r.grade ?? '', r.student_name,
        r.target_private_name ?? '', r.target_public_name ?? '', '', r.created_at],
        recent.students.has(r.login_id), r.is_active);
    } else if (kind === 'guardians') {
      name = r.guardian_name;
      fillRow(tr, [r.login_id, r.guardian_name, r.children, '', r.created_at],
        recent.guardians.has(r.login_id), r.is_active);
    } else {
      name = r.teacher_name;
      fillRow(tr, [r.login_id, r.teacher_name, r.role, r.classroom_names, '', r.created_at],
        recent.teachers.has(r.login_id), r.is_active);
    }
    actionCell(tr, kind, r.login_id, name, r.is_active, r);
    tbody.appendChild(tr);
  }
  // 色違いの行(今回の登録・変更)を上に見せる（並び替え中は順序を尊重してそのまま）
  if (!sort.key) {
    tbody.querySelectorAll('tr.new-row').forEach((tr) => tbody.prepend(tr));
  }
}

// ---- ご家庭向け案内文の生成（登録結果から作る。保護者の仮パスワードはこの画面でしか確認できない） ----
const GUIDE_SEP = '\n\n──────── ✂ ────────\n\n';
function buildGuideText(headerName, studentBlock, guardianId, guardianPassword) {
  return '【中京個別指導学院 学習記録サイトのご案内】\n'
    + headerName + '\n'
    + '\n■ お子さま用（学習ツール・マイページ）\n'
    + studentBlock
    + '\n■ 保護者用（保護者ページ）\n'
    + '　保護者ID：' + guardianId + '\n'
    + '　仮パスワード：' + guardianPassword + '\n'
    + '　※初回ログイン時に、ご自身の新しいパスワード（8〜15文字の半角英数）を設定していただきます\n'
    + '\n▼ 学習ツール\nhttps://chukyokobetsu.com/learning/\n'
    + '▼ がんばりの記録（生徒マイページ）\nhttps://chukyokobetsu.com/mypage.php\n'
    + '▼ 保護者ページ（お子さまのがんばりを見られます）\nhttps://chukyokobetsu.com/guardian.php\n'
    + '\n※ログインして学習すると記録が残り、マイページ・保護者ページでがんばりを確認できます。\n'
    + '※ID・パスワードはご家庭で大切に保管してください。';
}
async function copyWithNote(text, noteId) {
  const note = document.getElementById(noteId);
  try {
    await navigator.clipboard.writeText(text);
    note.textContent = 'コピーしました';
  } catch (e) {
    note.textContent = 'コピーに失敗しました。手動で選択してコピーしてください';
  }
}

// ---- 生徒登録 ----
document.getElementById('student-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  try {
    const { res, data } = await post('/api/register_student.php', {
      classroom_id: Number(f.classroom_id.value),
      student_name: f.student_name.value.trim(),
      grade: f.grade.value.trim() || null,
      pin: f.pin.value,
      target_private_id: f.target_private_id.value ? Number(f.target_private_id.value) : null,
      target_public_id: f.target_public_id.value ? Number(f.target_public_id.value) : null,
    });
    if (res.ok && data && data.ok) {
      recent.students.add(data.login_id);
      recent.guardians.add(data.guardian_login_id);
      showMsg('student-msg', true, '登録しました。生徒コード: ' + data.login_id + ' ／ 保護者ID: ' + data.guardian_login_id + '。下の案内文をコピーしてご家庭に渡してください（保護者の仮パスワードはこの画面でしか確認できません）');
      // 案内文は入力欄を消す前に組み立てる（生徒PINは手元の入力値が唯一の源泉）
      document.getElementById('student-guide-text').value = buildGuideText(
        f.student_name.value.trim() + ' さん・保護者様',
        '　生徒コード：' + data.login_id + '\n　PIN：' + f.pin.value + '\n',
        data.guardian_login_id, data.guardian_password);
      document.getElementById('student-guide-note').textContent = '';
      document.getElementById('student-guide').style.display = '';
      f.student_name.value = ''; f.pin.value = '';
      f.target_private_id.value = ''; f.target_public_id.value = '';
    } else {
      showMsg('student-msg', false, errText(data, res.status));
    }
  } catch (err) { showMsg('student-msg', false, '通信エラー: ' + err); }
});

document.getElementById('student-guide-copy').addEventListener('click', () =>
  copyWithNote(document.getElementById('student-guide-text').value, 'student-guide-note'));

// ---- 生徒一括登録 ----
document.getElementById('csv-file').addEventListener('change', (e) => {
  const file = e.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = () => {
    document.querySelector('#bulk-form textarea[name="rows"]').value = reader.result;
  };
  reader.readAsText(file, 'UTF-8');
});

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

let bulkOkRows = [];   // 登録に成功した生徒（案内文の一括生成用）
document.getElementById('bulk-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const lines = e.target.rows.value.split('\n').map(l => l.trim()).filter(l => l !== '');
  const table = document.getElementById('bulk-table');
  const tbody = table.querySelector('tbody');
  tbody.innerHTML = '';
  table.style.display = '';
  bulkOkRows = [];
  document.getElementById('bulk-guide-wrap').style.display = 'none';
  document.getElementById('bulk-guide-text').style.display = 'none';
  document.getElementById('bulk-guide-copy').style.display = 'none';
  document.getElementById('bulk-guide-note').textContent = '';

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const row = document.createElement('tr');
    row.innerHTML = `<td>${i + 1}</td><td></td><td>実行中...</td><td></td>`;
    row.children[1].textContent = line;
    tbody.appendChild(row);

    // Excelのセルコピーはタブ区切りになるので、タブがあれば優先的にそちらで分割する
    const parts = (line.includes('\t') ? line.split('\t') : line.split(','));
    if (parts.length !== 4) {
      row.children[2].innerHTML = '<span class="ng-cell">形式エラー</span>';
      row.children[3].textContent = '教室ID,生徒名,学年,PINの4項目で区切ってください';
      continue;
    }
    const [classroomId, studentName, grade, pin] = parts.map(s => s.trim());
    try {
      const { res, data } = await post('/api/register_student.php', {
        classroom_id: Number(classroomId),
        student_name: studentName,
        grade: grade || null,
        pin: pin,
      });
      if (res.ok && data && data.ok) {
        recent.students.add(data.login_id);
        recent.guardians.add(data.guardian_login_id);
        row.children[2].innerHTML = '<span class="ok-cell">OK</span>';
        row.children[3].textContent = data.login_id + ' ／ 保護者: ' + data.guardian_login_id + '（仮PW ' + data.guardian_password + '）';
        bulkOkRows.push({
          student_name: studentName,
          login_id: data.login_id,
          pin: pin,
          guardian_login_id: data.guardian_login_id,
          guardian_password: data.guardian_password,
        });
      } else {
        row.children[2].innerHTML = '<span class="ng-cell">失敗</span>';
        row.children[3].textContent = errText(data, res.status);
      }
    } catch (err) {
      row.children[2].innerHTML = '<span class="ng-cell">通信エラー</span>';
      row.children[3].textContent = String(err);
    }
    await sleep(300);
  }
  if (bulkOkRows.length > 0) {
    document.getElementById('bulk-guide-wrap').style.display = '';
  }
});

document.getElementById('bulk-guide-btn').addEventListener('click', () => {
  const text = bulkOkRows.map(r => buildGuideText(
    r.student_name + ' さん・保護者様',
    '　生徒コード：' + r.login_id + '\n　PIN：' + r.pin + '\n',
    r.guardian_login_id, r.guardian_password)).join(GUIDE_SEP);
  const ta = document.getElementById('bulk-guide-text');
  ta.value = text;
  ta.style.display = '';
  document.getElementById('bulk-guide-copy').style.display = '';
  document.getElementById('bulk-guide-note').textContent = bulkOkRows.length + '人分の案内文を生成しました';
});
document.getElementById('bulk-guide-copy').addEventListener('click', () =>
  copyWithNote(document.getElementById('bulk-guide-text').value, 'bulk-guide-note'));

// ---- 保護者登録 ----
document.getElementById('guardian-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  const codes = f.student_codes.value.split(/[,、\s]+/).map(s => s.trim()).filter(s => s !== '');
  try {
    const { res, data } = await post('/api/register_guardian.php', {
      student_codes: codes,
    });
    if (res.ok && data && data.ok) {
      recent.guardians.add(data.login_id);
      showMsg('guardian-msg', true, '登録しました。保護者ID: ' + data.login_id + '（お子さま' + data.linked + '人と紐づけ済み）。下の案内文をコピーしてご家庭に渡してください（仮パスワードはこの画面でしか確認できません）');
      document.getElementById('guardian-guide-text').value = buildGuideText(
        data.guardian_name,
        '　お子さま：' + data.children + '\n　生徒コード・PIN：お子さまがお使いのもの\n',
        data.login_id, data.temp_password);
      document.getElementById('guardian-guide-note').textContent = '';
      document.getElementById('guardian-guide').style.display = '';
      f.reset();
    } else {
      showMsg('guardian-msg', false, errText(data, res.status));
    }
  } catch (err) { showMsg('guardian-msg', false, '通信エラー: ' + err); }
});

document.getElementById('guardian-guide-copy').addEventListener('click', () =>
  copyWithNote(document.getElementById('guardian-guide-text').value, 'guardian-guide-note'));

// ---- 保護者一括登録 ----
// 各行: 6桁の数字トークン=生徒コード（兄弟は同じ行）。それ以外の語は無視する
// （Excelから氏名列ごと貼り付けられても通るように）。
// 表示名はサーバー側で「代表の子の生徒名＋保護者様」を自動設定。
// PINはサーバー側で自動生成され、結果一覧にだけ表示される（再表示不可→コピーして配布）
let gbulkRows = [];
document.getElementById('gbulk-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const lines = e.target.rows.value.split('\n').map(l => l.trim()).filter(l => l !== '');
  if (lines.length === 0) { showMsg('gbulk-msg', false, '貼り付け欄が空です'); return; }
  const rows = lines.map((line) => {
    const tokens = line.split(/[\t,、\s]+/).filter(Boolean);
    return { student_codes: tokens.filter(t => /^\d{6}$/.test(t)) };
  });
  const btn = e.target.querySelector('button');
  btn.disabled = true;
  showMsg('gbulk-msg', true, '登録中...');
  try {
    const { res, data } = await post('/api/bulk_register_guardians.php', { rows });
    if (res.ok && data && data.ok) {
      gbulkRows = data.results || [];
      const tbody = document.getElementById('gbulk-tbody');
      tbody.innerHTML = '';
      gbulkRows.forEach((r, i) => {
        const tr = document.createElement('tr');
        const td = (v) => { const c = document.createElement('td'); c.textContent = v; return c; };
        tr.appendChild(td(String(i + 1)));
        const st = document.createElement('td');
        st.innerHTML = r.ok ? '<span class="ok-cell">OK</span>' : '<span class="ng-cell">失敗</span>';
        tr.appendChild(st);
        if (r.ok) {
          recent.guardians.add(r.login_id);
          const pin = td(r.temp_password);
          pin.style.fontFamily = 'monospace';
          pin.style.fontWeight = '700';
          tr.appendChild(td(r.classroom_name));
          tr.appendChild(td(r.login_id));
          tr.appendChild(pin);
          tr.appendChild(td(r.guardian_name));
          tr.appendChild(td(r.children));
        } else {
          const err = td(((r.codes || []).join(' ') + '： ' + errText(r, 0)).trim());
          err.colSpan = 5;
          err.classList.add('ng-cell');
          tr.appendChild(err);
        }
        tbody.appendChild(tr);
      });
      document.getElementById('gbulk-result').style.display = '';
      document.getElementById('gbulk-copy-note').textContent = '';
      const failed = gbulkRows.length - data.registered;
      showMsg('gbulk-msg', failed === 0,
        data.registered + '家庭を登録しました' + (failed > 0 ? '（' + failed + '行は失敗。下の一覧を確認してください）' : '。結果をコピーして各教室に配布してください（この画面でしか確認できません）'));
    } else {
      showMsg('gbulk-msg', false, errText(data, res.status));
    }
  } catch (err) {
    showMsg('gbulk-msg', false, '通信エラー: ' + err);
  } finally {
    btn.disabled = false;
  }
});

document.getElementById('gbulk-copy-btn').addEventListener('click', async () => {
  // Excel/スプレッドシートにそのまま貼れるタブ区切り（ヘッダー付き・成功行のみ）
  const tsv = ['教室\t保護者ID\t仮パスワード\t表示名\tお子さま']
    .concat(gbulkRows.filter(r => r.ok).map(r =>
      [r.classroom_name, r.login_id, r.temp_password, r.guardian_name, r.children].join('\t')))
    .join('\n');
  const note = document.getElementById('gbulk-copy-note');
  try {
    await navigator.clipboard.writeText(tsv);
    note.textContent = 'コピーしました（Excel等に貼り付けできます）';
  } catch (e) {
    note.textContent = 'コピーに失敗しました。手動で選択してコピーしてください';
  }
});

// 既存生徒への保護者一括登録は生徒PINを知らないため、案内文の生徒欄は「お使いのもの」とする
document.getElementById('gbulk-guide-btn').addEventListener('click', () => {
  const okRows = gbulkRows.filter(r => r.ok);
  if (okRows.length === 0) {
    document.getElementById('gbulk-copy-note').textContent = '生成できる登録結果がありません';
    return;
  }
  const text = okRows.map(r => buildGuideText(
    r.guardian_name,
    '　お子さま：' + r.children + '\n　生徒コード・PIN：お子さまがお使いのもの\n',
    r.login_id, r.temp_password)).join(GUIDE_SEP);
  const ta = document.getElementById('gbulk-guide-text');
  ta.value = text;
  ta.style.display = '';
  document.getElementById('gbulk-guide-copy').style.display = '';
  document.getElementById('gbulk-copy-note').textContent = okRows.length + '家庭分の案内文を生成しました';
});
document.getElementById('gbulk-guide-copy').addEventListener('click', () =>
  copyWithNote(document.getElementById('gbulk-guide-text').value, 'gbulk-copy-note'));

// ---- 兄弟・姉妹の追加（既存保護者への後付け紐づけ） ----
// 追加する子が別の保護者アカウントに紐づいている（＝別々に登録されていた兄弟）場合は
// needs_move が返るので、確認ダイアログを出してから move=true で付け替えを再送する
document.getElementById('sibling-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  const codes = f.student_codes.value.split(/[,、\s]+/).map(s => s.trim()).filter(s => s !== '');
  try {
    let { res, data } = await post('/api/add_guardian_student.php', {
      guardian_key: f.guardian_key.value.trim(),
      student_codes: codes,
    });
    if (!res.ok && data && data.error === 'needs_move') {
      const lines = (data.conflicts || []).map(c =>
        '・' + c.student_code + ' ' + c.student_name + '（現在: ' + c.guardians.join('、') + '）').join('\n');
      const msg = '以下のお子さまは、すでに別の保護者アカウントに登録されています。\n\n' + lines
        + '\n\nこの保護者に付け替えますか？\n（元のアカウントは、お子さまがいなくなった場合は自動で無効化されます）';
      if (!confirm(msg)) { showMsg('sibling-msg', false, '付け替えを中止しました'); return; }
      ({ res, data } = await post('/api/add_guardian_student.php', {
        guardian_key: f.guardian_key.value.trim(),
        student_codes: codes,
        move: true,
      }));
    }
    if (res.ok && data && data.ok) {
      recent.guardians.add(data.login_id);
      let msg = data.guardian_name + '（' + data.login_id + '）';
      if (data.added.length > 0) {
        msg += 'に追加しました: ' + data.added.join('、');
      } else {
        msg += 'への追加はありませんでした';
      }
      if (data.already.length > 0) msg += '（すでに紐づけ済み: ' + data.already.join('、') + '）';
      if ((data.moved || []).length > 0) msg += '（付け替え: ' + data.moved.join('、') + '）';
      if ((data.deactivated || []).length > 0) msg += '（空になった元の保護者アカウント ' + data.deactivated.join('、') + ' は無効化しました）';
      showMsg('sibling-msg', data.added.length > 0, msg);
      if (data.added.length > 0) f.reset();
    } else {
      showMsg('sibling-msg', false, errText(data, res.status));
    }
  } catch (err) { showMsg('sibling-msg', false, '通信エラー: ' + err); }
});

// ---- 講師登録(統括のみフォームが存在) ----
const teacherForm = document.getElementById('teacher-form');
if (teacherForm) {
  teacherForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const loginId = f.login_id.value.trim();
    const classroomIds = [...f.querySelectorAll('input[name="classroom_ids"]:checked')].map(el => Number(el.value));
    try {
      const { res, data } = await post('/api/register_teacher.php', {
        login_id: loginId,
        teacher_name: f.teacher_name.value.trim(),
        role: f.role.value,
        classroom_ids: classroomIds,
      });
      if (res.ok && data && data.ok) {
        recent.teachers.add(loginId);
        // 初回ログイン→本人がパスワード設定、を伝える案内メールを組み立てて表示
        // （仮パスワードはこの画面でしか確認できないので、reset より前に組み立てる）
        document.getElementById('teacher-guide-text').value = buildTeacherGuideText(
          data.teacher_name, loginId, data.temp_password);
        document.getElementById('teacher-guide-note').textContent = '';
        document.getElementById('teacher-guide').style.display = '';
        showMsg('teacher-msg', true, '登録しました。下のメール文をコピーして ' + data.teacher_name + ' 先生に送ってください（仮パスワードはこの画面でしか確認できません）');
        f.reset();
      } else {
        showMsg('teacher-msg', false, errText(data, res.status));
      }
    } catch (err) { showMsg('teacher-msg', false, '通信エラー: ' + err); }
  });

  document.getElementById('teacher-guide-copy').addEventListener('click', () =>
    copyWithNote(document.getElementById('teacher-guide-text').value, 'teacher-guide-note'));
}

// ---- 講師向け案内メールの生成（仮パスワードでログイン→本人が8〜15文字の半角英数を設定） ----
// opts.reset=true は「仮パスワード再発行」時の文面（新規登録は既定＝「発行しました」）
function buildTeacherGuideText(name, loginId, tempPassword, opts) {
  const lead = (opts && opts.reset)
    ? '学習記録システムの講師アカウントの仮パスワードを再発行しました。'
    : '学習記録システムの講師アカウントを発行しました。';
  return '【中京個別指導学院 学習記録システム 講師アカウントのご案内】\n'
    + name + ' 先生\n'
    + '\n' + lead + '\n'
    + '下記の仮パスワードでログインのうえ、ご自身の新しいパスワードを設定してください。\n'
    + '\n　ログインID：' + loginId + '\n'
    + '　仮パスワード：' + tempPassword + '\n'
    + '\n▼ 講師ページ（ここからログインします）\n'
    + 'https://chukyokobetsu.com/teacher.php\n'
    + '\n■ ログインの手順\n'
    + '　1. 上のURLを開き、ログインID・仮パスワードでログインします\n'
    + '　2. パスワード設定画面が出るので、ご自身の新しいパスワード（8〜15文字の半角英数）を決めて設定します\n'
    + '　3. 次回からは、ご自身で決めたパスワードでログインしてください\n'
    + '\n※仮パスワードはこのご案内でのみお知らせします。設定後のパスワードは大切に保管してください。';
}

// ---- 既存講師の一括初期化(統括のみ) ----
const bulkResetBtn = document.getElementById('bulk-reset-btn');
if (bulkResetBtn) {
  let bulkRows = [];  // 直近の発行結果（コピー用に保持）
  bulkResetBtn.addEventListener('click', async () => {
    const msg = 'まだ本パスワードを設定していない講師 全員の仮パスワードを再発行しますか？\n\n'
      + '・すでに本人がパスワードを設定した講師は対象外です\n'
      + '・発行後の一覧はこの画面でしか確認できません（閉じると再表示不可）';
    if (!confirm(msg)) return;
    bulkResetBtn.disabled = true;
    showMsg('bulk-reset-msg', true, '発行中...');
    try {
      const { res, data } = await post('/api/reset_pending_teachers.php', {});
      if (res.ok && data && data.ok) {
        bulkRows = data.teachers || [];
        const tbody = document.getElementById('bulk-reset-tbody');
        const result = document.getElementById('bulk-reset-result');
        tbody.innerHTML = '';
        // 前回生成した案内メールは隠す（今回の発行分で作り直す）
        document.getElementById('bulk-mail-text').style.display = 'none';
        document.getElementById('bulk-mail-copy').style.display = 'none';
        document.getElementById('bulk-mail-note').textContent = '';
        if (bulkRows.length === 0) {
          result.style.display = 'none';
          showMsg('bulk-reset-msg', true, '対象の講師はいませんでした（全員すでに本パスワード設定済みです）');
        } else {
          bulkRows.forEach((t) => {
            const tr = document.createElement('tr');
            const td = (v) => { const c = document.createElement('td'); c.textContent = v; return c; };
            const pw = td(t.temp_password);
            pw.style.fontFamily = 'monospace';
            pw.style.fontWeight = '700';
            tr.appendChild(td(t.teacher_name));
            tr.appendChild(td(t.login_id));
            tr.appendChild(pw);
            tbody.appendChild(tr);
            recent.teachers.add(t.login_id);
          });
          result.style.display = 'block';
          document.getElementById('bulk-copy-note').textContent = '';
          showMsg('bulk-reset-msg', true, data.count + '人の仮パスワードを発行しました。下の一覧を配布してください。');
        }
      } else {
        showMsg('bulk-reset-msg', false, errText(data, res.status));
      }
    } catch (err) {
      showMsg('bulk-reset-msg', false, '通信エラー: ' + err);
    } finally {
      bulkResetBtn.disabled = false;
    }
  });

  document.getElementById('bulk-copy-btn').addEventListener('click', async () => {
    // Excel/スプレッドシートにそのまま貼れるタブ区切り（ヘッダー付き）
    const tsv = ['氏名\tログインID\t仮パスワード']
      .concat(bulkRows.map((t) => t.teacher_name + '\t' + t.login_id + '\t' + t.temp_password))
      .join('\n');
    const note = document.getElementById('bulk-copy-note');
    try {
      await navigator.clipboard.writeText(tsv);
      note.textContent = 'コピーしました（Excel等に貼り付けできます）';
    } catch (e) {
      note.textContent = 'コピーに失敗しました。手動で選択してコピーしてください';
    }
  });

  // 発行した全員分の案内メールを ✂ 区切りでまとめて生成（各先生に1通ずつ送る用）
  document.getElementById('bulk-mail-btn').addEventListener('click', () => {
    if (!bulkRows.length) return;
    document.getElementById('bulk-mail-text').value = bulkRows.map((t) =>
      buildTeacherGuideText(t.teacher_name, t.login_id, t.temp_password, { reset: true })).join(GUIDE_SEP);
    document.getElementById('bulk-mail-text').style.display = '';
    document.getElementById('bulk-mail-copy').style.display = '';
    document.getElementById('bulk-mail-note').textContent = bulkRows.length + '人分の案内メールを生成しました';
  });
  document.getElementById('bulk-mail-copy').addEventListener('click', () =>
    copyWithNote(document.getElementById('bulk-mail-text').value, 'bulk-mail-note'));
}

// ---- 講師情報の修正(統括のみ) ----
const teacherEditForm = document.getElementById('teacher-edit-form');

// 一覧の「編集」ボタンから、講師1件分の現在値をフォームに流し込む
function fillTeacherEditForm(r) {
  if (!teacherEditForm || !r) return;
  teacherEditForm.login_id.value = r.login_id;
  teacherEditForm.teacher_name.value = r.teacher_name || '';
  teacherEditForm.role.value = r.role || 'teacher';
  const ids = String(r.classroom_ids || '').split(',').filter(Boolean);
  teacherEditForm.querySelectorAll('input[name="classroom_ids"]').forEach((chk) => {
    chk.checked = ids.includes(chk.value);
  });
  showMsg('teacher-edit-msg', true, r.teacher_name + '（' + r.login_id + '）の情報を読み込みました');
  teacherEditForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

if (teacherEditForm) {
  teacherEditForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const loginId = f.login_id.value.trim();
    if (loginId === '') { showMsg('teacher-edit-msg', false, '一覧の「編集」から読み込んでください'); return; }
    const classroomIds = [...f.querySelectorAll('input[name="classroom_ids"]:checked')].map(el => Number(el.value));
    try {
      const { res, data } = await post('/api/update_teacher.php', {
        login_id: loginId,
        teacher_name: f.teacher_name.value.trim(),
        role: f.role.value,
        classroom_ids: classroomIds,
      });
      if (res.ok && data && data.ok) {
        recent.teachers.add(loginId);
        showMsg('teacher-edit-msg', true, '保存しました');
        loadList('teachers');
      } else {
        showMsg('teacher-edit-msg', false, errText(data, res.status));
      }
    } catch (err) { showMsg('teacher-edit-msg', false, '通信エラー: ' + err); }
  });
}

// ---- 生徒情報の修正（氏名・学年・志望校） ----
const renameForm = document.getElementById('rename-form');

// 志望校 <select> に現在値をセットする。選択肢に無いID（＝無効化された学校）でも、
// 名前つきの一時オプションを足して選択を保つ（保存時に意図せずNULLへ消し込むのを防ぐ）。
function setSchoolSelect(sel, id, name) {
  if (id == null) { sel.value = ''; return; }
  const idStr = String(id);
  if (![...sel.options].some((o) => o.value === idStr)) {
    const opt = document.createElement('option');
    opt.value = idStr;
    opt.textContent = (name || '不明な学校') + '（無効）';
    sel.appendChild(opt);
  }
  sel.value = idStr;
}

// 修正フォームに1件分の現在値を流し込む（一覧の「編集」ボタン、または生徒コード入力から呼ぶ）
function fillEditForm(r) {
  if (!r) return;
  renameForm.login_id.value = r.login_id;
  renameForm.student_name.value = r.student_name || '';
  renameForm.grade.value = r.grade || '';
  setSchoolSelect(renameForm.target_private_id, r.target_private_id, r.target_private_name);
  setSchoolSelect(renameForm.target_public_id, r.target_public_id, r.target_public_name);
  showMsg('rename-msg', true, r.student_name + '（' + r.login_id + '）の情報を読み込みました');
  renameForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// 生徒コードを入力/変更したら、取得済み一覧から現在値を自動で読み込む（手入力での取り違え・値の消し込み防止）
renameForm.login_id.addEventListener('change', () => {
  const code = renameForm.login_id.value.trim();
  const r = (listCache.students || []).find((s) => s.login_id === code);
  if (r) fillEditForm(r);
});

renameForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  const loginId = f.login_id.value.trim();
  try {
    const { res, data } = await post('/api/update_student.php', {
      login_id: loginId,
      student_name: f.student_name.value.trim(),
      grade: f.grade.value.trim() || null,
      target_private_id: f.target_private_id.value ? Number(f.target_private_id.value) : null,
      target_public_id: f.target_public_id.value ? Number(f.target_public_id.value) : null,
    });
    if (res.ok && data && data.ok) {
      recent.students.add(loginId);
      showMsg('rename-msg', true, '保存しました');
      f.reset();
      loadList('students');
    } else {
      showMsg('rename-msg', false, errText(data, res.status));
    }
  } catch (err) { showMsg('rename-msg', false, '通信エラー: ' + err); }
});

// ---- 初期化: 志望校プルダウンを読み込む ----
loadTargetSchools();
</script>
</body>
</html>
