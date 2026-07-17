<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 保護者の完全物理削除（統括のみ・登録間違い/テストデータの掃除用）。
// 消えるのは保護者アカウント・子どもとの紐づけ・保護者のログイン履歴だけで、
// 生徒と学習記録には一切影響しない。削除後は同じ代表の子で登録し直せる。
// 通常の停止（退塾など）は set_active.php の無効化を使う。

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

$stmt = $pdo->prepare('SELECT guardian_id, guardian_name FROM guardians WHERE login_id = :login_id');
$stmt->execute(['login_id' => $loginId]);
$guardian = $stmt->fetch();
if (!$guardian) {
    json_response(['ok' => false, 'error' => 'guardian_not_found'], 404);
}
$gid = (int)$guardian['guardian_id'];

$pdo->beginTransaction();
try {
    // login_logs は actor_type+actor_id のポリモーフィック参照（FKなし）なので明示的に消す
    $pdo->prepare("DELETE FROM login_logs WHERE actor_type = 'guardian' AND actor_id = :id")
        ->execute(['id' => $gid]);
    // guardian_students は FK ON DELETE CASCADE で一緒に消える
    $pdo->prepare('DELETE FROM guardians WHERE guardian_id = :id')->execute(['id' => $gid]);
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    throw $e;
}

json_response([
    'ok'            => true,
    'login_id'      => $loginId,
    'guardian_name' => $guardian['guardian_name'],
]);
