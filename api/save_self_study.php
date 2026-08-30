<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/self_study_common.php';

// 自習記録の新規作成・修正。生徒本人だけが呼べる。
//
//  新規（まとめ書き）: {study_date, items:[{subject, study_type, retain_span, material, …}, …]}
//    生徒は「覚える勉強」「忘れない勉強」の欄に教科ぶんの入力を並べて1回で送る。
//    日付は全件で共通。保存は 1教科 = 1行（講師が教科ごとに確認印を押せる粒度を保つ）。
//    1件でも弾かれたら全件保存しない（トランザクション）＝入力欄がそのまま残る。
//  修正: {log_id, study_date, subject, …} の単件（自分の記録・かつ未確認のものだけ）
//
// XPは付けない（自己申告のため。CLAUDE.md「自習記録」の項を参照）。

require_post();
$actor = require_login(['student']);
$studentId = $actor['id'];

$input = json_input();
$logId = isset($input['log_id']) ? (int)$input['log_id'] : 0;

// ---- 日付: 未来日は不可 / SELF_STUDY_BACKDATE_DAYS より前も不可 ----
// まとめ書きでも日付は1つ（「その日にやった自習」をまとめて書く画面のため）
$dateStr = trim((string)($input['study_date'] ?? ''));
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateStr);
if (!$date || $date->format('Y-m-d') !== $dateStr) {
    json_response(['ok' => false, 'error' => 'invalid_date'], 400);
}
$today = new DateTimeImmutable('today');
if ($date > $today || $date < $today->modify('-' . SELF_STUDY_BACKDATE_DAYS . ' days')) {
    json_response(['ok' => false, 'error' => 'date_out_of_range'], 400);
}

// 検証に落ちたらその場で400を返して終了する。
// まとめ書きのときは何件目かを index で返す＝画面がそのカードだけを赤くできる。
function ss_fail(string $error, ?int $index): void
{
    $res = ['ok' => false, 'error' => $error];
    if ($index !== null) {
        $res['index'] = $index;
    }
    json_response($res, 400);
}

// 1教科ぶんの入力を検証して INSERT/UPDATE 用の値に整える
function ss_clean_item(array $in, ?int $index): array
{
    // ---- 教科 ----
    $subject = (string)($in['subject'] ?? '');
    if (!isset(SELF_STUDY_SUBJECTS[$subject])) {
        ss_fail('invalid_subject', $index);
    }

    // ---- 覚える勉強 / 忘れない勉強 ----
    // 未指定も通す（この機能より前のクライアントから来た場合。区別なしで保存される）
    $type = trim((string)($in['study_type'] ?? ''));
    if ($type === '') {
        $type = null;
    } elseif (!isset(SELF_STUDY_TYPES[$type])) {
        ss_fail('invalid_type', $index);
    }
    $span = trim((string)($in['retain_span'] ?? ''));
    if ($span === '') {
        $span = null;
    } elseif (!isset(SELF_STUDY_RETAIN_SPANS[$span])) {
        ss_fail('invalid_span', $index);
    }
    // 短期／長期は「忘れない勉強」だけが持つ（覚える勉強に付いてきたら捨てる）
    if ($type !== 'retain') {
        $span = null;
    }

    // ---- 教材名（必須）・範囲 ----
    $material = trim((string)($in['material'] ?? ''));
    if ($material === '' || mb_strlen($material) > 100) {
        ss_fail('invalid_material', $index);
    }
    $rangeText = trim((string)($in['range_text'] ?? ''));
    if (mb_strlen($rangeText) > 100) {
        ss_fail('invalid_range', $index);
    }
    $rangeText = $rangeText !== '' ? $rangeText : null;

    // ---- 学習時間(分)。0は「書かなかった」扱いにする ----
    $minutes = null;
    if (isset($in['minutes']) && $in['minutes'] !== '' && $in['minutes'] !== null) {
        $minutes = (int)$in['minutes'];
        if ($minutes < 0 || $minutes > 600) {
            ss_fail('invalid_minutes', $index);
        }
        if ($minutes === 0) {
            $minutes = null;
        }
    }

    // ---- 手ごたえ 1〜5 ----
    $feeling = null;
    if (isset($in['feeling']) && $in['feeling'] !== '' && $in['feeling'] !== null) {
        $feeling = (int)$in['feeling'];
        if (!isset(SELF_STUDY_FEELINGS[$feeling])) {
            ss_fail('invalid_feeling', $index);
        }
    }

    // ---- メモ ----
    $memo = trim((string)($in['memo'] ?? ''));
    if (mb_strlen($memo) > 500) {
        ss_fail('invalid_memo', $index);
    }
    $memo = $memo !== '' ? $memo : null;

    return [
        'subject'     => $subject,
        'study_type'  => $type,
        'retain_span' => $span,
        'material'    => $material,
        'range_text'  => $rangeText,
        'minutes'     => $minutes,
        'feeling'     => $feeling,
        'memo'        => $memo,
    ];
}

