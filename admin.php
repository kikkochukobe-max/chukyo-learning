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
    <button class="tab" data-tab="guardian" type="button">保護者登録</button>
<?php if ($role === 'super_admin'): ?>
    <button class="tab" data-tab="teacher" type="button">講師登録</button>
<?php endif; ?>
    <button class="tab" data-tab="list" type="button">生徒一覧・氏名修正</button>
  </div>

  <!-- ============ 生徒登録 ============ -->
  <div class="pane" id="pane-student">
  <div class="card">
    <h2>生徒登録</h2>
    <p class="note">生徒コードは自動採番（入塾年度2桁+通し番号4桁）。登録後に表示されるコードを生徒に渡してください。</p>
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
      </div>
      <button class="go" type="submit">生徒を登録</button>
      <div class="msg" id="student-msg"></div>
    </form>
  </div>
  </div>

  <!-- ============ 生徒一括登録 ============ -->
  <div class="pane" id="pane-bulk" style="display:none;">
  <div class="card">
    <h2>生徒一括登録</h2>
    <p class="note">1行1人、「教室ID・生徒名・学年・PIN」の4列（学年は空欄可）。Excelで4列を範囲コピーしてそのまま貼り付けるか、UTF-8のCSVを読み込みます。<br>
      教室ID: <?= h(implode(' / ', array_map(fn($c) => $c['classroom_id'] . '=' . $c['classroom_name'], $classrooms))) ?></p>
    <label>CSVファイルを読み込む<input type="file" id="csv-file" accept=".csv,.txt"></label>
    <form id="bulk-form">
      <label>貼り付け欄（エクセルからコピー&amp;ペースト可）<textarea name="rows" placeholder="1&#9;山田太郎&#9;es4&#9;1234&#10;2&#9;鈴木花子&#9;&#9;5678"></textarea></label>
      <button class="go" type="submit">一括登録を実行</button>
    </form>
    <div class="scroll">
    <table id="bulk-table" style="display:none;">
      <thead><tr><th>#</th><th>入力</th><th>結果</th><th>生徒コード / エラー</th></tr></thead>
      <tbody></tbody>
    </table>
    </div>
  </div>
  </div>

  <!-- ============ 保護者登録 ============ -->
  <div class="pane" id="pane-guardian" style="display:none;">
  <div class="card">
    <h2>保護者登録</h2>
    <p class="note">お子さまの生徒コードと紐づけて登録します（兄弟はカンマ区切りで複数可）。<strong>保護者IDは「最初に入力したお子さまの生徒コードに g を付けたもの」を自動発行</strong>します（例: 260038 → g260038）。保護者専用ログインは後日リリース予定で、アカウントの発行だけ先に行えます。</p>
    <form id="guardian-form">
      <div class="row2">
        <label>保護者氏名<input type="text" name="guardian_name" required maxlength="50"></label>
        <label>PIN（4桁数字）<input type="text" name="pin" pattern="\d{4}" maxlength="4" inputmode="numeric" required autocomplete="off"></label>
        <label>お子さまの生徒コード（例: 260001, 260002 ／ 先頭が代表）<input type="text" name="student_codes" required placeholder="260001, 260002"></label>
      </div>
      <button class="go" type="submit">保護者を登録</button>
      <div class="msg" id="guardian-msg"></div>
    </form>
  </div>
  </div>

