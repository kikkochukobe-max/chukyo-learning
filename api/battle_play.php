<?php
declare(strict_types=1);

// 一般常識バトル（生徒側）。learning/game/game_es_joshiki_battle.html が呼ぶ。
//   GET  ?action=mine                       … 入っている部屋（待合室・対戦中・終わったばかり）。
//                                             対戦中に呼ばれた＝画面を開き直した、なので失格にする
//   POST {action:'join', code}              … 4桁の部屋番号で待合室に入る
//   POST {action:'leave', room_id}          … 待合室から出る
//   POST {action:'team', room_id, team}     … チーム戦の部屋で自分のチームを選ぶ（待合室のあいだは選び直せる）
//   GET  ?action=state&room_id=             … 進行状況（待合室の顔ぶれ・スタート時刻）。生存確認を兼ねる
//   GET  ?action=question&room_id=&seq=     … 問題（出題時刻になるまで返さない。正解の位置は含めない）
//   POST {action:'answer', room_id, seq, choice}
//   GET  ?action=reveal&room_id=&seq=       … 正解発表（締め切り＋猶予を過ぎるまで返さない）
//   POST {action:'dq', room_id, reason}     … 失格（画面を離れた。sendBeacon で届く）
//   GET  ?action=result&room_id=            … 最終順位と全問のふりかえり（終わってから）
// どの応答にも now_ms（サーバーの時計）を入れる。画面はこれで自分の時計を合わせる。
require_once __DIR__ . '/battle_common.php';

$actor = require_login(['student']);
// ポーリングのたびに同じ生徒のリクエストがセッションのロック待ちで直列にならないように、すぐ手放す
session_write_close();

$studentId = (int)$actor['id'];
$pdo = db();
$now = battle_now_ms();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$in = $isPost ? json_input() : $_GET;
$action = (string)($in['action'] ?? '');

function fail(string $error, int $status = 400, array $extra = []): void
{
    global $now;
    json_response(array_merge(['ok' => false, 'error' => $error, 'now_ms' => $now], $extra), $status);
}

function my_player(PDO $pdo, int $roomId, int $studentId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM battle_players WHERE room_id = :r AND student_id = :s');
    $stmt->execute(['r' => $roomId, 's' => $studentId]);
    $p = $stmt->fetch();
    return $p ?: null;
}

function touch_me(PDO $pdo, int $roomId, int $studentId, int $now): void
{
    $pdo->prepare(
        "UPDATE battle_players SET last_seen_ms = :n
         WHERE room_id = :r AND student_id = :s AND status IN ('waiting', 'playing')"
    )->execute(['n' => $now, 'r' => $roomId, 's' => $studentId]);
}

function me_public(?array $p): array
{
    if (!$p) {
        return ['status' => 'none'];
    }
    return [
        'status'    => (string)$p['status'],
        'team'      => isset($p['team']) ? (int)$p['team'] : null,
        'dq_reason' => $p['status'] === 'dq' ? (BATTLE_DQ_REASONS[$p['dq_reason']] ?? '失格') : null,
        'dq_no'     => ($p['status'] === 'dq' && $p['dq_seq'] !== null) ? (int)$p['dq_seq'] + 1 : null,
    ];
}

// room_id で部屋と自分の参加情報を読み、状態を「いま」に合わせる
function load_for_me(PDO $pdo, array $in, int $studentId, int $now): array
{
    $roomId = (int)($in['room_id'] ?? 0);
    $room = $roomId > 0 ? battle_load_room($pdo, $roomId) : null;
    if (!$room) {
        fail('room_not_found', 404);
    }
    $me = my_player($pdo, $roomId, $studentId);
    if (!$me) {
        fail('not_in_room', 403);
    }
    $room = battle_tick($pdo, $room, $now);
    $me = my_player($pdo, $roomId, $studentId);   // tick で失格になっていることがある
    return [$room, $me];
}

function seq_param(array $in, array $room): int
{
    $seq = (int)($in['seq'] ?? -1);
    if ($seq < 0 || $seq >= (int)$room['question_count']) {
        fail('invalid_seq');
    }
    return $seq;
}

