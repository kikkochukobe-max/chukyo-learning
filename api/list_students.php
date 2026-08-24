<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$actor = require_login(['teacher']);
$pdo = db();

$stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $actor['id']]);
$requesterRole = $stmt->fetchColumn();

// guardian_login_id / siblings = 兄弟登録が済んでいる家庭を admin.php の一覧で見分けるための2列。
// siblings は「自分以外」の同一保護者の子（コード＋氏名）。保護者が未発行なら両方 NULL。
// ⚠ SQL内に ' ' と '、' を書くので、この文字列はダブルクォートで囲む（シングルだと
//   PHPの文字列がそこで終わって parse error → 一覧が「取得に失敗しました」になる）。
//   中に $ や { が無いことを確認済み＝補間の心配はない。list_guardians.php も同じ形。
$baseSql =
    "SELECT s.login_id, s.student_name, s.grade, c.classroom_name, s.is_active, s.created_at,
            s.target_private_id, tpv.name AS target_private_name,
            s.target_public_id,  tpb.name AS target_public_name,
            (SELECT g.login_id FROM guardian_students gs
               JOIN guardians g ON g.guardian_id = gs.guardian_id
              WHERE gs.student_id = s.student_id
              ORDER BY g.guardian_id LIMIT 1) AS guardian_login_id,
            (SELECT GROUP_CONCAT(CONCAT(s2.login_id, ' ', s2.student_name)
                      ORDER BY s2.login_id SEPARATOR '、')
               FROM guardian_students gs1
               JOIN guardian_students gs2 ON gs2.guardian_id = gs1.guardian_id
               JOIN students s2 ON s2.student_id = gs2.student_id
              WHERE gs1.student_id = s.student_id
                AND s2.student_id <> s.student_id) AS siblings
     FROM students s
     JOIN classrooms c ON c.classroom_id = s.classroom_id
     LEFT JOIN target_schools tpv ON tpv.target_school_id = s.target_private_id
     LEFT JOIN target_schools tpb ON tpb.target_school_id = s.target_public_id";

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
