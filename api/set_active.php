<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// アカウントの無効化/有効化（削除の代わり。物理削除はしない=学習記録を守る）
// kind: student / guardian / teacher, login_id, active: true(有効に戻す) / false(無効にする)
// 権限: 生徒・保護者=super_admin または担当教室のclassroom_admin / 講師=super_adminのみ(自分自身は不可)

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
$kind = (string)($input['kind'] ?? '');
$loginId = trim((string)($input['login_id'] ?? ''));
$active = (int)(bool)($input['active'] ?? false);

if (!in_array($kind, ['student', 'guardian', 'teacher'], true)) {
    json_response(['ok' => false, 'error' => 'invalid_kind'], 400);
}
if ($loginId === '') {
    json_response(['ok' => false, 'error' => 'invalid_login_id'], 400);
}

if ($kind === 'student') {
    $stmt = $pdo->prepare('SELECT student_id, classroom_id FROM students WHERE login_id = :login_id');
    $stmt->execute(['login_id' => $loginId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'student_not_found'], 404);
    }
    if ($requesterRole === 'classroom_admin') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM teacher_classrooms WHERE teacher_id = :tid AND classroom_id = :cid');
        $stmt->execute(['tid' => $actor['id'], 'cid' => $row['classroom_id']]);
        if ((int)$stmt->fetchColumn() === 0) {
            json_response(['ok' => false, 'error' => 'forbidden_classroom'], 403);
        }
    }
    $stmt = $pdo->prepare('UPDATE students SET is_active = :a WHERE student_id = :id');
    $stmt->execute(['a' => $active, 'id' => $row['student_id']]);

} elseif ($kind === 'guardian') {
    $stmt = $pdo->prepare('SELECT guardian_id FROM guardians WHERE login_id = :login_id');
    $stmt->execute(['login_id' => $loginId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'guardian_not_found'], 404);
    }
    if ($requesterRole === 'classroom_admin') {
        // 担当教室の生徒に紐づく保護者のみ操作可（一覧に見える範囲と同じ）
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM guardian_students gs
             JOIN students s ON s.student_id = gs.student_id
             WHERE gs.guardian_id = :gid
               AND s.classroom_id IN (SELECT classroom_id FROM teacher_classrooms WHERE teacher_id = :tid)'
        );
        $stmt->execute(['gid' => $row['guardian_id'], 'tid' => $actor['id']]);
        if ((int)$stmt->fetchColumn() === 0) {
            json_response(['ok' => false, 'error' => 'forbidden_classroom'], 403);
        }
    }
    $stmt = $pdo->prepare('UPDATE guardians SET is_active = :a WHERE guardian_id = :id');
    $stmt->execute(['a' => $active, 'id' => $row['guardian_id']]);

} else { // teacher
    if ($requesterRole !== 'super_admin') {
        json_response(['ok' => false, 'error' => 'forbidden'], 403);
    }
    $stmt = $pdo->prepare('SELECT teacher_id FROM teachers WHERE login_id = :login_id');
    $stmt->execute(['login_id' => $loginId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'teacher_not_found'], 404);
    }
    if ((int)$row['teacher_id'] === (int)$actor['id']) {
        json_response(['ok' => false, 'error' => 'cannot_self'], 400);
    }
    $stmt = $pdo->prepare('UPDATE teachers SET is_active = :a WHERE teacher_id = :id');
    $stmt->execute(['a' => $active, 'id' => $row['teacher_id']]);
}

json_response(['ok' => true, 'kind' => $kind, 'login_id' => $loginId, 'active' => (bool)$active]);