switch ($action) {

case 'mine':
    // 最近入った部屋を1つ。終わった部屋は30分だけ結果を見に戻れる
    $stmt = $pdo->prepare(
        "SELECT r.* FROM battle_players p
         JOIN battle_rooms r ON r.room_id = p.room_id
         WHERE p.student_id = :s AND p.status <> 'left'
           AND (r.status IN ('lobby', 'playing')
                OR (r.status = 'finished' AND r.finished_at >= NOW() - INTERVAL 30 MINUTE))
         ORDER BY r.room_id DESC LIMIT 1"
    );
    $stmt->execute(['s' => $studentId]);
    $room = $stmt->fetch();
    if (!$room) {
        json_response(['ok' => true, 'room' => null, 'me' => me_public(null), 'now_ms' => $now]);
    }
    $roomId = (int)$room['room_id'];
    $room = battle_tick($pdo, $room, $now);
    $me = my_player($pdo, $roomId, $studentId);
    // 対戦中にページが読み込まれた＝閉じた・開き直した。sendBeacon が届かなかった場合もここで失格にする。
    // カウントダウン中（1問目が出る前）は失格にしない＝開き直した画面がそのまま対戦に入る（dq と同じ線引き）
    if ($room['status'] === 'playing' && $now >= (int)$room['start_ms'] && $me && $me['status'] === 'playing') {
        $pdo->prepare(
            "UPDATE battle_players SET status = 'dq', dq_reason = 'reload', dq_seq = :seq, dq_at = NOW()
             WHERE room_id = :r AND student_id = :s AND status = 'playing'"
        )->execute(['seq' => battle_seq_at($room, $now), 'r' => $roomId, 's' => $studentId]);
        $me = my_player($pdo, $roomId, $studentId);
    }
    if ($room['status'] === 'lobby') {
        touch_me($pdo, $roomId, $studentId, $now);
    }
    json_response(['ok' => true, 'room' => battle_room_public($room, $now), 'me' => me_public($me), 'now_ms' => $now]);
    break;

case 'join':
    if (!$isPost) {
        fail('method_not_allowed', 405);
    }
    $code = preg_replace('/\D/', '', (string)($in['code'] ?? ''));
    if (strlen($code) !== 4) {
        fail('invalid_code');
    }
    $stmt = $pdo->prepare(
        "SELECT * FROM battle_rooms WHERE room_code = :c AND status IN ('lobby', 'playing')
         ORDER BY room_id DESC LIMIT 1"
    );
    $stmt->execute(['c' => $code]);
    $room = $stmt->fetch();
    if (!$room) {
        fail('room_not_found', 404);
    }
    $roomId = (int)$room['room_id'];
    $room = battle_tick($pdo, $room, $now);
    if ($room['status'] !== 'lobby') {
        fail('already_started', 409);
    }
    // ほかの待合室に入っていたらそちらからは抜ける（同時に2部屋には入らない）
    $pdo->prepare(
        "UPDATE battle_players SET status = 'left'
         WHERE student_id = :s AND status = 'waiting' AND room_id <> :r"
    )->execute(['s' => $studentId, 'r' => $roomId]);
    $pdo->prepare(
        "INSERT INTO battle_players (room_id, student_id, status, last_seen_ms)
         VALUES (:r, :s, 'waiting', :n)
         ON DUPLICATE KEY UPDATE status = 'waiting', last_seen_ms = VALUES(last_seen_ms)"
    )->execute(['r' => $roomId, 's' => $studentId, 'n' => $now]);
    $me = my_player($pdo, $roomId, $studentId);
    json_response(['ok' => true, 'room' => battle_room_public($room, $now), 'me' => me_public($me), 'now_ms' => $now]);
    break;

case 'leave':
    if (!$isPost) {
        fail('method_not_allowed', 405);
    }
    $roomId = (int)($in['room_id'] ?? 0);
    $pdo->prepare(
        "UPDATE battle_players SET status = 'left'
         WHERE room_id = :r AND student_id = :s AND status = 'waiting'"
    )->execute(['r' => $roomId, 's' => $studentId]);
    json_response(['ok' => true, 'now_ms' => $now]);
    break;

case 'team':
    if (!$isPost) {
        fail('method_not_allowed', 405);
    }
    [$room, $me] = load_for_me($pdo, $in, $studentId, $now);
    if ($room['status'] !== 'lobby') {
        fail('already_started', 409);
    }
    if ($me['status'] !== 'waiting') {
        fail('not_in_room', 403);
    }
    $team = (int)($in['team'] ?? 0);
    if ($team < 1 || $team > (int)($room['team_count'] ?? 0)) {
        fail('invalid_team');
    }
    $pdo->prepare(
        "UPDATE battle_players SET team = :t, last_seen_ms = :n
         WHERE room_id = :r AND student_id = :s AND status = 'waiting'"
    )->execute(['t' => $team, 'n' => $now, 'r' => $room['room_id'], 's' => $studentId]);
    $me = my_player($pdo, (int)$room['room_id'], $studentId);
    json_response(['ok' => true, 'room' => battle_room_public($room, $now), 'me' => me_public($me), 'now_ms' => $now]);
    break;

case 'state':
    [$room, $me] = load_for_me($pdo, $in, $studentId, $now);
    touch_me($pdo, (int)$room['room_id'], $studentId, $now);
    $out = ['ok' => true, 'room' => battle_room_public($room, $now), 'me' => me_public($me), 'now_ms' => $now];
    if ($room['status'] === 'lobby') {
        $out['players'] = array_values(array_map(
            function ($p) { return ['name' => $p['name'], 'classroom' => $p['classroom'], 'team' => $p['team']]; },
            array_filter(battle_lobby_players($pdo, (int)$room['room_id'], $now), function ($p) { return $p['alive']; })
        ));
    }
    json_response($out);
    break;

case 'question':
    [$room, $me] = load_for_me($pdo, $in, $studentId, $now);
    if ($me['status'] !== 'playing' || $room['status'] !== 'playing') {
        fail('not_playing', 409, ['room' => battle_room_public($room, $now), 'me' => me_public($me)]);
    }
    $seq = seq_param($in, $room);
    $open = battle_open_ms($room, $seq);
    if ($now < $open - BATTLE_EARLY_MS) {
        fail('not_yet', 409, ['wait_ms' => $open - $now]);
    }
    $row = battle_room_question($pdo, (int)$room['room_id'], $seq);
    if (!$row) {
        fail('question_missing', 500);
    }
    touch_me($pdo, (int)$room['room_id'], $studentId, $now);
    $stmt = $pdo->prepare('SELECT choice FROM battle_answers WHERE room_id = :r AND student_id = :s AND seq = :q');
    $stmt->execute(['r' => $room['room_id'], 's' => $studentId, 'q' => $seq]);
    $mine = $stmt->fetchColumn();
    json_response([
        'ok'       => true,
        'seq'      => $seq,
        'question' => battle_question_payload($row),
        'open_ms'  => $open,
        'close_ms' => battle_close_ms($room, $seq),
        'answered' => $mine !== false ? (int)$mine : null,
        'now_ms'   => $now,
    ]);
    break;

case 'answer':
    if (!$isPost) {
        fail('method_not_allowed', 405);
    }
    [$room, $me] = load_for_me($pdo, $in, $studentId, $now);
    if ($me['status'] !== 'playing' || $room['status'] !== 'playing') {
        fail('not_playing', 409, ['room' => battle_room_public($room, $now), 'me' => me_public($me)]);
    }
    $seq = seq_param($in, $room);
    $choice = (int)($in['choice'] ?? -1);
    if ($choice < 0 || $choice > 5) {
        fail('invalid_choice');
    }
    $open = battle_open_ms($room, $seq);
    $close = battle_close_ms($room, $seq);
    // 締め切りはサーバーの時計で決める（画面のタイマーは目安。猶予は通信の遅れのぶんだけ）
    if ($now < $open - BATTLE_EARLY_MS || $now > $close + BATTLE_GRACE_MS) {
        fail('closed', 409);
    }
    $row = battle_room_question($pdo, (int)$room['room_id'], $seq);
    if (!$row) {
        fail('question_missing', 500);
    }
    $isCorrect = $choice === battle_correct_pos((string)$row['choice_order']) ? 1 : 0;
    // 締め切りまでは何度でも選び直せる（最後に届いた答えが有効）。締め切りの判定は上で済んでいる
    $stmt = $pdo->prepare(
        'INSERT INTO battle_answers (room_id, student_id, seq, choice, is_correct, elapsed_ms)
         VALUES (:r, :s, :q, :c, :ok, :el)
         ON DUPLICATE KEY UPDATE choice = VALUES(choice), is_correct = VALUES(is_correct),
           elapsed_ms = VALUES(elapsed_ms), answered_at = NOW()'
    );
    $stmt->execute([
        'r'  => $room['room_id'],
        's'  => $studentId,
        'q'  => $seq,
        'c'  => $choice,
        'ok' => $isCorrect,
        'el' => max(0, $now - $open),
    ]);
    touch_me($pdo, (int)$room['room_id'], $studentId, $now);
    // 正誤はここでは返さない（全員そろって正解発表で知る。先に答えた子が周りに教えられないように）
    json_response(['ok' => true, 'accepted' => true, 'choice' => $choice, 'now_ms' => $now]);
    break;

case 'reveal':
    [$room, $me] = load_for_me($pdo, $in, $studentId, $now);
    $seq = seq_param($in, $room);
    if ($room['status'] === 'cancelled' || $room['status'] === 'lobby') {
        fail('not_playing', 409, ['room' => battle_room_public($room, $now), 'me' => me_public($me)]);
    }
    $close = battle_close_ms($room, $seq);
    if ($now < $close + BATTLE_GRACE_MS) {
        fail('not_yet', 409, ['wait_ms' => $close + BATTLE_GRACE_MS - $now]);
    }
    $row = battle_room_question($pdo, (int)$room['room_id'], $seq);
    if (!$row) {
        fail('question_missing', 500);
    }
    touch_me($pdo, (int)$room['room_id'], $studentId, $now);
    $stmt = $pdo->prepare('SELECT choice, is_correct FROM battle_answers WHERE room_id = :r AND student_id = :s AND seq = :q');
    $stmt->execute(['r' => $room['room_id'], 's' => $studentId, 'q' => $seq]);
    $mine = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM battle_answers WHERE room_id = :r AND seq = :q AND is_correct = 1');
    $stmt->execute(['r' => $room['room_id'], 'q' => $seq]);
    $nCorrect = (int)$stmt->fetchColumn();
    // この問題までの自分の点数。順位は返さない（対戦中は自分の順位を知らせない方針。
    // 画面で隠すだけだと開発者ツールで見えるので、そもそも送らない）
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(is_correct), 0) FROM battle_answers WHERE room_id = :r AND student_id = :s AND seq <= :q'
    );
    $stmt->execute(['r' => $room['room_id'], 's' => $studentId, 'q' => $seq]);
    $myScore = (int)$stmt->fetchColumn() * BATTLE_POINT;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM battle_players WHERE room_id = :r AND status = 'playing'");
    $stmt->execute(['r' => $room['room_id']]);
    $nPlaying = (int)$stmt->fetchColumn();
    json_response([
        'ok'          => true,
        'seq'         => $seq,
        'correct'     => battle_correct_pos((string)$row['choice_order']),
        'mine'        => $mine ? (int)$mine['choice'] : null,
        'is_correct'  => $mine ? (int)$mine['is_correct'] === 1 : false,
        'explanation' => (string)$row['explanation'],
        'n_correct'   => $nCorrect,
        'n_playing'   => $nPlaying,
        'score'       => $myScore,
        'me'          => me_public($me),
        'now_ms'      => $now,
    ]);
    break;

