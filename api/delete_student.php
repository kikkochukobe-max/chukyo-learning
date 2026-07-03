<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 生徒の完全物理削除（super_admin のみ）。登録間違い・テストデータの掃除用。
// 学習記録(answer_logs/study_sessions/retry_queue/xp_logs/paper_test_results)と
// 保護者紐づけ(guardian_students)は外部キーのCASCADEで消える。
// login_logs / auth_tokens はFKが無い(actor_type+actor_id方式)ためここで明示的に消す。
// 保護者はこの生徒しか子がいない場合のみ道連れで削除（兄弟がいる保護者は残す）。
// 通常の退塾は set_active.php の無効化を使うこと（記録が消えるため）。

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

// 削除件数の報告用（消える学習記録の規模を返す）
$stmt = $pdo->prepare('SELECT COUNT(*) FROM answer_logs WHERE student_id = :id');
$stmt->execute(['id' => $studentId]);
$answerCount = (int)$stmt->fetchColumn();

$pdo->beginTransaction();
try {
    // この生徒に紐づく保護者を控えておく（削除後に子が残っているか確認する）
    $stmt = $pdo->prepare('SELECT guardian_id FROM guardian_students WHERE student_id = :id');
    $stmt->execute(['id' => $studentId]);
    $guardianIds = array_map(fn($r) => (int)$r['guardian_id'], $stmt->fetchAll());

    // FKの無いログを明示的に削除
    $stmt = $pdo->prepare("DELETE FROM login_logs WHERE actor_type = 'student' AND actor_id = :id");
    $stmt->execute(['id' => $studentId]);
    $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE actor_type = 'student' AND actor_id = :id");
    $stmt->execute(['id' => $studentId]);

    // 本体（学習記録・解き直し・XP・確認テスト・保護者紐づけはCASCADE）
    $stmt = $pdo->prepare('DELETE FROM students WHERE student_id = :id');
    $stmt->execute(['id' => $studentId]);

    // 子がいなくなった保護者だけ道連れで削除（兄弟が残っていれば保護者も残る）
    $removedGuardians = 0;
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM guardian_students WHERE guardian_id = :gid');
    $delLog = $pdo->prepare("DELETE FROM login_logs WHERE actor_type = 'guardian' AND actor_id = :gid");
    $delTok = $pdo->prepare("DELETE FROM auth_tokens WHERE actor_type = 'guardian' AND actor_id = :gid");
    $delGuardian = $pdo->prepare('DELETE FROM guardians WHERE guardian_id = :gid');
    foreach ($guardianIds as $gid) {
        $countStmt->execute(['gid' => $gid]);
        if ((int)$countStmt->fetchColumn() === 0) {
            $delLog->execute(['gid' => $gid]);
            $delTok->execute(['gid' => $gid]);
            $delGuardian->execute(['gid' => $gid]);
            $removedGuardians++;
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

json_response([
    'ok'                => true,
    'login_id'          => $loginId,
    'student_name'      => $student['student_name'],
    'deleted_answers'   => $answerCount,
    'removed_guardians' => $removedGuardians,
]);
