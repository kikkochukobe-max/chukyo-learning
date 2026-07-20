<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// タイムアタックのクリアタイムを1プレイ=1行で保存する。
// answer_logs には残さない（種類別集計・XPを汚さない）。
// 併せて「自己ベスト更新か」をサーバー側で判定して返す。

require_post();
$actor = require_login(['student']);
$studentId = $actor['id'];

$input = json_input();
$unitKey = (string)($input['unit_key'] ?? '');
$questionKey = (string)($input['question_key'] ?? 'default');
if ($questionKey === '') {
    $questionKey = 'default';
}

if (!preg_match('/^[a-z0-9_]{1,64}$/i', $unitKey) || !preg_match('/^[a-zA-Z0-9_]{1,128}$/', $questionKey)) {
    json_response(['ok' => false, 'error' => 'invalid_request'], 400);
}

$timeMs = isset($input['time_ms']) ? (int)$input['time_ms'] : 0;
// 妥当な範囲だけ受ける（0以下・24時間超は不正扱い）
if ($timeMs <= 0 || $timeMs > 86400000) {
    json_response(['ok' => false, 'error' => 'invalid_time'], 400);
}
$missCount = isset($input['miss_count']) ? max(0, (int)$input['miss_count']) : 0;
$meta = $input['meta'] ?? null;
if ($meta !== null && !is_array($meta)) {
    json_response(['ok' => false, 'error' => 'invalid_request'], 400);
}

$pdo = db();
$deviceId = device_id($pdo);

// session_id は必ず本人所有かを確認してから使う（他人のセッションへの書き込み防止）
$sessionId = null;
if (!empty($input['session_id'])) {
    $stmt = $pdo->prepare('SELECT session_id FROM study_sessions WHERE session_id = :id AND student_id = :sid');
    $stmt->execute(['id' => (int)$input['session_id'], 'sid' => $studentId]);
    if ($stmt->fetchColumn()) {
        $sessionId = (int)$input['session_id'];
    }
}

$pdo->beginTransaction();
try {
    // 保存前のベスト（自己ベスト更新の判定に使う）
    $stmt = $pdo->prepare(
        'SELECT MIN(time_ms) FROM time_records
         WHERE student_id = :sid AND unit_key = :uk AND question_key = :qk'
    );
    $stmt->execute(['sid' => $studentId, 'uk' => $unitKey, 'qk' => $questionKey]);
    $prevBestRaw = $stmt->fetchColumn();
    $prevBest = $prevBestRaw !== null && $prevBestRaw !== false ? (int)$prevBestRaw : null;

    $stmt = $pdo->prepare(
        'INSERT INTO time_records
            (student_id, unit_key, question_key, time_ms, miss_count, meta, session_id, device_id)
         VALUES
            (:sid, :uk, :qk, :time_ms, :miss, :meta, :session_id, :device_id)'
    );
    $stmt->execute([
        'sid'        => $studentId,
        'uk'         => $unitKey,
        'qk'         => $questionKey,
        'time_ms'    => $timeMs,
        'miss'       => $missCount,
        'meta'       => $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        'session_id' => $sessionId,
        'device_id'  => $deviceId,
    ]);
    $recordId = (int)$pdo->lastInsertId();

    // 学習時間は活動ベースで積算（1問ごとのログは残さないが、遊んだ時間は記録する）。
    // save_answer.php と同じく「前回活動からの経過(上限5分)」を duration_sec に足す。
    if ($sessionId !== null) {
        $stmt = $pdo->prepare(
            'UPDATE study_sessions
             SET duration_sec = COALESCE(duration_sec, 0)
                   + LEAST(TIMESTAMPDIFF(SECOND, COALESCE(ended_at, started_at), NOW()), 300),
                 ended_at = NOW()
             WHERE session_id = :id'
        );
        $stmt->execute(['id' => $sessionId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

$isFirst = $prevBest === null;
$isBest  = !$isFirst && $timeMs < $prevBest;   // 過去ベストを更新した時だけ「新記録」
$bestMs  = $isFirst ? $timeMs : min($timeMs, $prevBest);

json_response([
    'ok'           => true,
    'record_id'    => $recordId,
    'is_first'     => $isFirst,
    'is_best'      => $isBest,
    'prev_best_ms' => $prevBest,
    'best_ms'      => $bestMs,
]);
