<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 学習時間の積算用ハートビート。divp-core.js がタブ表示中に1分ごとに叩く。
// ended_at は「最後に活動した時刻」として使い、前回活動からの経過を
// 5分上限で duration_sec に加算する（放置時間を学習時間に数えないため）
require_post();
$actor = require_login(['student']);

$input = json_input();
$sessionId = isset($input['session_id']) ? (int)$input['session_id'] : 0;
if ($sessionId <= 0) {
    json_response(['ok' => false, 'error' => 'invalid_request'], 400);
}

$pdo = db();
$stmt = $pdo->prepare(
    'UPDATE study_sessions
     SET duration_sec = COALESCE(duration_sec, 0)
           + LEAST(TIMESTAMPDIFF(SECOND, COALESCE(ended_at, started_at), NOW()), 300),
         ended_at = NOW()
     WHERE session_id = :id AND student_id = :sid'
);
$stmt->execute(['id' => $sessionId, 'sid' => $actor['id']]);

json_response(['ok' => true]);
