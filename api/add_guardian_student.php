<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 兄弟・姉妹の後付け紐づけ。下の子が後から入塾したケース用に、
// 既存の保護者アカウントへ生徒を追加でひもづける（保護者ID・PINは変わらない）。
// 保護者の指定は「保護者ID(g〜)」または「登録済みのお子さまの生徒コード」のどちらでも可。
// すでに紐づいている生徒は already に入れて返すだけでエラーにはしない。
// 別々に登録されていた兄弟の統合にも対応: 追加する子が別の保護者アカウントに
// 紐づいている場合は needs_move(409) を返し、move=true で再送されたら古い紐づけを
// 外して付け替える（お子さまがいなくなった古い保護者アカウントは自動で無効化）。
// 権限: super_admin=無条件 / classroom_admin=追加する生徒が担当教室の生徒である場合のみ

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
$guardianKey = trim((string)($input['guardian_key'] ?? ''));
$move = (bool)($input['move'] ?? false);   // true=別の保護者からの付け替えを許可
$studentCodes = is_array($input['student_codes'] ?? null)
    ? array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v), $input['student_codes']), fn($v) => $v !== '')))
    : [];

if ($guardianKey === '') {
    json_response(['ok' => false, 'error' => 'guardian_key_required'], 400);
}
if (count($studentCodes) === 0) {
    json_response(['ok' => false, 'error' => 'student_codes_required'], 400);
}

// 保護者を解決。g始まりなら保護者IDとして直接、数字だけなら
// 「その子に紐づく保護者」を逆引きする（複数いる場合はID指定を求める）
if ($guardianKey[0] === 'g') {
    $stmt = $pdo->prepare(
        'SELECT guardian_id, login_id, guardian_name FROM guardians
         WHERE login_id = :id AND is_active = 1'
    );
    $stmt->execute(['id' => $guardianKey]);
    $guardian = $stmt->fetch();
} else {
    $stmt = $pdo->prepare(
        'SELECT g.guardian_id, g.login_id, g.guardian_name
         FROM guardians g
         JOIN guardian_students gs ON gs.guardian_id = g.guardian_id
         JOIN students s ON s.student_id = gs.student_id
         WHERE s.login_id = :code AND g.is_active = 1'
    );
    $stmt->execute(['code' => $guardianKey]);
    $found = $stmt->fetchAll();
    if (count($found) > 1) {
        json_response(['ok' => false, 'error' => 'guardian_ambiguous'], 400);
    }
    $guardian = $found[0] ?? false;
}
if (!$guardian) {
    json_response(['ok' => false, 'error' => 'guardian_not_found'], 404);
}
$guardianId = (int)$guardian['guardian_id'];

// 追加する生徒を解決（存在・権限・重複紐づけを確認）
$findStudent = $pdo->prepare(
    'SELECT student_id, student_name, classroom_id FROM students
     WHERE login_id = :login_id AND is_active = 1'
);
$checkClassroom = $pdo->prepare(
    'SELECT COUNT(*) FROM teacher_classrooms WHERE teacher_id = :tid AND classroom_id = :cid'
);
$checkLink = $pdo->prepare(
    'SELECT COUNT(*) FROM guardian_students WHERE guardian_id = :gid AND student_id = :sid'
);
$findOthers = $pdo->prepare(
    'SELECT g.guardian_id, g.login_id FROM guardian_students gs
     JOIN guardians g ON g.guardian_id = gs.guardian_id
     WHERE gs.student_id = :sid AND gs.guardian_id <> :gid'
);

$toAdd = [];
$already = [];
$conflicts = [];        // 別の保護者に紐づいている子（move=false のときは確認を求める）
$oldGuardianIds = [];   // 付け替えで紐づけを外す元の保護者ID
foreach ($studentCodes as $code) {
    $findStudent->execute(['login_id' => $code]);
    $s = $findStudent->fetch();
    if (!$s) {
        json_response(['ok' => false, 'error' => 'student_not_found', 'student_code' => $code], 400);
    }
    if ($requesterRole === 'classroom_admin') {
        $checkClassroom->execute(['tid' => $actor['id'], 'cid' => (int)$s['classroom_id']]);
        if ((int)$checkClassroom->fetchColumn() === 0) {
            json_response(['ok' => false, 'error' => 'forbidden_classroom', 'student_code' => $code], 403);
        }
    }
    $checkLink->execute(['gid' => $guardianId, 'sid' => (int)$s['student_id']]);
    if ((int)$checkLink->fetchColumn() > 0) {
        $already[] = $code . ' ' . $s['student_name'];
        continue;
    }
    $findOthers->execute(['sid' => (int)$s['student_id'], 'gid' => $guardianId]);
    $others = $findOthers->fetchAll();
    if (count($others) > 0) {
        $conflicts[] = [
            'student_code' => $code,
            'student_name' => $s['student_name'],
            'guardians'    => array_map(fn($o) => $o['login_id'], $others),
        ];
        foreach ($others as $o) {
            $oldGuardianIds[(int)$o['guardian_id']] = $o['login_id'];
        }
    }
    $toAdd[] = ['code' => $code, 'student_id' => (int)$s['student_id'], 'student_name' => $s['student_name']];
}

// 付け替えは確認済み(move=true)のときだけ行う。未確認なら内容を返して画面側で確認させる
if (count($conflicts) > 0 && !$move) {
    json_response(['ok' => false, 'error' => 'needs_move', 'conflicts' => $conflicts], 409);
}

$deactivated = [];
if (count($toAdd) > 0) {
    $insLink  = $pdo->prepare('INSERT INTO guardian_students (guardian_id, student_id) VALUES (:gid, :sid)');
    $delLink  = $pdo->prepare('DELETE FROM guardian_students WHERE student_id = :sid AND guardian_id <> :gid');
    $countKid = $pdo->prepare('SELECT COUNT(*) FROM guardian_students WHERE guardian_id = :gid');
    $pdo->beginTransaction();
    try {
        foreach ($toAdd as $s) {
            if ($move) {
                $delLink->execute(['sid' => $s['student_id'], 'gid' => $guardianId]);
            }
            $insLink->execute(['gid' => $guardianId, 'sid' => $s['student_id']]);
        }
        // お子さまがいなくなった元の保護者アカウントは無効化する（家庭の二重アカウント防止）
        foreach ($oldGuardianIds as $oldId => $oldLoginId) {
            $countKid->execute(['gid' => $oldId]);
            if ((int)$countKid->fetchColumn() === 0) {
                $pdo->prepare('UPDATE guardians SET is_active = 0 WHERE guardian_id = :gid')
                    ->execute(['gid' => $oldId]);
                $deactivated[] = $oldLoginId;
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
}

json_response([
    'ok'            => true,
    'login_id'      => $guardian['login_id'],
    'guardian_name' => $guardian['guardian_name'],
    'added'         => array_map(fn($s) => $s['code'] . ' ' . $s['student_name'], $toAdd),
    'already'       => $already,
    'moved'         => array_map(fn($c) => $c['student_code'] . ' ' . $c['student_name'], $conflicts),
    'deactivated'   => $deactivated,
]);
