<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 既存講師の一括パスワード初期化（super_admin のみ）。
// 対象は「まだ本パスワードを設定していない講師」= must_change_password=1 かつ is_active=1。
// （初回ログイン済みで本人が本PWを設定した講師は must_change_password=0 なので巻き込まない）
// 自分自身は除外する。各講師に新しい仮パスワードを発行し、配布用の一覧を返す。

require_post();
$actor = require_login(['teacher']);

$pdo = db();

$stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $actor['id']]);
if ($stmt->fetchColumn() !== 'super_admin') {
    json_response(['ok' => false, 'error' => 'forbidden'], 403);
}

$stmt = $pdo->prepare(
    'SELECT teacher_id, login_id, teacher_name
       FROM teachers
      WHERE is_active = 1 AND must_change_password = 1 AND teacher_id <> :self
      ORDER BY teacher_id'
);
$stmt->execute(['self' => (int)$actor['id']]);
$targets = $stmt->fetchAll();

$update = $pdo->prepare(
    'UPDATE teachers SET password_hash = :hash, must_change_password = 1 WHERE teacher_id = :id'
);

$results = [];
$pdo->beginTransaction();
try {
    foreach ($targets as $t) {
        $temp = generate_temp_password();
        $update->execute([
            'hash' => password_hash($temp, PASSWORD_DEFAULT),
            'id'   => (int)$t['teacher_id'],
        ]);
        $results[] = [
            'login_id'      => $t['login_id'],
            'teacher_name'  => $t['teacher_name'],
            'temp_password' => $temp,
        ];
    }
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    throw $e;
}

json_response(['ok' => true, 'count' => count($results), 'teachers' => $results]);
