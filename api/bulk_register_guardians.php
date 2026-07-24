<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 保護者の一括登録。1行=1家庭（兄弟は同じ行にまとめる。行の先頭の生徒コードが代表）。
// ログインIDは「代表のお子さまの生徒コード」に g を付けて自動採番する。
// 保護者は自前のパスワードを持たず「お子さまの生徒PIN」でログインするため、
// guardians.password_hash は使わない（NOT NULL を満たすためだけの未使用ダミー値）。
// 行ごとに独立して登録するため、失敗行があっても他の行は登録される。
// 兄弟のどれかが既に保護者に紐づいている行は already_has_guardian で弾く
// （代表の子が違うと login_id の重複では検知できない家庭の二重登録を防ぐ。
//   下の子の後付けは add_guardian_student.php を使う）。
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
$rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
if (count($rows) === 0) {
    json_response(['ok' => false, 'error' => 'rows_required'], 400);
}
if (count($rows) > 300) {
    json_response(['ok' => false, 'error' => 'too_many_rows'], 400);
}

$findStudent = $pdo->prepare(
    'SELECT s.student_id, s.student_name, s.classroom_id, c.classroom_name
     FROM students s JOIN classrooms c ON c.classroom_id = s.classroom_id
     WHERE s.login_id = :login_id AND s.is_active = 1'
);
$checkClassroom = $pdo->prepare(
    'SELECT COUNT(*) FROM teacher_classrooms WHERE teacher_id = :tid AND classroom_id = :cid'
);
$checkLinked = $pdo->prepare(
    'SELECT g.login_id FROM guardian_students gs
     JOIN guardians g ON g.guardian_id = gs.guardian_id
     WHERE gs.student_id = :sid LIMIT 1'
);
// must_change_password は列に触れない（別マイグレーションで後付けの列＝未適用環境では
// 存在しない可能性があるため。保護者では未使用なのでDEFAULTのままでよい）。
$insGuardian = $pdo->prepare(
    'INSERT INTO guardians (login_id, password_hash, guardian_name)
     VALUES (:login_id, :password_hash, :guardian_name)'
);
$insLink = $pdo->prepare('INSERT INTO guardian_students (guardian_id, student_id) VALUES (:gid, :sid)');

$results = [];
$registered = 0;

foreach ($rows as $row) {
    $codes = is_array($row['student_codes'] ?? null)
        ? array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v), $row['student_codes']), fn($v) => $v !== '')))
        : [];

    $result = ['ok' => false, 'codes' => $codes];

    if (count($codes) === 0) {
        $results[] = array_merge($result, ['error' => 'student_codes_required']);
        continue;
    }

    // 生徒コード→student_id を解決しつつ、権限と既存保護者の有無を行単位で確認
    $students = [];
    $rowError = null;
    foreach ($codes as $code) {
        $findStudent->execute(['login_id' => $code]);
        $s = $findStudent->fetch();
        if (!$s) {
            $rowError = ['error' => 'student_not_found', 'student_code' => $code];
            break;
        }
        if ($requesterRole === 'classroom_admin') {
            $checkClassroom->execute(['tid' => $actor['id'], 'cid' => (int)$s['classroom_id']]);
            if ((int)$checkClassroom->fetchColumn() === 0) {
                $rowError = ['error' => 'forbidden_classroom', 'student_code' => $code];
                break;
            }
        }
        $checkLinked->execute(['sid' => (int)$s['student_id']]);
        $existing = $checkLinked->fetchColumn();
        if ($existing !== false) {
            $rowError = ['error' => 'already_has_guardian', 'student_code' => $code, 'guardian_login_id' => $existing];
            break;
        }
        $students[] = $s;
    }
    if ($rowError !== null) {
        $results[] = array_merge($result, $rowError);
        continue;
    }

    $loginId = 'g' . $codes[0];
    // 保護者氏名は登録せず「代表の子の生徒名＋保護者様」を自動設定する（登録時点のスナップショット）
    $guardianName = mb_substr($students[0]['student_name'] . ' 保護者様', 0, 50);
    // 保護者はお子さまの生徒PINでログインするため未使用のダミーhashを入れる（NOT NULL対策）
    $dummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

    $pdo->beginTransaction();
    try {
        $insGuardian->execute([
            'login_id'      => $loginId,
            'password_hash' => $dummyHash,
            'guardian_name' => $guardianName,
        ]);
        $guardianId = (int)$pdo->lastInsertId();
        foreach ($students as $s) {
            $insLink->execute(['gid' => $guardianId, 'sid' => (int)$s['student_id']]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() === '23000') {
            $results[] = array_merge($result, ['error' => 'duplicate_login_id']);
            continue;
        }
        throw $e;
    }

    $registered++;
    $children = [];
    foreach ($students as $i => $s) {
        $children[] = $codes[$i] . ' ' . $s['student_name'];
    }
    $results[] = [
        'ok'             => true,
        'codes'          => $codes,
        'login_id'       => $loginId,
        'guardian_name'  => $guardianName,
        'classroom_name' => $students[0]['classroom_name'],  // 代表の子の教室（配布先の目安）
        'children'       => implode('、', $children),
    ];
}

json_response([
    'ok'         => true,
    'registered' => $registered,
    'results'    => $results,
]);
