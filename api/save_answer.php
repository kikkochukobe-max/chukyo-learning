<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const DAILY_XP_CAP = 300;
const DEFAULT_BASE_XP = 1;   // question_catalog 未登録の (unit_key, question_key) に与える既定XP

// 同一question_keyの当日の解答回数に応じてXPを減衰させる（連打対策）
function xp_decay_factor(int $answeredTodayCount): float
{
    if ($answeredTodayCount <= 10) {
        return 1.0;
    }
    if ($answeredTodayCount <= 20) {
        return 0.5;
    }
    if ($answeredTodayCount <= 30) {
        return 0.25;
    }
    return 0.0;
}

require_post();
$actor = require_login(['student']);
$studentId = $actor['id'];

$input = json_input();
$unitKey = (string)($input['unit_key'] ?? '');
$questionKey = (string)($input['question_key'] ?? '');

if (!preg_match('/^[a-z0-9_]{1,64}$/i', $unitKey) || !preg_match('/^[a-zA-Z0-9_]{1,128}$/', $questionKey)) {
    json_response(['ok' => false, 'error' => 'invalid_request'], 400);
}
if (!array_key_exists('is_correct', $input)) {
    json_response(['ok' => false, 'error' => 'invalid_request'], 400);
}

$isCorrect = (bool)$input['is_correct'];
$questionParams = $input['question_params'] ?? null;
if ($questionParams !== null && !is_array($questionParams)) {
    json_response(['ok' => false, 'error' => 'invalid_request'], 400);
}
$questionText = isset($input['question_text']) ? substr((string)$input['question_text'], 0, 255) : null;
$correctAnswer = isset($input['correct_answer']) ? substr((string)$input['correct_answer'], 0, 100) : null;
$studentAnswer = isset($input['student_answer']) ? substr((string)$input['student_answer'], 0, 100) : null;
$retryOf = isset($input['retry_of']) ? (int)$input['retry_of'] : null;
$timeTakenSec = isset($input['time_taken_sec']) ? (int)$input['time_taken_sec'] : null;
$hash = params_hash($questionParams);

$pdo = db();

