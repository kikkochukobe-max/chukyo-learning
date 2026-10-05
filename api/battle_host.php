<?php
declare(strict_types=1);

// 常識バトル（講師側）。/battle_host.php の画面が呼ぶ。部屋を作れるのは講師だけ。
//   GET  ?action=rooms                   … 自分の部屋の一覧（開いている部屋＋最近の対戦）
//   POST {action:'create', level, count} … 部屋を作る（難易度 1〜4 / 問題数 10〜100 の5問きざみ）。4桁の部屋番号を返す
//   POST {action:'start', room_id}       … スタート（待合室で画面を開いている生徒だけが参加する）
//   POST {action:'cancel', room_id}      … 部屋を閉じる（待合室・対戦中どちらでも）
//   GET  ?action=state&room_id=          … 進行状況（待合室の顔ぶれ / いまの問題・回答数・途中順位）
//   GET  ?action=result&room_id=         … 最終順位と全問の正答数
// 操作できるのは部屋を作った講師と統括（super_admin）。
require_once __DIR__ . '/battle_common.php';

$actor = require_login(['teacher']);
session_write_close();

$teacherId = (int)$actor['id'];
$pdo = db();
$now = battle_now_ms();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$in = $isPost ? json_input() : $_GET;
$action = (string)($in['action'] ?? '');

$stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :t AND is_active = 1');
$stmt->execute(['t' => $teacherId]);
$role = $stmt->fetchColumn();
if ($role === false) {
    json_response(['ok' => false, 'error' => 'unauthenticated'], 401);
}
$isSuper = $role === 'super_admin';

function fail(string $error, int $status = 400, array $extra = []): void
{
    global $now;
    json_response(array_merge(['ok' => false, 'error' => $error, 'now_ms' => $now], $extra), $status);
}

function require_post_action(bool $isPost): void
{
    if (!$isPost) {
        fail('method_not_allowed', 405);
    }
}

// 部屋を読み、操作してよいか確かめ、状態を「いま」に合わせる
function load_my_room(PDO $pdo, array $in, int $teacherId, bool $isSuper, int $now): array
{
    $roomId = (int)($in['room_id'] ?? 0);
    $room = $roomId > 0 ? battle_load_room($pdo, $roomId) : null;
    if (!$room) {
        fail('room_not_found', 404);
    }
    if ((int)$room['host_teacher_id'] !== $teacherId && !$isSuper) {
        fail('forbidden', 403);
    }
    return battle_tick($pdo, $room, $now);
}

