<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_post();
$actor = require_login(['student']);

$input = json_input();
$unitKey = (string)($input['unit_key'] ?? '');

if (!preg_match('/^[a-z0-9_]{1,64}$/i', $unitKey)) {
    json_response(['ok' => false, 'error' => 'invalid_unit_key'], 400);
}

$pdo = db();
$deviceId = device_id($pdo);

$stmt = $pdo->prepare(
    'INSERT INTO study_sessions (student_id, unit_key, device_id, ip, user_agent)
     VALUES (:student_id, :unit_key, :device_id, :ip, :ua)'
);
$stmt->execute([
    'student_id' => $actor['id'],
    'unit_key'   => $unitKey,
    'device_id'  => $deviceId,
    'ip'         => client_ip(),
    'ua'         => client_user_agent(),
]);

json_response(['ok' => true, 'session_id' => (int)$pdo->lastInsertId()]);
