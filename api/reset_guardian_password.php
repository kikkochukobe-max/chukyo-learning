<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 保護者パスワードの初期化（保護者がパスワードを忘れた時用）。
// 講師のPW初期化と同じ思想: 仮パスワードをサーバー側で自動生成して応答でのみ返し、
// must_change_password=1 に戻す（本人が次回ログインで8〜15字英数を設定し直す）。
// 権限: super_admin=全保護者 / classroom_admin=担当教室の生徒に紐づく保護者のみ
// （保護者からの申し出は教室で受けるため、講師のように統括限定にはしない）

require_post();
$actor = require_login(['teacher']);

$pdo = db();

$stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $actor['id']]);
$requesterRole = $stmt->fetchColumn();

if (!in_array($requesterRole, ['super_admin', 'classroom_admin'], true)) {
    json_response(['ok' => false, 'error' => 'forbidden'], 403);
}

$input = json_input();
$loginId = trim((string)($input['login_id'] ?? ''));
if ($loginId === '') {
    json_response(['ok' => false, 'error' => 'invalid_login_id'], 400);
}

$stmt = $pdo->prepare(
    'SELECT guardian_id, guardian_name FROM guardians WHERE login_id = :login_id AND is_active = 1'
);
$stmt->execute(['login_id' => $loginId]);
$guardian = $stmt->fetch();
if (!$guardian) {
    json_response(['ok' => false, 'error' => 'guardian_not_found'], 404);
}

if ($requesterRole === 'classroom_admin') {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM guardian_students gs
         JOIN students s ON s.student_id = gs.student_id
         WHERE gs.guardian_id = :gid
           AND s.classroom_id IN (SELECT classroom_id FROM teacher_classrooms WHERE teacher_id = :tid)'
    );
    $stmt->execute(['gid' => (int)$guardian['guardian_id'], 'tid' => $actor['id']]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_response(['ok' => false, 'error' => 'forbidden_classroom'], 403);
    }
}

$temp = generate_temp_password();

$stmt = $pdo->prepare(
    'UPDATE guardians SET password_hash = :hash, must_change_password = 1 WHERE guardian_id = :id'
);
$stmt->execute([
    'hash' => password_hash($temp, PASSWORD_DEFAULT),
    'id'   => $guardian['guardian_id'],
]);

json_response([
    'ok'            => true,
    'login_id'      => $loginId,
    'guardian_name' => $guardian['guardian_name'],
    'temp_password' => $temp,
]);
