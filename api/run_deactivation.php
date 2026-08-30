<?php
declare(strict_types=1);

// ============================================================
// 退会予約の日次バッチ
// Hetemlのcronで毎日1回（深夜〜早朝、例 4:00）実行する。
//
//   students.deactivate_on = 「この日までは使える」最終利用日
//   → 翌日になった生徒を is_active = 0 にする（予約日そのものは履歴として残す）
//
// cron の例（update_xp.php と同じ書き方）:
//   /usr/local/bin/php /home/sites/heteml/users/xxxx/web/chukyokobetsu.com/api/run_deactivation.php
//   もしくは curl -s "https://chukyokobetsu.com/api/run_deactivation.php?token=..."
//
// 直接URLで叩かれないよう保護:
//   ・CLI実行(php run_deactivation.php)は無条件で許可
//   ・Web経由は ?token=... が config の batch_token（無ければ xp_batch_token）と
//     一致した時のみ許可
//
// ※このバッチが動かなくても、ログイン時(api/auth.php)と講師ページ表示時に
//   同じ掃除(sweep_due_deactivations)が走るので、退会した生徒がログインできることはない。
//   cron はあくまで「一覧やレポートの人数も当日中に正しくする」ためのもの。
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    $config = require config_path();
    $expected = $config['batch_token'] ?? ($config['xp_batch_token'] ?? '');
    $given = (string)($_GET['token'] ?? '');
    header('Content-Type: text/plain; charset=utf-8');
    if ($expected === '' || !hash_equals((string)$expected, $given)) {
        http_response_code(403);
        echo "forbidden\n";
        exit;
    }
}

$pdo = db();

// 誰が無効になったかを cron のメール／ログに残したいので、先に対象を読む
$due = [];
try {
    $due = $pdo->query(
        'SELECT login_id, student_name, deactivate_on FROM students
          WHERE is_active = 1 AND deactivate_on IS NOT NULL AND deactivate_on < CURDATE()
          ORDER BY deactivate_on, login_id'
    )->fetchAll();
} catch (PDOException $e) {
    // deactivate_on 列が無い（migrate_student_deactivate_schedule.sql が未実行）
    $msg = "[deactivation] students.deactivate_on がありません。db/migrations/migrate_student_deactivate_schedule.sql を実行してください\n";
    error_log(trim($msg));
    if (!$isCli) {
        http_response_code(500);
    }
    echo $msg;
    exit;
}

$count = sweep_due_deactivations($pdo);

$msg = sprintf("[deactivation] %s: %d 人を無効にしました\n", date('Y-m-d H:i:s'), $count);
foreach ($due as $s) {
    $msg .= sprintf("  %s %s（%s まで）\n", $s['login_id'], $s['student_name'], $s['deactivate_on']);
}
error_log(sprintf('[deactivation] %d rows deactivated', $count));

echo $msg;
