<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_post();
$actor = require_login(['teacher']);

$pdo = db();

$stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $actor['id']]);
$requesterRole = $stmt->fetchColumn();

if ($requesterRole !== 'super_admin') {
    json_response(['ok' => false, 'error' => 'forbidden'], 403);
}

$input = json_input();
$loginId = trim((string)($input['login_id'] ?? ''));
$password = (string)($input['password'] ?? '');
$teacherName = trim((string)($input['teacher_name'] ?? ''));
$role = (string)($input['role'] ?? '');
$classroomIds = is_array($input['classroom_ids'] ?? null) ? array_map('intval', $input['classroom_ids']) : [];

$validRoles = ['super_admin', 'classroom_admin', 'teacher'];
if ($loginId === '' || mb_strlen($loginId) > 50) {
    json_response(['ok' => false, 'error' => 'invalid_login_id'], 400);
}
if (mb_strlen($password) < 8) {
    json_response(['ok' => false, 'error' => 'invalid_password'], 400);
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

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO teachers (login_id, password_hash, teacher_name, role, must_change_password)
         VALUES (:login_id, :password_hash, :teacher_name, :role, 1)'
    );
    $stmt->execute([
        'login_id'      => $loginId,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'teacher_name'  => $teacherName,
        'role'          => $role,
    ]);
    $teacherId = (int)$pdo->lastInsertId();

    if ($role !== 'super_admin') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM classrooms WHERE classroom_id = :id');
        $link = $pdo->prepare('INSERT INTO teacher_classrooms (teacher_id, classroom_id) VALUES (:teacher_id, :classroom_id)');
        foreach (array_unique($classroomIds) as $classroomId) {
            $stmt->execute(['id' => $classroomId]);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new InvalidArgumentException('invalid_classroom_id');
            }
            $link->execute(['teacher_id' => $teacherId, 'classroom_id' => $classroomId]);
        }
    }

    $pdo->commit();
} catch (InvalidArgumentException $e) {
    $pdo->rollBack();
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '23000') {
        json_response(['ok' => false, 'error' => 'login_id_taken'], 409);
    }
    throw $e;
}

json_response(['ok' => true, 'teacher_id' => $teacherId, 'login_id' => $loginId]);