<?php if ($role === 'super_admin'): ?>
  <!-- ============ 講師登録(統括のみ) ============ -->
  <div class="pane" id="pane-teacher" style="display:none;">
  <div class="card">
    <h2>講師登録 <span style="font-size:11px;color:var(--ink-soft);font-weight:500;">（統括のみ）</span></h2>
    <p class="note">初期パスワードは初回ログイン時に本人が変更します。</p>
    <form id="teacher-form">
      <div class="row2">
        <label>ログインID<input type="text" name="login_id" required maxlength="50"></label>
        <label>初期パスワード（8文字以上）<input type="text" name="password" required minlength="8" autocomplete="off"></label>
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
      <h3 style="margin:14px 0 6px;font-family:'Zen Maru Gothic',sans-serif;color:var(--ai,#2C5F8A);font-size:15px;">氏名の修正</h3>
      <form id="rename-form" class="row2" style="align-items:end;">
        <label>生徒コード<input type="text" name="login_id" maxlength="6" inputmode="numeric"></label>
        <label>新しい氏名
          <div style="display:flex;gap:8px;">
            <input type="text" name="student_name" maxlength="50" style="flex:1;">
            <button class="go" type="submit" style="margin-top:4px;white-space:nowrap;">氏名を修正</button>
          </div>
        </label>
      </form>
      <div class="msg" id="rename-msg"></div>
      <label style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;font-size:13px;font-weight:700;color:var(--ink,#33312B);cursor:pointer;white-space:nowrap;">
        <input type="checkbox" class="hide-test" checked style="width:auto;flex:none;margin:0;">テスト生（名前に「テスト」を含む）を非表示
      </label>
      <p style="margin:8px 0 4px;color:var(--ai,#2C5F8A);font-weight:700;font-size:13px;">列の見出し（生徒コード・教室・氏名など）をクリックすると、その項目で並び替えできます（もう一度クリックで昇順⇄降順、▲▼が今の並び順）。</p>
      <div class="scroll">
      <table id="students-table">
        <thead><tr><th class="sortable" data-key="login_id">生徒コード</th><th class="sortable" data-key="classroom_name">教室</th><th class="sortable" data-key="grade">学年</th><th class="sortable" data-key="student_name">氏名</th><th class="sortable" data-key="is_active">状態</th><th class="sortable" data-key="created_at">登録日</th><th>操作</th></tr></thead>
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
      <div class="scroll">
      <table id="guardians-table">
        <thead><tr><th class="sortable" data-key="login_id">ログインID</th><th class="sortable" data-key="guardian_name">氏名</th><th class="sortable" data-key="children">お子さま</th><th class="sortable" data-key="is_active">状態</th><th class="sortable" data-key="created_at">登録日</th><th>操作</th></tr></thead>
        <tbody></tbody>
      </table>
      </div>
    </div>

<?php if ($role === 'super_admin'): ?>
    <!-- 講師一覧 -->
    <div id="list-teachers" style="display:none;">
      <label style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;font-size:13px;font-weight:700;color:var(--ink,#33312B);cursor:pointer;white-space:nowrap;">
        <input type="checkbox" class="hide-test" checked style="width:auto;flex:none;margin:0;">テスト生（名前に「テスト」を含む）を非表示
      </label>
      <p style="margin:8px 0 4px;color:var(--ai,#2C5F8A);font-weight:700;font-size:13px;">列の見出し（ログインID・氏名・役割など）をクリックすると、その項目で並び替えできます（もう一度クリックで昇順⇄降順、▲▼が今の並び順）。</p>
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
  teacher_not_found: 'その講師が見つかりません',
  invalid_kind: '種別の指定が不正です',
  cannot_self: '自分自身を無効にすることはできません',
  forbidden: 'この操作の権限がありません',
};

const MY_LOGIN_ID = <?= json_encode($me['login_id']) ?>;
const IS_SUPER = <?= json_encode($role === 'super_admin') ?>;

function errText(data, status) {
  if (data && data.error) {
    let t = ERROR_TEXT[data.error] || data.error;
    if (data.student_code) t += '（' + data.student_code + '）';
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
  });
});

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
function actionCell(tr, kind, loginId, name, isActive) {
  const td = document.createElement('td');
  // 自分自身の講師アカウントにはボタンを出さない(APIも拒否する)
  if (!(kind === 'teachers' && loginId === MY_LOGIN_ID)) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mini ' + (isActive ? 'off' : 'on');
    btn.textContent = isActive ? '無効にする' : '有効に戻す';
    btn.addEventListener('click', () => toggleActive(kind, loginId, name, !isActive));
    td.appendChild(btn);
  }
  // 完全削除は生徒のみ・統括のみ（登録間違い・テストデータの掃除用）
  if (kind === 'students' && IS_SUPER) {
    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'mini del';
    del.textContent = '完全削除';
    del.addEventListener('click', () => deleteStudent(loginId, name));
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
  tr.appendChild(td);
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
      prompt('初期化しました。この仮パスワードを ' + data.teacher_name + ' 先生に伝えてください\n（この画面を閉じると二度と表示されません）', data.temp_password);
      loadList('teachers');
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
  tbody.innerHTML = '<tr><td colspan="7" style="color:var(--ink-soft);">読み込み中...</td></tr>';
  try {
    const res = await fetch(urls[kind], { credentials: 'same-origin' });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data || !data.ok) {
      tbody.innerHTML = '<tr><td colspan="7" class="ng-cell">一覧の取得に失敗しました</td></tr>';
      return;
    }
    listCache[kind] = data[kind];
    renderRows(kind);
  } catch (err) {
    tbody.innerHTML = '<tr><td colspan="7" class="ng-cell">通信エラーが発生しました</td></tr>';
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
  tbody.innerHTML = '';
  if (rows.length === 0) {
    const text = (kind === 'students' && classroomFilter !== '')
      ? 'この教室には生徒がいません' : 'まだ登録がありません';
    tbody.innerHTML = '<tr><td colspan="7" style="color:var(--ink-soft);">' + text + '</td></tr>';
    return;
  }
  for (const r of rows) {
    const tr = document.createElement('tr');
    let name;
    if (kind === 'students') {
      name = r.student_name;
      fillRow(tr, [r.login_id, r.classroom_name, r.grade ?? '', r.student_name, '', r.created_at],
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
    actionCell(tr, kind, r.login_id, name, r.is_active);
    tbody.appendChild(tr);
  }
  // 色違いの行(今回の登録・変更)を上に見せる（並び替え中は順序を尊重してそのまま）
  if (!sort.key) {
    tbody.querySelectorAll('tr.new-row').forEach((tr) => tbody.prepend(tr));
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
    });
    if (res.ok && data && data.ok) {
      recent.students.add(data.login_id);
      showMsg('student-msg', true, '登録しました。生徒コード: ' + data.login_id + '（登録一覧タブで確認できます）');
      f.student_name.value = ''; f.pin.value = '';
    } else {
      showMsg('student-msg', false, errText(data, res.status));
    }
  } catch (err) { showMsg('student-msg', false, '通信エラー: ' + err); }
});

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

