<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 講師の完全物理削除（統括のみ・登録間違い/テストデータの掃除用）。
// 消えるのは講師アカウント本体・担当教室の紐づけ(teacher_classrooms=FK CASCADE)・
// 講師のログイン履歴と自動ログイントークン(login_logs/auth_tokens=FKなしのため明示削除)だけ。
// この講師が登録した生徒(students.created_by)・確認テストの記録者(paper_test_results.recorded_by)は
// FKの ON DELETE SET NULL で NULL になるだけ＝生徒・学習記録・テスト結果は一切消えない。
// 自分自身は削除不可（実行者の統括は必ず残る）。
// 通常の退任は set_active.php の無効化を使うこと（監査のため履歴を残す）。

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

$stmt = $pdo->prepare('SELECT teacher_id, teacher_name FROM teachers WHERE login_id = :login_id');
$stmt->execute(['login_id' => $loginId]);
$teacher = $stmt->fetch();
if (!$teacher) {
    json_response(['ok' => false, 'error' => 'teacher_not_found'], 404);
}
$teacherId = (int)$teacher['teacher_id'];

if ($teacherId === (int)$actor['id']) {
    json_response(['ok' => false, 'error' => 'cannot_self'], 400);
}

$pdo->beginTransaction();
try {
    // login_logs / auth_tokens は actor_type+actor_id のポリモーフィック参照（FKなし）なので明示的に消す
    $pdo->prepare("DELETE FROM login_logs WHERE actor_type = 'teacher' AND actor_id = :id")
        ->execute(['id' => $teacherId]);
    $pdo->prepare("DELETE FROM auth_tokens WHERE actor_type = 'teacher' AND actor_id = :id")
        ->execute(['id' => $teacherId]);
    // teacher_classrooms は FK ON DELETE CASCADE で一緒に消える。
    // students.created_by / paper_test_results.recorded_by は SET NULL（本体は残る）。
    $pdo->prepare('DELETE FROM teachers WHERE teacher_id = :id')->execute(['id' => $teacherId]);
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    throw $e;
}

json_response([
    'ok'           => true,
    'login_id'     => $loginId,
    'teacher_name' => $teacher['teacher_name'],
]);
