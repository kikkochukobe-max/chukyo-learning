<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 講師情報の変更（氏名・役割・担当教室）。super_admin のみ。
// 自分自身は変更不可（役割の自己降格でアクセスを失う事故を防ぐ）。
// パスワードはここでは扱わない（PW初期化 / 本人変更が別にある）。

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
$teacherName = trim((string)($input['teacher_name'] ?? ''));
$role = (string)($input['role'] ?? '');
$classroomIds = is_array($input['classroom_ids'] ?? null) ? array_map('intval', $input['classroom_ids']) : [];

$validRoles = ['super_admin', 'classroom_admin', 'teacher'];
if ($loginId === '') {
    json_response(['ok' => false, 'error' => 'invalid_login_id'], 400);
}
if ($teacherName === '' || mb_strlen($teacherName) > 50) {
    json_response(['ok' => false, 'error' => 'invalid_teacher_name'], 400);
}
if (!in_array($role, $validRoles, true)) {
    json_response(['ok' => false, 'error' => 'invalid_role'], 400);
}
if ($role !== 'super_admin' && count($classroomIds) === 0) {
    json_response(['ok' => false, 'error' => 'classroom_ids_required'], 400);
}

$stmt = $pdo->prepare('SELECT teacher_id FROM teachers WHERE login_id = :login_id');
$stmt->execute(['login_id' => $loginId]);
$targetId = $stmt->fetchColumn();
if ($targetId === false) {
    json_response(['ok' => false, 'error' => 'teacher_not_found'], 404);
}
$targetId = (int)$targetId;
if ($targetId === (int)$actor['id']) {
    json_response(['ok' => false, 'error' => 'cannot_self'], 400);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE teachers SET teacher_name = :name, role = :role WHERE teacher_id = :id'
    );
    $stmt->execute(['name' => $teacherName, 'role' => $role, 'id' => $targetId]);

    // 担当教室を作り直す（既存を全削除 → 選択分を再登録）。
    // super_admin は全教室扱いなので担当教室リンクは持たせない。
    $del = $pdo->prepare('DELETE FROM teacher_classrooms WHERE teacher_id = :id');
    $del->execute(['id' => $targetId]);

    if ($role !== 'super_admin') {
        $check = $pdo->prepare('SELECT COUNT(*) FROM classrooms WHERE classroom_id = :id');
        $link = $pdo->prepare('INSERT INTO teacher_classrooms (teacher_id, classroom_id) VALUES (:teacher_id, :classroom_id)');
        foreach (array_unique($classroomIds) as $classroomId) {
            $check->execute(['id' => $classroomId]);
            if ((int)$check->fetchColumn() === 0) {
                throw new InvalidArgumentException('invalid_classroom_id');
            }
            $link->execute(['teacher_id' => $targetId, 'classroom_id' => $classroomId]);
        }
    }

    $pdo->commit();
} catch (InvalidArgumentException $e) {
    $pdo->rollBack();
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (PDOException $e) {
    $pdo->rollBack();
    throw $e;
}

json_response(['ok' => true, 'login_id' => $loginId, 'teacher_name' => $teacherName]);