document.getElementById('bulk-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const lines = e.target.rows.value.split('\n').map(l => l.trim()).filter(l => l !== '');
  const table = document.getElementById('bulk-table');
  const tbody = table.querySelector('tbody');
  tbody.innerHTML = '';
  table.style.display = '';

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
        row.children[2].innerHTML = '<span class="ok-cell">OK</span>';
        row.children[3].textContent = data.login_id;
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
});

// ---- 保護者登録 ----
document.getElementById('guardian-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  const codes = f.student_codes.value.split(/[,、\s]+/).map(s => s.trim()).filter(s => s !== '');
  try {
    const { res, data } = await post('/api/register_guardian.php', {
      pin: f.pin.value,
      guardian_name: f.guardian_name.value.trim(),
      student_codes: codes,
    });
    if (res.ok && data && data.ok) {
      recent.guardians.add(data.login_id);
      showMsg('guardian-msg', true, '登録しました。保護者ID: ' + data.login_id + '（お子さま' + data.linked + '人と紐づけ済み。登録一覧タブで確認できます）');
      f.reset();
    } else {
      showMsg('guardian-msg', false, errText(data, res.status));
    }
  } catch (err) { showMsg('guardian-msg', false, '通信エラー: ' + err); }
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
        password: f.password.value,
        teacher_name: f.teacher_name.value.trim(),
        role: f.role.value,
        classroom_ids: classroomIds,
      });
      if (res.ok && data && data.ok) {
        recent.teachers.add(loginId);
        showMsg('teacher-msg', true, '登録しました（登録一覧タブで確認できます）');
        f.reset();
      } else {
        showMsg('teacher-msg', false, errText(data, res.status));
      }
    } catch (err) { showMsg('teacher-msg', false, '通信エラー: ' + err); }
  });
}

// ---- 生徒名の変更 ----
document.getElementById('rename-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  const loginId = f.login_id.value.trim();
  try {
    const { res, data } = await post('/api/update_student.php', {
      login_id: loginId,
      student_name: f.student_name.value.trim(),
    });
    if (res.ok && data && data.ok) {
      recent.students.add(loginId);
      showMsg('rename-msg', true, '変更しました');
      f.reset();
      loadList('students');
    } else {
      showMsg('rename-msg', false, errText(data, res.status));
    }
  } catch (err) { showMsg('rename-msg', false, '通信エラー: ' + err); }
});
</script>
</body>
</html>
