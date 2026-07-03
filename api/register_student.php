<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 生徒コード = [入塾年度下2桁][全教室通し連番4桁]。年度は常に実行時点の年で確定する
// （過去の生徒を遡って個別指定はしない。教室番号・学年はコードに含めない）
function next_student_login_id(PDO $pdo, string $yearPrefix): string
{
    $stmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING(login_id, 3, 4) AS UNSIGNED))
         FROM students
         WHERE login_id LIKE :prefix AND CHAR_LENGTH(login_id) = 6"
    );
    $stmt->execute(['prefix' => $yearPrefix . '____']);
    $max = (int)$stmt->fetchColumn();
    return $yearPrefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

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
$classroomId = isset($input['classroom_id']) ? (int)$input['classroom_id'] : 0;
$studentName = trim((string)($input['student_name'] ?? ''));
$grade = isset($input['grade']) ? trim((string)$input['grade']) : null;
$pin = (string)($input['pin'] ?? '');

if ($classroomId <= 0) {
    json_response(['ok' => false, 'error' => 'invalid_classroom_id'], 400);
}
if ($studentName === '' || mb_strlen($studentName) > 50) {
    json_response(['ok' => false, 'error' => 'invalid_student_name'], 400);
}
if ($grade !== null && mb_strlen($grade) > 10) {
    json_response(['ok' => false, 'error' => 'invalid_grade'], 400);
}
if (!preg_match('/^\d{4}$/', $pin)) {
    json_response(['ok' => false, 'error' => 'invalid_pin'], 400);
}

if ($requesterRole === 'classroom_admin') {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM teacher_classrooms WHERE teacher_id = :tid AND classroom_id = :cid');
    $stmt->execute(['tid' => $actor['id'], 'cid' => $classroomId]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_response(['ok' => false, 'error' => 'forbidden_classroom'], 403);
    }
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM classrooms WHERE classroom_id = :id');
$stmt->execute(['id' => $classroomId]);
if ((int)$stmt->fetchColumn() === 0) {
    json_response(['ok' => false, 'error' => 'invalid_classroom_id'], 400);
}

$yearPrefix = date('y');
$passwordHash = password_hash($pin, PASSWORD_DEFAULT);

// 同時登録で連番が衝突した場合はUNIQUE制約違反(23000)を捕まえて採番し直す
for ($attempt = 0; $attempt < 5; $attempt++) {
    $loginId = next_student_login_id($pdo, $yearPrefix);
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO students (classroom_id, login_id, password_hash, student_name, grade, created_by)
             VALUES (:classroom_id, :login_id, :password_hash, :student_name, :grade, :created_by)'
        );
        $stmt->execute([
            'classroom_id'  => $classroomId,
            'login_id'      => $loginId,
            'password_hash' => $passwordHash,
            'student_name'  => $studentName,
            'grade'         => $grade,
            'created_by'    => $actor['id'],
        ]);
        json_response([
            'ok'         => true,
            'student_id' => (int)$pdo->lastInsertId(),
            'login_id'   => $loginId,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000' || $attempt === 4) {
            throw $e;
        }
        // 次のループで next_student_login_id() が繰り上がった連番を再取得する
    }
}
