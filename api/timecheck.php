<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// タイムゾーン診断（講師ログイン必須）。日本時間ずれの切り分け用。
// PHP時刻とDBのNOW()が両方JSTなら、API経由の新規登録は正しく日本時間で記録される。
// それでも古い行がずれている場合は、phpMyAdmin等のUTCセッションで入れた行が原因。
require_login(['teacher']);

$pdo = db();
$row = $pdo->query(
    "SELECT NOW() AS db_now, @@session.time_zone AS session_tz, @@global.time_zone AS global_tz"
)->fetch();

$latest = $pdo->query(
    'SELECT login_id, created_at FROM teachers ORDER BY teacher_id DESC LIMIT 3'
)->fetchAll();

json_response([
    'ok'              => true,
    'php_now'         => date('Y-m-d H:i:s'),   // Asia/Tokyo のはず
    'db_now'          => $row['db_now'],         // php_now と一致するはず
    'session_tz'      => $row['session_tz'],     // +09:00 のはず
    'global_tz'       => $row['global_tz'],      // SYSTEM や UTC でも session が +09:00 なら問題ない
    'latest_teachers' => $latest,
]);
