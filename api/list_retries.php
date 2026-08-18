<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 指定単元の解き直し(pending)一覧を返す。ツールが「解き直しモード」で
// question_params から同一問題を再出題するために使う。
// question_params から作り直せないツール向けに、保存されていれば
// replay（画面に出した問題そのもの）も返す（retry_queue.replay_json）。
$actor = require_login(['student']);

$unitKey = (string)($_GET['unit_key'] ?? '');
if (!preg_match('/^[a-z0-9_]{1,64}$/i', $unitKey)) {
    json_response(['ok' => false, 'error' => 'invalid_unit_key'], 400);
}

$pdo = db();

// replay_json は migrate_retry_replay.sql を当てた環境にしか無い。
// 未適用の環境で列名を書くとSQLエラーで一覧ごと落ちるので、先に確認する。
$hasReplay = false;
try {
    $hasReplay = (bool)$pdo->query("SHOW COLUMNS FROM retry_queue LIKE 'replay_json'")->fetchColumn();
} catch (Throwable $e) {
    $hasReplay = false;
}

$stmt = $pdo->prepare(
    'SELECT retry_id, question_key, question_params, ' . ($hasReplay ? 'replay_json, ' : '') .
    "params_hash, wrong_count, correct_streak
     FROM retry_queue
     WHERE student_id = :id AND unit_key = :unit_key AND status = 'pending'
     ORDER BY updated_at DESC"
);
$stmt->execute(['id' => $actor['id'], 'unit_key' => $unitKey]);

$items = [];
foreach ($stmt->fetchAll() as $row) {
    $params = $row['question_params'] !== null ? json_decode($row['question_params'], true) : null;
    // 再出題に必要な question_params が無い古い記録はスキップ
    if (!is_array($params)) {
        continue;
    }
    $replay = null;
    if ($hasReplay && ($row['replay_json'] ?? null) !== null) {
        $decoded = json_decode((string)$row['replay_json'], true);
        if (is_array($decoded)) $replay = $decoded;
    }
    $items[] = [
        'retry_id'        => (int)$row['retry_id'],
        'question_key'    => $row['question_key'],
        'question_params' => $params,
        // 画面に出した問題そのもの。無い場合は null（params から復元できるツールは使わない）
        'replay'          => $replay,
        'params_hash'     => (string)$row['params_hash'],
        'wrong_count'     => (int)$row['wrong_count'],
        'correct_streak'  => (int)$row['correct_streak'],
    ];
}

json_response(['ok' => true, 'items' => $items]);
