<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 保護者一覧（登録確認用）。紐づくお子さまの生徒コード・氏名も並べて返す。
// super_admin=全件 / それ以外=担当教室の生徒に紐づく保護者のみ
$actor = require_login(['teacher']);
$pdo = db();

$stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $actor['id']]);
$requesterRole = $stmt->fetchColumn();

$baseSql =
    "SELECT g.login_id, g.guardian_name, g.is_active, g.created_at,
            COALESCE(GROUP_CONCAT(CONCAT(s.login_id, ' ', s.student_name)
              ORDER BY s.login_id SEPARATOR '、'), '') AS children
     FROM guardians g
     LEFT JOIN guardian_students gs ON gs.guardian_id = g.guardian_id
     LEFT JOIN students s ON s.student_id = gs.student_id";

if ($requesterRole === 'super_admin') {
    $guardians = $pdo->query($baseSql . ' GROUP BY g.guardian_id ORDER BY g.guardian_id')->fetchAll();
} else {
    $stmt = $pdo->prepare(
        $baseSql . "
         WHERE g.guardian_id IN (
           SELECT gs2.guardian_id FROM guardian_students gs2
           JOIN students s2 ON s2.student_id = gs2.student_id
           WHERE s2.classroom_id IN (SELECT classroom_id FROM teacher_classrooms WHERE teacher_id = :tid)
         )
         GROUP BY g.guardian_id ORDER BY g.guardian_id"
    );
    $stmt->execute(['tid' => $actor['id']]);
    $guardians = $stmt->fetchAll();
}

foreach ($guardians as &$row) {
    $row['is_active'] = (bool)$row['is_active'];
}

json_response(['ok' => true, 'guardians' => $guardians]);
