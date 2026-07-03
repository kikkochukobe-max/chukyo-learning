<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_post();
$actor = require_login(['student']);

$input = json_input();
$sessionId = isset($input['session_id']) ? (int)$input['session_id'] : 0;
if ($sessionId <= 0) {
    json_response(['ok' => false, 'error' => 'invalid_request'], 400);
}

$pdo = db();

// ended_at は「最後に活動した時刻」の意味で使う（NULLチェックはしない）
$stmt = $pdo->prepare('SELECT session_id FROM study_sessions WHERE session_id = :id AND student_id = :sid');
$stmt->execute(['id' => $sessionId, 'sid' => $actor['id']]);
if (!$stmt->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'session_not_found'], 404);
}

// 増分更新のズレを避けるため、確定時に answer_logs から集計し直す
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS total, COALESCE(SUM(is_correct), 0) AS correct
     FROM answer_logs WHERE session_id = :id'
);
$stmt->execute(['id' => $sessionId]);
$counts = $stmt->fetch();

// 学習時間は壁時計ではなく活動ベースの積算（放置時間を含めない。最終区間も5分上限）
$stmt = $pdo->prepare(
    'UPDATE study_sessions
     SET duration_sec = COALESCE(duration_sec, 0)
           + LEAST(TIMESTAMPDIFF(SECOND, COALESCE(ended_at, started_at), NOW()), 300),
         ended_at = NOW(),
         total_questions = :total,
         correct_count = :correct
     WHERE session_id = :id'
);
$stmt->execute([
    'total'   => (int)$counts['total'],
    'correct' => (int)$counts['correct'],
    'id'      => $sessionId,
]);

$stmt = $pdo->prepare('SELECT duration_sec, total_questions, correct_count FROM study_sessions WHERE session_id = :id');
$stmt->execute(['id' => $sessionId]);
$result = $stmt->fetch();

json_response([
    'ok'              => true,
    'session_id'      => $sessionId,
    'duration_sec'    => (int)$result['duration_sec'],
    'total_questions' => (int)$result['total_questions'],
    'correct_count'   => (int)$result['correct_count'],
]);
