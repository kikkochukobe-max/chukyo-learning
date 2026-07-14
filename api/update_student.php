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

// 志望校IDが有効か検証する。null/0/空は「未設定(=NULLにする)」、種別違い/存在しないは false（不正）。
function validate_target(PDO $pdo, $raw, string $kind)
{
    $id = (int)($raw ?? 0);
    if ($id <= 0) return null;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM target_schools WHERE target_school_id = :id AND kind = :kind');
    $stmt->execute(['id' => $id, 'kind' => $kind]);
    return (int)$stmt->fetchColumn() > 0 ? $id : false;
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

// 送られてきた項目だけ更新する（キーが無い項目は既存値を保持＝古いフォームとも互換）。
// student_name は必須なので常に更新対象。
$sets = ['student_name = :name'];
$params = ['name' => $studentName];

if (array_key_exists('grade', $input)) {
    $grade = trim((string)$input['grade']) ?: null;
    if ($grade !== null && mb_strlen($grade) > 10) {
        json_response(['ok' => false, 'error' => 'invalid_grade'], 400);
    }
    $sets[] = 'grade = :grade';
    $params['grade'] = $grade;
}
if (array_key_exists('target_private_id', $input)) {
    $tp = validate_target($pdo, $input['target_private_id'], 'private');
    if ($tp === false) {
        json_response(['ok' => false, 'error' => 'invalid_target_school'], 400);
    }
    $sets[] = 'target_private_id = :tp';
    $params['tp'] = $tp;
}
if (array_key_exists('target_public_id', $input)) {
    $tpub = validate_target($pdo, $input['target_public_id'], 'public');
    if ($tpub === false) {
        json_response(['ok' => false, 'error' => 'invalid_target_school'], 400);
    }
    $sets[] = 'target_public_id = :tpub';
    $params['tpub'] = $tpub;
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

$params['id'] = $student['student_id'];
$stmt = $pdo->prepare('UPDATE students SET ' . implode(', ', $sets) . ' WHERE student_id = :id');
$stmt->execute($params);

json_response(['ok' => true, 'login_id' => $loginId, 'student_name' => $studentName]);
