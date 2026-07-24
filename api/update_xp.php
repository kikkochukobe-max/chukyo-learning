<?php
declare(strict_types=1);

// ============================================================
// 動的XP単価の日次バッチ（docs/xp_dynamic_spec.md §2）
// Hetemlのcronで深夜(例 3:00)に1日1回実行する。
//
// 全生徒の全期間の正答率から question_catalog.current_xp を更新する:
//   スムージング正答率 p = (correct + 5) / (total + 10)
//   xp = round(base_xp × (2 − p))   … 正答率100%で等倍、50%で1.5倍
//   下限 base_xp、上限 base_xp × 3、回答20件未満は base_xp のまま
//   統計(stat_total / stat_correct)は全回答をカウント(同一生徒の繰り返しも含む)
//
// 直接URLで叩かれないよう保護:
//   ・CLI実行(php update_xp.php)は無条件で許可
//   ・Web経由は ?token=... が config の xp_batch_token と一致した時のみ許可
// ============================================================

require_once __DIR__ . '/db.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    // Web経由: 秘密トークン照合。config に xp_batch_token が無ければ一律拒否。
    $config = require config_path();
    $expected = $config['xp_batch_token'] ?? '';
    $given = (string)($_GET['token'] ?? '');
    header('Content-Type: text/plain; charset=utf-8');
    if ($expected === '' || !hash_equals((string)$expected, $given)) {
        http_response_code(403);
        echo "forbidden\n";
        exit;
    }
}

$pdo = db();

$sql = <<<SQL
UPDATE question_catalog qc
JOIN (
  SELECT unit_key, question_key,
         COUNT(*)        AS total,
         SUM(is_correct) AS correct
  FROM answer_logs
  GROUP BY unit_key, question_key
) t ON qc.unit_key = t.unit_key AND qc.question_key = t.question_key
SET qc.stat_total   = t.total,
    qc.stat_correct = t.correct,
    qc.current_xp = CASE
      WHEN t.total < 20 THEN qc.base_xp
      ELSE LEAST(
             qc.base_xp * 3,
             GREATEST(
               qc.base_xp,
               ROUND(qc.base_xp * (2 - (t.correct + 5) / (t.total + 10)))
             )
           )
    END,
    qc.stat_updated_at = NOW()
SQL;

$affected = $pdo->exec($sql);

$msg = sprintf("[update_xp] %s: %d rows updated\n", date('Y-m-d H:i:s'), (int)$affected);
error_log(trim($msg));

if (!$isCli) {
    echo $msg;
} else {
    fwrite(STDOUT, $msg);
}
