<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/self_study_common.php';

// 自習記録の新規作成・修正。生徒本人だけが呼べる。
// log_id を付けて送ると修正（自分の記録・かつ未確認のものだけ）。
// XPは付けない（自己申告のため。CLAUDE.md「自習記録」の項を参照）。

require_post();
$actor = require_login(['student']);
$studentId = $actor['id'];

$input = json_input();
$logId = isset($input['log_id']) ? (int)$input['log_id'] : 0;

// ---- 日付: 未来日は不可 / SELF_STUDY_BACKDATE_DAYS より前も不可 ----
$dateStr = trim((string)($input['study_date'] ?? ''));
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateStr);
if (!$date || $date->format('Y-m-d') !== $dateStr) {
    json_response(['ok' => false, 'error' => 'invalid_date'], 400);
}
$today = new DateTimeImmutable('today');
if ($date > $today || $date < $today->modify('-' . SELF_STUDY_BACKDATE_DAYS . ' days')) {
    json_response(['ok' => false, 'error' => 'date_out_of_range'], 400);
}

// ---- 教科 ----
$subject = (string)($input['subject'] ?? '');
if (!isset(SELF_STUDY_SUBJECTS[$subject])) {
    json_response(['ok' => false, 'error' => 'invalid_subject'], 400);
}

// ---- 教材名（必須）・範囲 ----
$material = trim((string)($input['material'] ?? ''));
if ($material === '' || mb_strlen($material) > 100) {
    json_response(['ok' => false, 'error' => 'invalid_material'], 400);
}
$rangeText = trim((string)($input['range_text'] ?? ''));
if (mb_strlen($rangeText) > 100) {
    json_response(['ok' => false, 'error' => 'invalid_range'], 400);
}
$rangeText = $rangeText !== '' ? $rangeText : null;

// ---- 学習時間(分)。0は「書かなかった」扱いにする ----
$minutes = null;
if (isset($input['minutes']) && $input['minutes'] !== '' && $input['minutes'] !== null) {
    $minutes = (int)$input['minutes'];
    if ($minutes < 0 || $minutes > 600) {
        json_response(['ok' => false, 'error' => 'invalid_minutes'], 400);
    }
    if ($minutes === 0) {
        $minutes = null;
    }
}

// ---- 手ごたえ 1〜5 ----
$feeling = null;
if (isset($input['feeling']) && $input['feeling'] !== '' && $input['feeling'] !== null) {
    $feeling = (int)$input['feeling'];
    if (!isset(SELF_STUDY_FEELINGS[$feeling])) {
        json_response(['ok' => false, 'error' => 'invalid_feeling'], 400);
    }
}

// ---- メモ ----
$memo = trim((string)($input['memo'] ?? ''));
if (mb_strlen($memo) > 500) {
    json_response(['ok' => false, 'error' => 'invalid_memo'], 400);
}
$memo = $memo !== '' ? $memo : null;

$pdo = db();

if ($logId > 0) {
    // 確認印が押された記録は生徒側から書き換えられない
    // （講師が見てコメントを返した内容と食い違うため）
    $stmt = $pdo->prepare(
        'SELECT checked_at FROM self_study_logs WHERE log_id = :id AND student_id = :sid'
    );
    $stmt->execute(['id' => $logId, 'sid' => $studentId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'not_found'], 404);
    }
    if ($row['checked_at'] !== null) {
        json_response(['ok' => false, 'error' => 'already_checked'], 409);
    }

    $stmt = $pdo->prepare(
        'UPDATE self_study_logs
         SET study_date = :d, subject = :sub, material = :mat, range_text = :rng,
             minutes = :min, feeling = :fee, memo = :memo
         WHERE log_id = :id AND student_id = :sid'
    );
    $stmt->execute([
        'd' => $dateStr, 'sub' => $subject, 'mat' => $material, 'rng' => $rangeText,
        'min' => $minutes, 'fee' => $feeling, 'memo' => $memo,
        'id' => $logId, 'sid' => $studentId,
    ]);

    json_response(['ok' => true, 'log_id' => $logId, 'updated' => true]);
}

$deviceId = device_id($pdo);

$stmt = $pdo->prepare(
    'INSERT INTO self_study_logs
        (student_id, study_date, subject, material, range_text, minutes, feeling, memo, device_id)
     VALUES
        (:sid, :d, :sub, :mat, :rng, :min, :fee, :memo, :dev)'
);
$stmt->execute([
    'sid' => $studentId, 'd' => $dateStr, 'sub' => $subject, 'mat' => $material,
    'rng' => $rangeText, 'min' => $minutes, 'fee' => $feeling, 'memo' => $memo,
    'dev' => $deviceId,
]);

json_response(['ok' => true, 'log_id' => (int)$pdo->lastInsertId(), 'updated' => false]);
