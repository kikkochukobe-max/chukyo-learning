<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 生徒の学習記録だけを全消去する（super_admin のみ）。テストデータの掃除用。
// アカウント本体（生徒・PIN・保護者ひもづけ・志望校・教室）はそのまま残し、
// 記録テーブルの行だけを消す。
//   answer_logs / study_sessions / retry_queue / xp_logs /
//   time_records / paper_test_results
// answer_logs を先に消す（study_sessions が先だと fk_al_session の
// ON DELETE SET NULL が無駄に走るだけ）。answer_logs.retry_of には
// 外部キーが無いので自己参照で詰まることはない。
// xp_logs も消す: レベルは累計XPから算出するため、ここを残すと
// 「解答0なのにレベルだけ高い」状態になる。
// ログイン履歴(login_logs) と自動ログイン(auth_tokens) は「学習記録」ではないので残す
// （生徒がログインし直さずに済むように）。
// question_catalog の stat_total/stat_correct は update_xp.php が
// answer_logs からまるごと再集計するので手当て不要。
//
// アカウントごと消す場合は delete_student.php、
// 通常の退塾は set_active.php の無効化（記録は残す）を使うこと。
// 同じ処理のSQL版が db/maintenance/reset_student_records.sql にある。

// テーブルの存在確認（time_ranking.php と同じ SHOW TABLES 方式）
function record_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        try {
            $stmt = $pdo->prepare('SHOW TABLES LIKE :t');
            $stmt->execute(['t' => $table]);
            $cache[$table] = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
    }
    return $cache[$table];
}

require_post();
$actor = require_login(['teacher']);

$pdo = db();

$stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $actor['id']]);
if ($stmt->fetchColumn() !== 'super_admin') {
    json_response(['ok' => false, 'error' => 'forbidden'], 403);
}

$input = json_input();
$loginId = trim((string)($input['login_id'] ?? ''));
if ($loginId === '') {
    json_response(['ok' => false, 'error' => 'invalid_login_id'], 400);
}

$stmt = $pdo->prepare('SELECT student_id, student_name FROM students WHERE login_id = :login_id');
$stmt->execute(['login_id' => $loginId]);
$student = $stmt->fetch();
if (!$student) {
    json_response(['ok' => false, 'error' => 'student_not_found'], 404);
}
$studentId = (int)$student['student_id'];

// 消す順に並べた記録テーブル。time_records は migrate_time_records.sql 未適用の
// 環境がありうるので、存在するものだけに絞る（SHOW はトランザクション前に済ませる）
$tables = array_values(array_filter(
    ['answer_logs', 'study_sessions', 'retry_queue', 'xp_logs', 'time_records', 'paper_test_results'],
    fn($t) => record_table_exists($pdo, $t)
));

$deleted = [];
$pdo->beginTransaction();
try {
    foreach ($tables as $table) {
        // $table は上のリテラル配列由来のみ（外部入力は入らない）
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE student_id = :id");
        $stmt->execute(['id' => $studentId]);
        $deleted[$table] = $stmt->rowCount();
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

json_response([
    'ok'              => true,
    'login_id'        => $loginId,
    'student_name'    => $student['student_name'],
    'deleted'         => $deleted,
    'deleted_answers' => $deleted['answer_logs'] ?? 0,
    'deleted_retries' => $deleted['retry_queue'] ?? 0,   // マイページ「過去のまちがい」の件数
    'deleted_total'   => array_sum($deleted),
]);
