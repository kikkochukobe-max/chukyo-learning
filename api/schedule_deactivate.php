<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 退会の予約（生徒の自動無効化）
// 退会が月の途中で決まったとき、その場で「最終利用日」を入れておくと翌日から自動で無効になる。
// 月末に無効化操作をする必要が無くなる＝操作忘れの防止。
//
// login_id      : 生徒コード
// deactivate_on : 'YYYY-MM-DD'（この日までは使える。翌日から無効）/ null または '' で予約の取り消し
//
// 権限は set_active.php と同じ（super_admin または担当教室の classroom_admin）。
// 実際に無効化するのは api/run_deactivation.php(cron) と sweep_due_deactivations()。

require_post();
$actor = require_login(['teacher']);

$pdo = db();

$stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $actor['id']]);
$requesterRole = $stmt->fetchColumn();

if (!in_array($requesterRole, ['super_admin', 'classroom_admin'], true)) {
    json_response(['ok' => false, 'error' => 'forbidden'], 403);
}

// deactivate_on 列が無い＝db/migrations/migrate_student_deactivate_schedule.sql が未実行。
// ただし「SQLは流したのに出る」場合は接続先DBが別、という切り分けが要るので、
// つないでいるDB名と実際のエラーをそのまま返す（この画面は講師ログイン必須）。
if (!table_has_column($pdo, 'students', 'deactivate_on')) {
    $dbName = '';
    try {
        $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    } catch (PDOException $e) {
        $dbName = '(不明)';
    }
    json_response([
        'ok'     => false,
        'error'  => 'schema_not_ready',
        'detail' => '接続先DB: ' . $dbName . ' / ' . schema_probe_error(),
    ], 503);
}

$input = json_input();
$loginId = trim((string)($input['login_id'] ?? ''));
$raw = $input['deactivate_on'] ?? null;
$date = is_string($raw) ? trim($raw) : '';

if ($loginId === '') {
    json_response(['ok' => false, 'error' => 'invalid_login_id'], 400);
}

if ($date !== '') {
    // 'YYYY-MM-DD' として実在する日付か（2月30日のような値を弾く）
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        json_response(['ok' => false, 'error' => 'invalid_date'], 400);
    }
    // 今日は可（「今日までは使える」＝明日から無効）。過去日は不可
    if ($date < date('Y-m-d')) {
        json_response(['ok' => false, 'error' => 'past_date'], 400);
    }
    // 打ち間違い（年を1桁多く打つ等）の歯止め。1年ちょっと先まで
    if ($date > date('Y-m-d', strtotime('+400 day'))) {
        json_response(['ok' => false, 'error' => 'too_far'], 400);
    }
}

$stmt = $pdo->prepare('SELECT student_id, student_name, classroom_id, is_active FROM students WHERE login_id = :login_id');
$stmt->execute(['login_id' => $loginId]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['ok' => false, 'error' => 'student_not_found'], 404);
}

if ($requesterRole === 'classroom_admin') {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM teacher_classrooms WHERE teacher_id = :tid AND classroom_id = :cid');
    $stmt->execute(['tid' => $actor['id'], 'cid' => $row['classroom_id']]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_response(['ok' => false, 'error' => 'forbidden_classroom'], 403);
    }
}

// すでに停止中の生徒に予約を入れても意味が無い（先に「有効に戻す」が要る）
if ($date !== '' && (int)$row['is_active'] !== 1) {
    json_response(['ok' => false, 'error' => 'already_inactive'], 400);
}

$stmt = $pdo->prepare('UPDATE students SET deactivate_on = :d WHERE student_id = :id');
$stmt->execute(['d' => ($date !== '' ? $date : null), 'id' => $row['student_id']]);

json_response([
    'ok'            => true,
    'login_id'      => $loginId,
    'student_name'  => $row['student_name'],
    'deactivate_on' => $date !== '' ? $date : null,
]);