// クライアントの申告する session_id は必ず本人所有かを確認してから使う（他人のセッションへの書き込み防止）
// ※ended_at は「最後に活動した時刻」の意味で使う（NULLチェックはしない）
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
    $stmt = $pdo->prepare(
        'INSERT INTO answer_logs
            (session_id, student_id, unit_key, question_key, question_params, params_hash,
             question_text, correct_answer, student_answer, is_correct, retry_of, time_taken_sec)
         VALUES
            (:session_id, :student_id, :unit_key, :question_key, :question_params, :params_hash,
             :question_text, :correct_answer, :student_answer, :is_correct, :retry_of, :time_taken_sec)'
    );
    $stmt->execute([
        'session_id'      => $sessionId,
        'student_id'      => $studentId,
        'unit_key'        => $unitKey,
        'question_key'    => $questionKey,
        'question_params' => $questionParams !== null ? json_encode($questionParams, JSON_UNESCAPED_UNICODE) : null,
        'params_hash'     => $hash,
        'question_text'   => $questionText,
        'correct_answer'  => $correctAnswer,
        'student_answer'  => $studentAnswer,
        'is_correct'      => $isCorrect ? 1 : 0,
        'retry_of'        => $retryOf,
        'time_taken_sec'  => $timeTakenSec,
    ]);
    $answerId = (int)$pdo->lastInsertId();

    if ($sessionId !== null) {
        // 学習時間は活動ベースの積算: 前回活動からの経過(上限5分)を加算していく
        $stmt = $pdo->prepare(
            'UPDATE study_sessions
             SET total_questions = total_questions + 1,
                 correct_count = correct_count + :inc,
                 duration_sec = COALESCE(duration_sec, 0)
                   + LEAST(TIMESTAMPDIFF(SECOND, COALESCE(ended_at, started_at), NOW()), 300),
                 ended_at = NOW()
             WHERE session_id = :id'
        );
        $stmt->execute(['inc' => $isCorrect ? 1 : 0, 'id' => $sessionId]);
    }

    // retry_queue: 誤答はキュー投入/更新、正答は連続正解数を進めて2連続でmastered
    $retryStatus = 'none';
    if (!$isCorrect) {
        $stmt = $pdo->prepare(
            'INSERT INTO retry_queue (student_id, unit_key, question_key, question_params, params_hash, wrong_count, correct_streak, status, last_answered_at)
             VALUES (:student_id, :unit_key, :question_key, :question_params, :params_hash, 1, 0, "pending", NOW())
             ON DUPLICATE KEY UPDATE
                wrong_count = wrong_count + 1,
                correct_streak = 0,
                status = "pending",
                question_params = VALUES(question_params),
                last_answered_at = NOW()'
        );
        $stmt->execute([
            'student_id'      => $studentId,
            'unit_key'        => $unitKey,
            'question_key'    => $questionKey,
            'question_params' => $questionParams !== null ? json_encode($questionParams, JSON_UNESCAPED_UNICODE) : null,
            'params_hash'     => $hash,
        ]);
        $retryStatus = 'pending';
    } else {
        $stmt = $pdo->prepare(
            'SELECT retry_id, correct_streak, status FROM retry_queue
             WHERE student_id = :student_id AND unit_key = :unit_key AND question_key = :question_key AND params_hash = :params_hash
             FOR UPDATE'
        );
        $stmt->execute([
            'student_id'   => $studentId,
            'unit_key'     => $unitKey,
            'question_key' => $questionKey,
            'params_hash'  => $hash,
        ]);
        $queueRow = $stmt->fetch();
        if ($queueRow) {
            if ($queueRow['status'] === 'pending') {
                $newStreak = (int)$queueRow['correct_streak'] + 1;
                $newStatus = $newStreak >= 2 ? 'mastered' : 'pending';
                $stmt = $pdo->prepare(
                    'UPDATE retry_queue SET correct_streak = :streak, status = :status, last_answered_at = NOW() WHERE retry_id = :id'
                );
                $stmt->execute(['streak' => $newStreak, 'status' => $newStatus, 'id' => $queueRow['retry_id']]);
                $retryStatus = $newStatus;
            } else {
                $retryStatus = 'mastered';
            }
        }
    }

    // XPは正解のみ。カタログ未登録でも既定1XPを付与する（全モードで必ずXPが入るように）。
    // 未登録は警告ログを残し、講師/保護者画面のラベル整備のためにカタログ追加を促す。
    $xpAwarded = 0;
    if ($isCorrect) {
        $stmt = $pdo->prepare('SELECT base_xp FROM question_catalog WHERE unit_key = :unit_key AND question_key = :question_key');
        $stmt->execute(['unit_key' => $unitKey, 'question_key' => $questionKey]);
        $baseXp = $stmt->fetchColumn();

        if ($baseXp === false) {
            error_log("[save_answer] question_catalog未登録(既定XPで付与): unit_key={$unitKey} question_key={$questionKey}");
            $baseXp = DEFAULT_BASE_XP;
        }
        $baseXp = (int)$baseXp;

        if ($baseXp > 0) {
            $stmt = $pdo->prepare(
                'SELECT event_id, multiplier FROM xp_events
                 WHERE NOW() BETWEEN starts_at AND ends_at
                   AND (unit_key_prefix IS NULL OR :unit_key LIKE CONCAT(unit_key_prefix, "%"))
                 ORDER BY multiplier DESC LIMIT 1'
            );
            $stmt->execute(['unit_key' => $unitKey]);
            $event = $stmt->fetch();
            $multiplier = $event ? (float)$event['multiplier'] : 1.0;
            $eventId = $event ? (int)$event['event_id'] : null;

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM answer_logs
                 WHERE student_id = :student_id AND question_key = :question_key AND answered_at >= CURDATE()'
            );
            $stmt->execute(['student_id' => $studentId, 'question_key' => $questionKey]);
            $answeredToday = (int)$stmt->fetchColumn();
            $decay = xp_decay_factor($answeredToday);

            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(amount), 0) FROM xp_logs WHERE student_id = :student_id AND created_at >= CURDATE()'
            );
            $stmt->execute(['student_id' => $studentId]);
            $todayTotal = (int)$stmt->fetchColumn();
            $remaining = max(0, DAILY_XP_CAP - $todayTotal);

            $computed = (int)floor($baseXp * $multiplier * $decay);
            $xpAwarded = min($computed, $remaining);

            if ($xpAwarded > 0) {
                $stmt = $pdo->prepare(
                    'INSERT INTO xp_logs (student_id, amount, reason, event_id, answer_id)
                     VALUES (:student_id, :amount, "correct", :event_id, :answer_id)'
                );
                $stmt->execute([
                    'student_id' => $studentId,
                    'amount'     => $xpAwarded,
                    'event_id'   => $eventId,
                    'answer_id'  => $answerId,
                ]);
            }
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

json_response([
    'ok'           => true,
    'answer_id'    => $answerId,
    'params_hash'  => $hash,
    'retry_status' => $retryStatus,
    'xp_awarded'   => $xpAwarded,
]);
