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
$studentCodes = is_array($input['student_codes'] ?? null)
    ? array_values(array_filter(array_map(fn($v) => trim((string)$v), $input['student_codes']), fn($v) => $v !== ''))
    : [];

if (count($studentCodes) === 0) {
    json_response(['ok' => false, 'error' => 'student_codes_required'], 400);
}

// 保護者は自前のパスワードを持たず「お子さまの生徒PIN」でログインする。
// guardians.password_hash は使わない（NOT NULL を満たすためだけの未使用ダミー値）。
$guardianDummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

// 保護者ログインIDは「代表のお子さま（最初に指定した生徒）の生徒コード」に g を付けて自動採番。
// 例: 260038 → g260038。兄弟は下の guardian_students で複数ひもづける。
// 同じ代表の子で二重登録すると login_id が重複し 409 になる（＝すでに登録済み）。
$loginId = 'g' . $studentCodes[0];

// 生徒コード→student_id を解決。存在しないコードがあれば登録しない
$studentIds = [];
$children = [];  // 「コード 氏名」の一覧（案内文用に返す）
$repName = '';   // 代表の子の氏名（保護者の表示名の元）
$stmt = $pdo->prepare('SELECT student_id, student_name, classroom_id FROM students WHERE login_id = :login_id AND is_active = 1');
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
    if ($repName === '') {
        $repName = $row['student_name'];
    }
    $studentIds[] = (int)$row['student_id'];
    $children[] = $code . ' ' . $row['student_name'];
}

// 保護者氏名は登録せず「代表の子の生徒名＋保護者様」を自動設定する（登録時点のスナップショット）
$guardianName = mb_substr($repName . ' 保護者様', 0, 50);

$pdo->beginTransaction();
try {
    // must_change_password は列に触れない（別マイグレーションで後付けの列＝未適用環境では
    // 存在しない可能性があるため。保護者では未使用なのでDEFAULTのままでよい）。
    $stmt = $pdo->prepare(
        'INSERT INTO guardians (login_id, password_hash, guardian_name)
         VALUES (:login_id, :password_hash, :guardian_name)'
    );
    $stmt->execute([
        'login_id'      => $loginId,
        'password_hash' => $guardianDummyHash,
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
    'ok'            => true,
    'guardian_id'   => $guardianId,
    'login_id'      => $loginId,
    'guardian_name' => $guardianName,
    'children'      => implode('、', $children),
    'linked'        => count($studentIds),
]);
