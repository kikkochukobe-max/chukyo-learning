<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_post();
start_secure_session();

$input = json_input();
$actorType = (string)($input['actor_type'] ?? '');
$loginId = trim((string)($input['login_id'] ?? ''));
$password = (string)($input['password'] ?? '');

$tables = [
    'student'  => ['table' => 'students', 'id' => 'student_id', 'name' => 'student_name'],
    'teacher'  => ['table' => 'teachers', 'id' => 'teacher_id', 'name' => 'teacher_name'],
    'guardian' => ['table' => 'guardians', 'id' => 'guardian_id', 'name' => 'guardian_name'],
];

if (!isset($tables[$actorType]) || $loginId === '' || $password === '') {
    json_response(['ok' => false, 'error' => 'invalid_request'], 400);
}

$t = $tables[$actorType];
$pdo = db();
$stmt = $pdo->prepare(
    "SELECT * FROM {$t['table']} WHERE login_id = :login_id AND is_active = 1 LIMIT 1"
);
$stmt->execute(['login_id' => $loginId]);
$row = $stmt->fetch();

// PIN試行制限: 同一アカウントで直近10分に5回失敗していたらロック
// （4桁PIN=1万通りの総当たり対策。判定はパスワード照合より先に行う）
if ($row) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_logs
         WHERE actor_type = :actor_type AND actor_id = :actor_id
           AND success = 0 AND logged_in_at > (NOW() - INTERVAL 10 MINUTE)'
    );
    $stmt->execute(['actor_type' => $actorType, 'actor_id' => (int)$row[$t['id']]]);
    if ((int)$stmt->fetchColumn() >= 5) {
        json_response(['ok' => false, 'error' => 'locked'], 429);
    }
}

if (!$row || !password_verify($password, $row['password_hash'])) {
    // 失敗も login_logs に記録する（存在しないIDの場合は記録先が無いのでスキップ）
    if ($row) {
        $stmt = $pdo->prepare(
            'INSERT INTO login_logs (actor_type, actor_id, ip, user_agent, success)
             VALUES (:actor_type, :actor_id, :ip, :ua, 0)'
        );
        $stmt->execute([
            'actor_type' => $actorType,
            'actor_id'   => (int)$row[$t['id']],
            'ip'         => client_ip(),
            'ua'         => client_user_agent(),
        ]);
    }
    json_response(['ok' => false, 'error' => 'invalid_credentials'], 401);
}

session_regenerate_id(true);
$_SESSION['actor_type'] = $actorType;
$_SESSION['actor_id'] = (int)$row[$t['id']];
$_SESSION['actor_name'] = $row[$t['name']];

$deviceId = device_id($pdo);

// 生徒のみ自動ログイン用トークンを発行（同じブラウザなら180日ログイン不要）
if ($actorType === 'student') {
    issue_remember_token($pdo, 'student', (int)$row[$t['id']], $deviceId);
}

$logStmt = $pdo->prepare(
    'INSERT INTO login_logs (actor_type, actor_id, device_id, ip, user_agent)
     VALUES (:actor_type, :actor_id, :device_id, :ip, :ua)'
);
$logStmt->execute([
    'actor_type' => $actorType,
    'actor_id'   => (int)$row[$t['id']],
    'device_id'  => $deviceId,
    'ip'         => client_ip(),
    'ua'         => client_user_agent(),
]);

$actor = [
    'type' => $actorType,
    'id'   => (int)$row[$t['id']],
    'name' => $row[$t['name']],
];
if ($actorType === 'teacher') {
    $actor['role'] = $row['role'];
    $actor['must_change_password'] = (bool)$row['must_change_password'];
}
if ($actorType === 'student') {
    $actor['classroom_id'] = (int)$row['classroom_id'];
}

json_response(['ok' => true, 'actor' => $actor]);