switch ($action) {

case 'rooms':
    battle_sweep_rooms($pdo, $now);
    $stmt = $pdo->prepare(
        "SELECT r.*,
                (SELECT COUNT(*) FROM battle_players p
                  WHERE p.room_id = r.room_id AND p.status IN ('playing', 'dq')) AS n_players
         FROM battle_rooms r
         WHERE r.host_teacher_id = :t
         ORDER BY r.room_id DESC LIMIT 15"
    );
    $stmt->execute(['t' => $teacherId]);
    $rooms = [];
    $open = null;
    foreach ($stmt->fetchAll() as $r) {
        $pub = battle_room_public($r, $now);
        unset($pub['schedule']);   // 一覧には要らない（1部屋100件ぶんの進行表）
        $pub['n_players'] = (int)$r['n_players'];
        $pub['created_at'] = (string)$r['created_at'];
        $rooms[] = $pub;
        if ($open === null && in_array($r['status'], ['lobby', 'playing'], true)) {
            $open = (int)$r['room_id'];
        }
    }
    // 難易度ごとの出題できる問題数（seed を流し忘れていると部屋が作れないので、画面に出す）と、
    // そのうち計算が要る問題の数（終わるまでの時間の目安に使う）
    $hasCalc = battle_has_calc($pdo);
    $avail = [];
    $calcN = [];
    $sql = 'SELECT level, COUNT(*) AS n, ' . ($hasCalc ? 'SUM(needs_calc)' : '0') . ' AS c
            FROM battle_questions WHERE is_active = 1 GROUP BY level';
    foreach ($pdo->query($sql)->fetchAll() as $row) {
        $avail[(int)$row['level']] = (int)$row['n'];
        $calcN[(int)$row['level']] = (int)$row['c'];
    }
    $levels = [];
    foreach (BATTLE_LEVELS as $lv => $def) {
        $levels[] = [
            'level'     => $lv,
            'label'     => $def['label'],
            'sec'       => $def['sec'],
            'calc_sec'  => $hasCalc ? $def['calc_sec'] : $def['sec'],
            'available' => $avail[$lv] ?? 0,
            'calc'      => $calcN[$lv] ?? 0,
        ];
    }
    json_response([
        'ok'      => true,
        'open'    => $open,
        'rooms'   => $rooms,
        'levels'  => $levels,
        'count'   => ['min' => BATTLE_COUNT_MIN, 'max' => BATTLE_COUNT_MAX, 'step' => BATTLE_COUNT_STEP],
        'now_ms'  => $now,
    ]);
    break;

case 'create':
    require_post_action($isPost);
    $level = (int)($in['level'] ?? 0);
    $count = (int)($in['count'] ?? 0);
    if (!isset(BATTLE_LEVELS[$level])) {
        fail('invalid_level');
    }
    if ($count < BATTLE_COUNT_MIN || $count > BATTLE_COUNT_MAX || ($count - BATTLE_COUNT_MIN) % BATTLE_COUNT_STEP !== 0) {
        fail('invalid_count');
    }
    battle_sweep_rooms($pdo, $now);
    // 開いている部屋があるうちは作らせない（閉じ忘れた部屋に生徒が入ってしまうのを防ぐ）
    $stmt = $pdo->prepare(
        "SELECT room_id FROM battle_rooms
         WHERE host_teacher_id = :t AND status IN ('lobby', 'playing')
         ORDER BY room_id DESC LIMIT 1"
    );
    $stmt->execute(['t' => $teacherId]);
    $openId = $stmt->fetchColumn();
    if ($openId !== false) {
        fail('room_open', 409, ['room_id' => (int)$openId]);
    }
    // 出題する問題（難易度の中からランダム。カテゴリは混ざる）。計算が要る問題は制限時間を長くする
    $hasCalc = battle_has_calc($pdo);
    $picked = $pdo->query(
        'SELECT question_id, ' . ($hasCalc ? 'needs_calc' : '0') . ' AS needs_calc FROM battle_questions
         WHERE level = ' . $level . ' AND is_active = 1
         ORDER BY RAND() LIMIT ' . $count
    )->fetchAll();
    if (count($picked) < $count) {
        fail('not_enough_questions', 409, ['available' => count($picked)]);
    }
    // 部屋番号: 待合室・対戦中の部屋と重ならない4桁（1000〜9999。先頭の0で迷わせない）
    $code = null;
    $chk = $pdo->prepare("SELECT 1 FROM battle_rooms WHERE room_code = :c AND status IN ('lobby', 'playing') LIMIT 1");
    for ($i = 0; $i < 30; $i++) {
        $try = (string)random_int(1000, 9999);
        $chk->execute(['c' => $try]);
        if ($chk->fetchColumn() === false) {
            $code = $try;
            break;
        }
    }
    if ($code === null) {
        fail('code_exhausted', 503);
    }
    $pdo->beginTransaction();
    $pdo->prepare(
        'INSERT INTO battle_rooms (room_code, host_teacher_id, level, question_count, time_limit_sec, reveal_sec)
         VALUES (:c, :t, :l, :n, :sec, :rev)'
    )->execute([
        'c'   => $code,
        't'   => $teacherId,
        'l'   => $level,
        'n'   => $count,
        'sec' => BATTLE_LEVELS[$level]['sec'],
        'rev' => BATTLE_REVEAL_SEC,
    ]);
    $roomId = (int)$pdo->lastInsertId();
    if ($hasCalc) {
        $ins = $pdo->prepare(
            'INSERT INTO battle_room_questions (room_id, seq, question_id, choice_order, limit_sec)
             VALUES (:r, :s, :q, :o, :lim)'
        );
        foreach (array_values($picked) as $seq => $p) {
            $lim = (int)$p['needs_calc'] === 1 ? BATTLE_LEVELS[$level]['calc_sec'] : BATTLE_LEVELS[$level]['sec'];
            $ins->execute(['r' => $roomId, 's' => $seq, 'q' => (int)$p['question_id'], 'o' => battle_shuffle_order(), 'lim' => $lim]);
        }
    } else {
        // migrate_joshiki_battle_calc.sql をまだ流していない: 全問 time_limit_sec で進む
        $ins = $pdo->prepare(
            'INSERT INTO battle_room_questions (room_id, seq, question_id, choice_order) VALUES (:r, :s, :q, :o)'
        );
        foreach (array_values($picked) as $seq => $p) {
            $ins->execute(['r' => $roomId, 's' => $seq, 'q' => (int)$p['question_id'], 'o' => battle_shuffle_order()]);
        }
    }
    $pdo->commit();
    json_response(['ok' => true, 'room_id' => $roomId, 'code' => $code, 'now_ms' => $now]);
    break;

case 'start':
    require_post_action($isPost);
    $room = load_my_room($pdo, $in, $teacherId, $isSuper, $now);
    if ($room['status'] !== 'lobby') {
        fail('not_lobby', 409);
    }
    $roomId = (int)$room['room_id'];
    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT status FROM battle_rooms WHERE room_id = :r FOR UPDATE');
    $lock->execute(['r' => $roomId]);
    if ($lock->fetchColumn() !== 'lobby') {
        $pdo->rollBack();
        fail('not_lobby', 409);
    }
    // 画面を開いていない（しばらく通信が無い）生徒は参加させない。スタートの瞬間に
    // 別のアプリを見ていた子が、戻ってきたら途中から始まっていて失格、にならないように
    $pdo->prepare(
        "UPDATE battle_players SET status = 'left'
         WHERE room_id = :r AND status = 'waiting' AND last_seen_ms < :th"
    )->execute(['r' => $roomId, 'th' => $now - BATTLE_LOBBY_ALIVE_MS]);
    $upd = $pdo->prepare("UPDATE battle_players SET status = 'playing' WHERE room_id = :r AND status = 'waiting'");
    $upd->execute(['r' => $roomId]);
    $n = $upd->rowCount();
    if ($n === 0) {
        $pdo->rollBack();
        fail('no_players', 409);
    }
    $pdo->prepare(
        "UPDATE battle_rooms SET status = 'playing', start_ms = :s, started_at = NOW()
         WHERE room_id = :r AND status = 'lobby'"
    )->execute(['s' => $now + BATTLE_COUNTDOWN_MS, 'r' => $roomId]);
    $pdo->commit();
    $room = battle_load_room($pdo, $roomId);
    json_response(['ok' => true, 'room' => battle_room_public($room, $now), 'n_players' => $n, 'now_ms' => $now]);
    break;

case 'cancel':
    require_post_action($isPost);
    $room = load_my_room($pdo, $in, $teacherId, $isSuper, $now);
    $pdo->prepare(
        "UPDATE battle_rooms SET status = 'cancelled', finished_at = NOW()
         WHERE room_id = :r AND status IN ('lobby', 'playing')"
    )->execute(['r' => $room['room_id']]);
    json_response(['ok' => true, 'now_ms' => $now]);
    break;

case 'state':
    $room = load_my_room($pdo, $in, $teacherId, $isSuper, $now);
    $roomId = (int)$room['room_id'];
    $pub = battle_room_public($room, $now);
    $out = ['ok' => true, 'room' => $pub, 'now_ms' => $now];
    if ($room['status'] === 'lobby') {
        $out['players'] = battle_lobby_players($pdo, $roomId, $now);
    } elseif ($room['status'] === 'playing' || $room['status'] === 'finished') {
        $seq = $pub['seq'];
        $revealed = -1;   // 正解を発表し終えた問題（順位はここまでの点数で出す＝講師の画面を映しても答えがばれない）
        $q = null;
        if ($seq !== null) {
            $close = battle_close_ms($room, $seq);
            $isRevealed = $now >= $close + BATTLE_GRACE_MS;
            $revealed = $isRevealed ? $seq : $seq - 1;
            $row = battle_room_question($pdo, $roomId, $seq);
            if ($row) {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) AS n, COALESCE(SUM(is_correct), 0) AS ok FROM battle_answers WHERE room_id = :r AND seq = :q'
                );
                $stmt->execute(['r' => $roomId, 'q' => $seq]);
                $cnt = $stmt->fetch();
                $q = battle_question_payload($row);
                $q['no'] = $seq + 1;
                $q['open_ms'] = battle_open_ms($room, $seq);
                $q['close_ms'] = $close;
                $q['n_answered'] = (int)$cnt['n'];
                if ($isRevealed) {
                    $q['correct'] = battle_correct_pos((string)$row['choice_order']);
                    $q['n_correct'] = (int)$cnt['ok'];
                    $q['explanation'] = (string)$row['explanation'];
                }
            }
        } elseif ($pub['phase'] === 'finished') {
            $revealed = (int)$room['question_count'] - 1;
        }
        $out['question'] = $q;
        $out['standings'] = battle_standings($pdo, $roomId, $revealed);
    }
    json_response($out);
    break;

case 'result':
    $room = load_my_room($pdo, $in, $teacherId, $isSuper, $now);
    if ($room['status'] !== 'finished') {
        fail('not_finished', 409, ['room' => battle_room_public($room, $now)]);
    }
    json_response([
        'ok'        => true,
        'room'      => battle_room_public($room, $now),
        'standings' => battle_standings($pdo, (int)$room['room_id']),
        'review'    => battle_review($pdo, (int)$room['room_id'], null),
        'now_ms'    => $now,
    ]);
    break;

default:
    fail('unknown_action');
}
