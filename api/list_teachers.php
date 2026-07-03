<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 講師一覧（登録確認用）。講師登録と同じく super_admin のみ
$actor = require_login(['teacher']);
$pdo = db();

$stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $actor['id']]);
if ($stmt->fetchColumn() !== 'super_admin') {
    json_response(['ok' => false, 'error' => 'forbidden'], 403);
}

$teachers = $pdo->query(
    "SELECT t.login_id, t.teacher_name, t.role, t.is_active, t.created_at,
            COALESCE(GROUP_CONCAT(c.classroom_name ORDER BY c.classroom_id SEPARATOR '・'), '') AS classroom_names
     FROM teachers t
     LEFT JOIN teacher_classrooms tc ON tc.teacher_id = t.teacher_id
     LEFT JOIN classrooms c ON c.classroom_id = tc.classroom_id
     GROUP BY t.teacher_id
     ORDER BY t.teacher_id"
)->fetchAll();

foreach ($teachers as &$row) {
    $row['is_active'] = (bool)$row['is_active'];
}

json_response(['ok' => true, 'teachers' => $teachers]);
