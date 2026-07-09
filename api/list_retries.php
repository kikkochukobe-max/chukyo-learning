<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 指定単元の解き直し(pending)一覧を返す。ツールが「解き直しモード」で
// question_params から同一問題を再出題するために使う
$actor = require_login(['student']);

$unitKey = (string)($_GET['unit_key'] ?? '');
if (!preg_match('/^[a-z0-9_]{1,64}$/i', $unitKey)) {
    json_response(['ok' => false, 'error' => 'invalid_unit_key'], 400);
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT retry_id, question_key, question_params, params_hash, wrong_count, correct_streak
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
    $items[] = [
        'retry_id'        => (int)$row['retry_id'],
        'question_key'    => $row['question_key'],
        'question_params' => $params,
        'params_hash'     => (string)$row['params_hash'],
        'wrong_count'     => (int)$row['wrong_count'],
        'correct_streak'  => (int)$row['correct_streak'],
    ];
}

json_response(['ok' => true, 'items' => $items]);
