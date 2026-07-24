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

// 志望校IDが有効か検証する。null/0/空は「未設定」として null を返す。
// 指定があるのに存在しない or 種別違いなら false（=不正）を返す。
function validate_target(PDO $pdo, $raw, string $kind)
{
    $id = (int)($raw ?? 0);
    if ($id <= 0) return null;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM target_schools WHERE target_school_id = :id AND kind = :kind');
    $stmt->execute(['id' => $id, 'kind' => $kind]);
    return (int)$stmt->fetchColumn() > 0 ? $id : false;
}

$input = json_input();
$classroomId = isset($input['classroom_id']) ? (int)$input['classroom_id'] : 0;
$studentName = trim((string)($input['student_name'] ?? ''));
$grade = isset($input['grade']) ? trim((string)$input['grade']) : null;
$pin = (string)($input['pin'] ?? '');
$targetPrivate = validate_target($pdo, $input['target_private_id'] ?? null, 'private');
$targetPublic  = validate_target($pdo, $input['target_public_id'] ?? null, 'public');

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
if ($targetPrivate === false || $targetPublic === false) {
    json_response(['ok' => false, 'error' => 'invalid_target_school'], 400);
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

// 同時登録で連番が衝突した場合はUNIQUE制約違反(23000)を捕まえて採番し直す。
// 保護者アカウント（ID=g+生徒コード / 表示名=生徒名+保護者様）も同じトランザクションで
// 自動発行する。兄弟が後から入塾した場合は自動発行されたものを admin.php の
// 「兄弟・姉妹の追加」で上の子の保護者へ付け替えて統合する。
// 保護者は自前のパスワードを持たず「お子さまの生徒PIN」でログインするため、
// guardians.password_hash は使わない（NOT NULL を満たすためだけの未使用ダミー値を入れる）。
$guardianDummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
for ($attempt = 0; $attempt < 5; $attempt++) {
    $loginId = next_student_login_id($pdo, $yearPrefix);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO students (classroom_id, login_id, password_hash, student_name, grade, target_private_id, target_public_id, created_by)
             VALUES (:classroom_id, :login_id, :password_hash, :student_name, :grade, :tp, :tpub, :created_by)'
        );
        $stmt->execute([
            'classroom_id'  => $classroomId,
            'login_id'      => $loginId,
            'password_hash' => $passwordHash,
            'student_name'  => $studentName,
            'grade'         => $grade,
            'tp'            => $targetPrivate,
            'tpub'          => $targetPublic,
            'created_by'    => $actor['id'],
        ]);
        $studentId = (int)$pdo->lastInsertId();

        // must_change_password は列に触れない（別マイグレーションで後付けの列＝未適用環境では
        // 存在しない可能性があるため。保護者では未使用なのでDEFAULTのままでよい）。
        $stmt = $pdo->prepare(
            'INSERT INTO guardians (login_id, password_hash, guardian_name)
             VALUES (:login_id, :password_hash, :guardian_name)'
        );
        $stmt->execute([
            'login_id'      => 'g' . $loginId,
            'password_hash' => $guardianDummyHash,
            'guardian_name' => mb_substr($studentName . ' 保護者様', 0, 50),
        ]);
        $guardianId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('INSERT INTO guardian_students (guardian_id, student_id) VALUES (:gid, :sid)');
        $stmt->execute(['gid' => $guardianId, 'sid' => $studentId]);

        $pdo->commit();
        json_response([
            'ok'                => true,
            'student_id'        => $studentId,
            'login_id'          => $loginId,
            'guardian_login_id' => 'g' . $loginId,
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() !== '23000' || $attempt === 4) {
            throw $e;
        }
        // 次のループで next_student_login_id() が繰り上がった連番を再取得する
    }
}
