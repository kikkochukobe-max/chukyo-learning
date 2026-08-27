<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/self_study_common.php';

// 自習記録に講師の「確認印」を押す／ひとことコメントを返す。
//  {log_id, checked:true|false, comment:"…"}
//  checked=false は押しまちがいの取り消し（生徒がまた編集できる状態に戻る）。
//  comment だけを送ると確認状態は変えずにコメントを書き換える。
// 権限: 担当教室の生徒のみ（super_admin は全教室）。teacher ロールでも押せる
//   （確認印は評価ではなく「見ました」の意思表示なので、閲覧できる講師なら押してよい）。

require_post();
$actor = require_login(['teacher']);
$teacherId = (int)$actor['id'];

$input = json_input();
$logId = isset($input['log_id']) ? (int)$input['log_id'] : 0;
if ($logId <= 0) {
    json_response(['ok' => false, 'error' => 'invalid_request'], 400);
}

$hasChecked = array_key_exists('checked', $input);
$hasComment = array_key_exists('comment', $input);
if (!$hasChecked && !$hasComment) {
    json_response(['ok' => false, 'error' => 'invalid_request'], 400);
}

$comment = null;
if ($hasComment) {
    $comment = trim((string)$input['comment']);
    if (mb_strlen($comment) > 500) {
        json_response(['ok' => false, 'error' => 'invalid_comment'], 400);
    }
    $comment = $comment !== '' ? $comment : null;
}

$pdo = db();

$stmt = $pdo->prepare('SELECT student_id FROM self_study_logs WHERE log_id = :id');
$stmt->execute(['id' => $logId]);
$studentId = $stmt->fetchColumn();
if ($studentId === false) {
    json_response(['ok' => false, 'error' => 'not_found'], 404);
}
self_study_require_teacher_access($pdo, $teacherId, (int)$studentId);

// ※プレースホルダはエミュレーション無効なので同名を2回書けない。
//   teacher_id は「確認印」と「コメント」の両方で立てたいので、フラグで1回だけ組み立てる。
$sets = [];
$params = ['id' => $logId];
$setTeacher = false;

if ($hasChecked) {
    if ($input['checked']) {
        $sets[] = 'checked_at = NOW()';
        $setTeacher = true;
    } else {
        // 取り消し。コメントも一緒に消す（「確認していないのにコメントだけ残る」を防ぐ）
        $sets[] = 'checked_at = NULL';
        $sets[] = 'teacher_id = NULL';
        $sets[] = 'teacher_comment = NULL';
        $hasComment = false;
    }
}
if ($hasComment) {
    $sets[] = 'teacher_comment = :cmt';
    $params['cmt'] = $comment;
    $setTeacher = true;   // コメントだけ送られた場合も「見た人」を残す
}
if ($setTeacher) {
    $sets[] = 'teacher_id = :tid';
    $params['tid'] = $teacherId;
}

$stmt = $pdo->prepare('UPDATE self_study_logs SET ' . implode(', ', $sets) . ' WHERE log_id = :id');
$stmt->execute($params);

$stmt = $pdo->prepare(
    'SELECT sslog.log_id, sslog.study_date, sslog.subject, sslog.material, sslog.range_text,
            sslog.minutes, sslog.feeling, sslog.memo, sslog.checked_at, sslog.teacher_comment,
            t.teacher_name
     FROM self_study_logs sslog
     LEFT JOIN teachers t ON t.teacher_id = sslog.teacher_id
     WHERE sslog.log_id = :id'
);
$stmt->execute(['id' => $logId]);
$row = $stmt->fetch();

json_response(['ok' => true, 'item' => self_study_row($row)]);