case 'dq':
    if (!$isPost) {
        fail('method_not_allowed', 405);
    }
    $reason = (string)($in['reason'] ?? '');
    if (!in_array($reason, ['hidden', 'blur', 'pagehide'], true)) {
        $reason = 'hidden';
    }
    [$room, $me] = load_for_me($pdo, $in, $studentId, $now);
    // 1問目が出てから終わるまでだけ。カウントダウン中は問題が見えていない＝離れても調べられるものが無いので
    // 失格にしない（iPhone が画面の暗転・通知で「裏に回った」を出し、スタートと同時の失格が続いたため）。
    // 終わったあとに画面を閉じても失格にはしない
    if ($room['status'] === 'playing' && $now >= (int)$room['start_ms'] && $now < battle_end_ms($room)
        && $me['status'] === 'playing') {
        $pdo->prepare(
            "UPDATE battle_players SET status = 'dq', dq_reason = :why, dq_seq = :seq, dq_at = NOW()
             WHERE room_id = :r AND student_id = :s AND status = 'playing'"
        )->execute([
            'why' => $reason,
            'seq' => battle_seq_at($room, $now),
            'r'   => $room['room_id'],
            's'   => $studentId,
        ]);
        $me = my_player($pdo, (int)$room['room_id'], $studentId);
    }
    json_response(['ok' => true, 'me' => me_public($me), 'now_ms' => $now]);
    break;

