<?php
declare(strict_types=1);

// 講師のパスワード変更ページ。
// must_change_password=1 の講師は teacher.php / admin.php からここへ強制リダイレクトされる
// （変更するまで他のページに進めない）。通常時も各ページのヘッダーから任意で変更できる。
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$actor = current_actor();
if (!$actor || $actor['type'] !== 'teacher') {
    header('Location: /teacher.php');
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT teacher_name, must_change_password FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $actor['id']]);
$me = $stmt->fetch();
$must = (bool)$me['must_change_password'];
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>パスワード変更 | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root{--paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;--ai:#2C5F8A;--shu:#C73E2E;--white:#fff;
    --radius:14px;--shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06)}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6}
  .box{max-width:440px;margin:80px auto;background:var(--white);border-radius:var(--radius);
    box-shadow:var(--shadow);border-top:4px solid var(--ai);padding:28px}
  h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:18px;color:var(--ai)}
  p.sub{font-size:12px;color:var(--ink-soft);margin-top:2px}
  .must{background:#FDF0EE;border:1.5px solid var(--shu);border-radius:8px;
    font-size:12px;color:var(--shu);font-weight:700;padding:8px 12px;margin-top:12px}
  label{display:block;font-size:12px;font-weight:700;margin-top:14px}
  input{width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;margin-top:4px}
  button{margin-top:18px;width:100%;background:var(--ai);color:#fff;border:none;border-radius:8px;
    padding:11px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Zen Maru Gothic',sans-serif}
  .err{color:#c0392b;font-size:12px;margin-top:8px;min-height:16px}
  .ok{color:#166534;font-size:13px;font-weight:700;margin-top:8px}
  .back{display:inline-block;margin-top:14px;font-size:13px;color:var(--ai);text-decoration:none;
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700}
</style>
</head>
<body>
<div class="box">
  <h1>パスワード変更</h1>
  <p class="sub"><?= h($me['teacher_name']) ?> 先生のパスワードを変更します</p>
<?php if ($must): ?>
  <div class="must">初期パスワードのままです。新しいパスワードに変更してから利用を開始してください。</div>
<?php endif; ?>
  <label>現在のパスワード<input type="password" id="cur" autocomplete="current-password"></label>
  <label>新しいパスワード（8文字以上）<input type="password" id="new1" autocomplete="new-password"></label>
  <label>新しいパスワード（もう一度）<input type="password" id="new2" autocomplete="new-password"></label>
  <button id="btn" type="button">変更する</button>
  <div class="err" id="err"></div>
<?php if (!$must): ?>
  <a class="back" href="/teacher.php">← 講師ページへ戻る</a>
<?php endif; ?>
</div>
<script>
const ERROR_TEXT = {
  invalid_password: '新しいパスワードは8文字以上にしてください',
  same_password: '現在と同じパスワードには変更できません',
  wrong_password: '現在のパスワードが違います',
};
document.getElementById('btn').addEventListener('click', async () => {
  const err = document.getElementById('err');
  err.textContent = '';
  const cur = document.getElementById('cur').value;
  const new1 = document.getElementById('new1').value;
  const new2 = document.getElementById('new2').value;
  if (new1.length < 8) { err.textContent = '新しいパスワードは8文字以上にしてください'; return; }
  if (new1 !== new2) { err.textContent = '新しいパスワードが2回とも一致しません'; return; }
  try {
    const res = await fetch('/api/change_password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ current_password: cur, new_password: new1 }),
    });
    const data = await res.json().catch(() => null);
    if (res.ok && data && data.ok) {
      err.className = 'ok';
      err.textContent = '変更しました。講師ページに移動します...';
      setTimeout(() => { location.href = '/teacher.php'; }, 1200);
    } else {
      err.textContent = (data && ERROR_TEXT[data.error]) || '変更に失敗しました';
    }
  } catch (e) { err.textContent = '通信エラーが発生しました'; }
});
document.getElementById('new2').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') document.getElementById('btn').click();
});
</script>
</body>
</html>
