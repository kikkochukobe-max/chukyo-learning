<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

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
$studentName = trim((string)($input['student_name'] ?? ''));

if ($loginId === '') {
    json_response(['ok' => false, 'error' => 'invalid_login_id'], 400);
}
if ($studentName === '' || mb_strlen($studentName) > 50) {
    json_response(['ok' => false, 'error' => 'invalid_student_name'], 400);
}

$stmt = $pdo->prepare('SELECT student_id, classroom_id FROM students WHERE login_id = :login_id');
$stmt->execute(['login_id' => $loginId]);
$student = $stmt->fetch();

if (!$student) {
    json_response(['ok' => false, 'error' => 'student_not_found'], 404);
}

if ($requesterRole === 'classroom_admin') {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM teacher_classrooms WHERE teacher_id = :tid AND classroom_id = :cid');
    $stmt->execute(['tid' => $actor['id'], 'cid' => $student['classroom_id']]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_response(['ok' => false, 'error' => 'forbidden_classroom'], 403);
    }
}

$stmt = $pdo->prepare('UPDATE students SET student_name = :name WHERE student_id = :id');
$stmt->execute(['name' => $studentName, 'id' => $student['student_id']]);

json_response(['ok' => true, 'login_id' => $loginId, 'student_name' => $studentName]);