case 'result':
    [$room, $me] = load_for_me($pdo, $in, $studentId, $now);
    if ($room['status'] === 'cancelled') {
        fail('cancelled', 409, ['room' => battle_room_public($room, $now)]);
    }
    if ($room['status'] !== 'finished') {
        $wait = $room['status'] === 'playing' ? max(0, battle_end_ms($room) - $now) : null;
        fail('not_yet', 409, ['wait_ms' => $wait, 'room' => battle_room_public($room, $now), 'me' => me_public($me)]);
    }
    $standings = battle_standings($pdo, (int)$room['room_id']);
    $teamStandings = battle_team_standings($room, $standings);
    $mine = null;
    foreach ($standings as &$s) {
        $s['me'] = $s['student_id'] === $studentId;
        if ($s['me']) {
            $mine = $s;
        }
        unset($s['student_id']);   // ほかの生徒の内部IDは画面に出さない
    }
    unset($s);
    json_response([
        'ok'        => true,
        'room'      => battle_room_public($room, $now),
        'me'        => me_public($me),
        'mine'      => $mine,
        'standings' => $standings,
        'team_standings' => $teamStandings,   // チーム戦の順位（個人戦は空）。自分のチームは mine.team で分かる
        'review'    => battle_review($pdo, (int)$room['room_id'], $studentId),
        'now_ms'    => $now,
    ]);
    break;

default:
    fail('unknown_action');
}
