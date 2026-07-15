<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 講師パスワードの初期化（super_admin のみ・自分自身は不可）。
// 仮パスワードはサーバー側で自動生成して返す（弱いパスワードの手入力を防ぐ）。
// 初期化された講師は must_change_password=1 になり、次回ログイン時に変更を求められる。

require_post();
$actor = require_login(['teacher']);

$pdo = db();

$stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $actor['id']]);
if ($stmt->fetchColumn() !== 'super_admin') {
    json_response(['ok' => false, 'error' => 'forbidden'], 403);
}

$input = json_input();
$loginId = trim((string)($input['login_id'] ?? ''));
if ($loginId === '') {
    json_response(['ok' => false, 'error' => 'invalid_login_id'], 400);
}

$stmt = $pdo->prepare('SELECT teacher_id, teacher_name FROM teachers WHERE login_id = :login_id');
$stmt->execute(['login_id' => $loginId]);
$target = $stmt->fetch();
if (!$target) {
    json_response(['ok' => false, 'error' => 'teacher_not_found'], 404);
}
if ((int)$target['teacher_id'] === (int)$actor['id']) {
    json_response(['ok' => false, 'error' => 'cannot_self'], 400);
}

$temp = generate_temp_password();

$stmt = $pdo->prepare(
    'UPDATE teachers SET password_hash = :hash, must_change_password = 1 WHERE teacher_id = :id'
);
$stmt->execute([
    'hash' => password_hash($temp, PASSWORD_DEFAULT),
    'id'   => $target['teacher_id'],
]);

json_response([
    'ok'            => true,
    'login_id'      => $loginId,
    'teacher_name'  => $target['teacher_name'],
    'temp_password' => $temp,
]);