$pdo = db();
// 列が無い環境（migrate_self_study_type.sql 未実行）では区別を保存せずに動く
$hasType = self_study_has_type($pdo);

// ============================================================
// 修正（単件）
// ============================================================
if ($logId > 0) {
    $item = ss_clean_item($input, null);

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

    $set = 'study_date = :d, subject = :sub, material = :mat, range_text = :rng,
            minutes = :min, feeling = :fee, memo = :memo';
    $params = [
        'd' => $dateStr, 'sub' => $item['subject'], 'mat' => $item['material'],
        'rng' => $item['range_text'], 'min' => $item['minutes'],
        'fee' => $item['feeling'], 'memo' => $item['memo'],
        'id' => $logId, 'sid' => $studentId,
    ];
    if ($hasType) {
        $set .= ', study_type = :type, retain_span = :span';
        $params['type'] = $item['study_type'];
        $params['span'] = $item['retain_span'];
    }

    $stmt = $pdo->prepare(
        "UPDATE self_study_logs SET {$set} WHERE log_id = :id AND student_id = :sid"
    );
    $stmt->execute($params);

    json_response(['ok' => true, 'log_id' => $logId, 'log_ids' => [$logId], 'count' => 1, 'updated' => true]);
}

// ============================================================
// 新規（まとめ書き。items が無ければ1件だけ送られてきたものとして扱う）
// ============================================================
$rawItems = $input['items'] ?? null;
if (is_array($rawItems)) {
    $rawItems = array_values($rawItems);
    if (count($rawItems) === 0) {
        json_response(['ok' => false, 'error' => 'no_items'], 400);
    }
    if (count($rawItems) > SELF_STUDY_MAX_ITEMS) {
        json_response(['ok' => false, 'error' => 'too_many_items'], 400);
    }
    $items = [];
    foreach ($rawItems as $i => $raw) {
        if (!is_array($raw)) {
            ss_fail('invalid_request', $i);
        }
        $items[] = ss_clean_item($raw, $i);
    }
} else {
    $items = [ss_clean_item($input, null)];
}

$deviceId = device_id($pdo);

$cols = 'student_id, study_date, subject, material, range_text, minutes, feeling, memo, device_id';
$vals = ':sid, :d, :sub, :mat, :rng, :min, :fee, :memo, :dev';
if ($hasType) {
    $cols .= ', study_type, retain_span';
    $vals .= ', :type, :span';
}
$stmt = $pdo->prepare("INSERT INTO self_study_logs ({$cols}) VALUES ({$vals})");

// 途中で落ちたら1件も残さない（「英語だけ入った」状態にすると生徒が二重に書き直す）
$logIds = [];
$pdo->beginTransaction();
try {
    foreach ($items as $item) {
        $params = [
            'sid' => $studentId, 'd' => $dateStr, 'sub' => $item['subject'],
            'mat' => $item['material'], 'rng' => $item['range_text'],
            'min' => $item['minutes'], 'fee' => $item['feeling'], 'memo' => $item['memo'],
            'dev' => $deviceId,
        ];
        if ($hasType) {
            $params['type'] = $item['study_type'];
            $params['span'] = $item['retain_span'];
        }
        $stmt->execute($params);
        $logIds[] = (int)$pdo->lastInsertId();
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

json_response([
    'ok'      => true,
    'log_id'  => $logIds[0],
    'log_ids' => $logIds,
    'count'   => count($logIds),
    'updated' => false,
]);
