<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 自分のタイムアタック記録トップ10（速い順）を返す。
// ツールの「きろくを見る」ボタンから呼ばれる。ログイン必須。

$actor = require_login(['student']);

$unitKey = (string)($_GET['unit_key'] ?? '');
$questionKey = (string)($_GET['question_key'] ?? 'default');
if ($questionKey === '') {
    $questionKey = 'default';
}
if (!preg_match('/^[a-z0-9_]{1,64}$/i', $unitKey) || !preg_match('/^[a-zA-Z0-9_]{1,128}$/', $questionKey)) {
    json_response(['ok' => false, 'error' => 'invalid_request'], 400);
}

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT time_ms, miss_count, meta, created_at
     FROM time_records
     WHERE student_id = :sid AND unit_key = :uk AND question_key = :qk
     ORDER BY time_ms ASC, created_at ASC
     LIMIT 10'
);
$stmt->execute(['sid' => $actor['id'], 'uk' => $unitKey, 'qk' => $questionKey]);

$items = [];
foreach ($stmt->fetchAll() as $row) {
    $items[] = [
        'time_ms'    => (int)$row['time_ms'],
        'miss_count' => (int)$row['miss_count'],
        'meta'       => $row['meta'] !== null ? json_decode($row['meta'], true) : null,
        'created_at' => $row['created_at'],
    ];
}

// これまでの総プレイ回数（「これまで◯回チャレンジ」表示用）
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM time_records
     WHERE student_id = :sid AND unit_key = :uk AND question_key = :qk'
);
$stmt->execute(['sid' => $actor['id'], 'uk' => $unitKey, 'qk' => $questionKey]);
$totalPlays = (int)$stmt->fetchColumn();

json_response(['ok' => true, 'items' => $items, 'total_plays' => $totalPlays]);
