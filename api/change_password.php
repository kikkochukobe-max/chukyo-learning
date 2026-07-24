<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// ログイン中の講師が自分のパスワードを変更する。
// 現在のパスワードの照合を必須にし、成功時に must_change_password を落とす。
// パスワードルールは8〜15文字の半角英数。
// （保護者はお子さまの生徒PINでログインする方式のため、パスワード変更の対象外）

require_post();
$actor = require_login(['teacher']);

$t = ['table' => 'teachers', 'id' => 'teacher_id'];

$input = json_input();
$current = (string)($input['current_password'] ?? '');
$new = (string)($input['new_password'] ?? '');

if (!is_valid_teacher_password($new)) {
    json_response(['ok' => false, 'error' => 'invalid_password'], 400);
}
if ($new === $current) {
    json_response(['ok' => false, 'error' => 'same_password'], 400);
}

$pdo = db();
$stmt = $pdo->prepare("SELECT password_hash FROM {$t['table']} WHERE {$t['id']} = :id AND is_active = 1");
$stmt->execute(['id' => $actor['id']]);
$hash = $stmt->fetchColumn();

if (!$hash || !password_verify($current, $hash)) {
    json_response(['ok' => false, 'error' => 'wrong_password'], 401);
}

$stmt = $pdo->prepare(
    "UPDATE {$t['table']} SET password_hash = :hash, must_change_password = 0 WHERE {$t['id']} = :id"
);
$stmt->execute([
    'hash' => password_hash($new, PASSWORD_DEFAULT),
    'id'   => $actor['id'],
]);

json_response(['ok' => true]);
