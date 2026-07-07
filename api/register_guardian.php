<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 保護者登録 + 生徒(兄弟可)との紐づけを一括で行う。
// 保護者専用ログインは後日リリースだが、器(アカウントと紐づけ)は先行して作れる。
// 権限: super_admin=無条件 / classroom_admin=紐づける生徒全員が担当教室の生徒である場合のみ

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
$pin = (string)($input['pin'] ?? '');
$guardianName = trim((string)($input['guardian_name'] ?? ''));
$studentCodes = is_array($input['student_codes'] ?? null)
    ? array_values(array_filter(array_map(fn($v) => trim((string)$v), $input['student_codes']), fn($v) => $v !== ''))
    : [];

// 保護者PINは生徒と同じ4桁数字（テンキー完結）
if (!preg_match('/^\d{4}$/', $pin)) {
    json_response(['ok' => false, 'error' => 'invalid_pin'], 400);
}
if ($guardianName === '' || mb_strlen($guardianName) > 50) {
    json_response(['ok' => false, 'error' => 'invalid_guardian_name'], 400);
}
if (count($studentCodes) === 0) {
    json_response(['ok' => false, 'error' => 'student_codes_required'], 400);
}

// 保護者ログインIDは「代表のお子さま（最初に指定した生徒）の生徒コード」に g を付けて自動採番。
// 例: 260038 → g260038。兄弟は下の guardian_students で複数ひもづける。
// 同じ代表の子で二重登録すると login_id が重複し 409 になる（＝すでに登録済み）。
$loginId = 'g' . $studentCodes[0];

// 生徒コード→student_id を解決。存在しないコードがあれば登録しない
$studentIds = [];
$stmt = $pdo->prepare('SELECT student_id, classroom_id FROM students WHERE login_id = :login_id AND is_active = 1');
$check = $pdo->prepare('SELECT COUNT(*) FROM teacher_classrooms WHERE teacher_id = :tid AND classroom_id = :cid');
foreach (array_unique($studentCodes) as $code) {
    $stmt->execute(['login_id' => $code]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'student_not_found', 'student_code' => $code], 400);
    }
    if ($requesterRole === 'classroom_admin') {
        $check->execute(['tid' => $actor['id'], 'cid' => (int)$row['classroom_id']]);
        if ((int)$check->fetchColumn() === 0) {
            json_response(['ok' => false, 'error' => 'forbidden_classroom', 'student_code' => $code], 403);
        }
    }
    $studentIds[] = (int)$row['student_id'];
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO guardians (login_id, password_hash, guardian_name)
         VALUES (:login_id, :password_hash, :guardian_name)'
    );
    $stmt->execute([
        'login_id'      => $loginId,
        'password_hash' => password_hash($pin, PASSWORD_DEFAULT),
        'guardian_name' => $guardianName,
    ]);
    $guardianId = (int)$pdo->lastInsertId();

    $link = $pdo->prepare('INSERT INTO guardian_students (guardian_id, student_id) VALUES (:gid, :sid)');
    foreach ($studentIds as $sid) {
        $link->execute(['gid' => $guardianId, 'sid' => $sid]);
    }
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '23000') {
        json_response(['ok' => false, 'error' => 'duplicate_login_id'], 409);
    }
    throw $e;
}

json_response([
    'ok'          => true,
    'guardian_id' => $guardianId,
    'login_id'    => $loginId,
    'linked'      => count($studentIds),
]);
