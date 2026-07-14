<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$actor = require_login(['teacher']);
$pdo = db();

$stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $actor['id']]);
$requesterRole = $stmt->fetchColumn();

$baseSql =
    'SELECT s.login_id, s.student_name, s.grade, c.classroom_name, s.is_active, s.created_at,
            s.target_private_id, tpv.name AS target_private_name,
            s.target_public_id,  tpb.name AS target_public_name
     FROM students s
     JOIN classrooms c ON c.classroom_id = s.classroom_id
     LEFT JOIN target_schools tpv ON tpv.target_school_id = s.target_private_id
     LEFT JOIN target_schools tpb ON tpb.target_school_id = s.target_public_id';

if ($requesterRole === 'super_admin') {
    $stmt = $pdo->query($baseSql . ' ORDER BY c.classroom_name, s.login_id');
    $students = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare(
        $baseSql . '
         WHERE s.classroom_id IN (SELECT classroom_id FROM teacher_classrooms WHERE teacher_id = :tid)
         ORDER BY c.classroom_name, s.login_id'
    );
    $stmt->execute(['tid' => $actor['id']]);
    $students = $stmt->fetchAll();
}

foreach ($students as &$row) {
    $row['is_active'] = (bool)$row['is_active'];
    $row['target_private_id'] = $row['target_private_id'] !== null ? (int)$row['target_private_id'] : null;
    $row['target_public_id']  = $row['target_public_id'] !== null ? (int)$row['target_public_id'] : null;
}

json_response(['ok' => true, 'students' => $students]);
