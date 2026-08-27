<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/self_study_common.php';

// 自習記録の削除（書きまちがい・重複の取り消し用）。
//  生徒: 自分の記録で、まだ確認印が押されていないものだけ
//  講師: 担当教室の生徒の記録なら確認済みでも消せる（重複入力の掃除用）

require_post();
$actor = require_login(['student', 'teacher']);

$input = json_input();
$logId = isset($input['log_id']) ? (int)$input['log_id'] : 0;
if ($logId <= 0) {
    json_response(['ok' => false, 'error' => 'invalid_request'], 400);
}

$pdo = db();

$stmt = $pdo->prepare('SELECT student_id, checked_at FROM self_study_logs WHERE log_id = :id');
$stmt->execute(['id' => $logId]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['ok' => false, 'error' => 'not_found'], 404);
}

if ($actor['type'] === 'student') {
    if ((int)$row['student_id'] !== (int)$actor['id']) {
        json_response(['ok' => false, 'error' => 'forbidden'], 403);
    }
    if ($row['checked_at'] !== null) {
        json_response(['ok' => false, 'error' => 'already_checked'], 409);
    }
} else {
    self_study_require_teacher_access($pdo, (int)$actor['id'], (int)$row['student_id']);
}

$pdo->prepare('DELETE FROM self_study_logs WHERE log_id = :id')->execute(['id' => $logId]);

json_response(['ok' => true, 'log_id' => $logId]);
