<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// 統括管理者(super_admin)を1人だけ作るための使い捨てスクリプト。
// teachers に1行でも存在したら以後は一切動作しない。実行後は必ずこのファイルをHetemlから削除する。
$pdo = db();
$alreadyExists = (int)$pdo->query('SELECT COUNT(*) FROM teachers')->fetchColumn() > 0;

$error = null;
$done = false;

if ($alreadyExists) {
    $error = '既に管理者が存在するため実行できません。このファイルを削除してください。';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginId = trim((string)($_POST['login_id'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $teacherName = trim((string)($_POST['teacher_name'] ?? ''));

    if ($loginId === '' || mb_strlen($loginId) > 50) {
        $error = 'ログインIDを入力してください（50文字以内）。';
    } elseif (mb_strlen($password) < 8) {
        $error = 'パスワードは8文字以上にしてください。';
    } elseif ($teacherName === '' || mb_strlen($teacherName) > 50) {
        $error = '氏名を入力してください（50文字以内）。';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO teachers (login_id, password_hash, teacher_name, role, must_change_password)
             VALUES (:login_id, :password_hash, :teacher_name, :role, 1)'
        );
        $stmt->execute([
            'login_id'      => $loginId,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'teacher_name'  => $teacherName,
            'role'          => 'super_admin',
        ]);
        $done = true;
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>初回管理者作成</title>
</head>
<body>
<h1>初回管理者作成</h1>
<?php if ($done): ?>
    <p>統括管理者「<?= htmlspecialchars($teacherName, ENT_QUOTES, 'UTF-8') ?>」を作成しました。</p>
    <p><strong>このファイル（setup_first_admin.php）を今すぐHetemlから削除してください。</strong></p>
<?php else: ?>
    <?php if ($error): ?>
        <p style="color:red;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if (!$alreadyExists): ?>
    <form method="post">
        <p><label>ログインID: <input type="text" name="login_id" maxlength="50" required></label></p>
        <p><label>パスワード: <input type="password" name="password" minlength="8" required></label></p>
        <p><label>氏名: <input type="text" name="teacher_name" maxlength="50" required></label></p>
        <p><button type="submit">作成</button></p>
    </form>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
