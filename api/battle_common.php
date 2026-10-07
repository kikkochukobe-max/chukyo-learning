<?php
declare(strict_types=1);

// 一般常識バトル（小学校高学年向け 6択クイズの一斉対戦）の共通定義。
// api/battle_host.php（講師）/ api/battle_play.php（生徒）が読む。
//
// 進行はすべて「スタート時刻 start_ms からの経過」で決まる（サーバーが状態を進めて回る必要がない）:
//   start_ms + offset[seq]          … seq問目の出題（seq は0始まり。offset はそれより前の問題の
//                                     （制限時間＋正解発表）の合計。battle_schedule() が作る）
//   出題 + limit[seq]               … 締め切り（画面はここでボタンを止める）。制限時間は問題ごと
//                                     （難しい・超難は計算が要る問題だけ長い＝battle_room_questions.limit_sec）
//   締め切り + BATTLE_GRACE_MS      … ここまでに届いた解答は受け付ける（通信の遅れのぶん）。正解はこの後でしか返さない
//   締め切り + 正解発表             … 次の問題
//   最後の問題の正解発表が終わる時刻 … 終了
// 時刻は PHP の時計（UNIXミリ秒）だけで決める。DB の NOW() と混ぜない
// （Heteml は PHP と MySQL が別のマシンなので、混ぜると時計の差がそのまま締め切りのずれになる）。
// 端末の時計は信用しない（画面は応答の now_ms との差で時計を合わせるだけ）。
//
// 学習記録（answer_logs / XP / 解き直し / 学習時間）には一切書かない。バトルだけで完結する。
// テーブルは db/migrations/migrate_joshiki_battle.sql、問題は db/seeds/seed_joshiki_battle_lv*.sql。
// チーム戦（部屋の team_count が2以上）は db/migrations/migrate_joshiki_battle_team.sql。
// 生徒は待合室でチームを選び、順位はチームの合計点（平均点も並べる）。

require_once __DIR__ . '/bootstrap.php';

// 難易度 → 表示名と1問の制限時間（秒）。sec=ふつうの問題、calc_sec=計算が要る問題（needs_calc=1）。
// 部屋を作った時点の値が battle_rooms / battle_room_questions に写るので、ここを変えても進行中の部屋はずれない
const BATTLE_LEVELS = [
    1 => ['label' => '易しい', 'sec' => 15, 'calc_sec' => 15],
    2 => ['label' => '普通',   'sec' => 15, 'calc_sec' => 15],
    3 => ['label' => '難しい', 'sec' => 20, 'calc_sec' => 60],
    4 => ['label' => '超難',   'sec' => 20, 'calc_sec' => 60],
];
const BATTLE_COUNT_MIN  = 10;
const BATTLE_COUNT_MAX  = 100;
const BATTLE_COUNT_STEP = 5;
const BATTLE_POINT      = 10;      // 1問の点数

const BATTLE_REVEAL_SEC     = 5;      // 締め切りから次の問題まで（正解発表）
const BATTLE_COUNTDOWN_MS   = 5000;   // スタートを押してから1問目まで
const BATTLE_GRACE_MS       = 1500;   // 締め切り後も受け付ける通信の遅れ
const BATTLE_EARLY_MS       = 500;    // 出題時刻より少し早い問い合わせも受ける（端末の時計合わせの誤差）
const BATTLE_LOST_MS        = 20000;  // 対戦中にこれだけ通信が無ければ失格（画面を閉じた・電波が切れた）
const BATTLE_LOBBY_ALIVE_MS = 10000;  // スタートの時にこれより前から通信が無い生徒は参加させない（画面を開いていない）
const BATTLE_LOBBY_EXPIRE_H = 3;      // 待合室のまま放置された部屋はこの時間で自動で閉じる

const BATTLE_CATEGORIES = [
    'kotoba'   => 'ことば',
    'kanji'    => '漢字',
    'sansu'    => '算数',
    'ikimono'  => '生き物と人体',
    'chikyu'   => '地球と物質',
    'chiri'    => '地理',
    'rekishi'  => '歴史',
    'kurashi'  => 'くらしと政治',
    'eigo'     => '英語',
    'seikatsu' => '生活と文化',
];

// チーム戦のチーム（番号 → 名前と色）。部屋の team_count が 2 以上なら、1〜team_count 番を使う。
// 名前は小学生が読めるように（「紫」は中学の漢字なので「むらさき」）
const BATTLE_TEAMS = [
    1 => ['name' => '赤',       'color' => '#D9483B'],
    2 => ['name' => '青',       'color' => '#2F6FB5'],
    3 => ['name' => '黄',       'color' => '#C99A00'],
    4 => ['name' => '緑',       'color' => '#3E8E5A'],
    5 => ['name' => 'むらさき', 'color' => '#7B4FA8'],
    6 => ['name' => 'オレンジ', 'color' => '#E07B22'],
];
const BATTLE_TEAM_MIN = 2;

// 失格の理由（画面から届くのは hidden / blur / pagehide の3つだけ。reload と lost はサーバーが付ける）
const BATTLE_DQ_REASONS = [
    'hidden'   => 'ほかのアプリ・タブを開いた',
    'blur'     => '別のウィンドウに切りかえた',
    'pagehide' => '画面を閉じた・画面が消えた',   // iPhone は画面が消えた（自動ロック）時も pagehide を先に出す
    'reload'   => '画面を開き直した',
    'lost'     => '通信が途絶えた',
];

function battle_now_ms(): int
{
    return (int)floor(microtime(true) * 1000);
}

// migrate_joshiki_battle_calc.sql（needs_calc / limit_sec の列）を流してあるか。
// 流す前に PHP だけ上げても、全問ふつうの制限時間で動くようにするため
function battle_has_calc(PDO $pdo): bool
{
    return table_has_column($pdo, 'battle_questions', 'needs_calc')
        && table_has_column($pdo, 'battle_room_questions', 'limit_sec');
}

// migrate_joshiki_battle_team.sql（team_count / team の列）を流してあるか。
// 流す前に PHP だけ上げても個人戦は動くようにするため（チーム戦の部屋だけ作れない）
function battle_has_team(PDO $pdo): bool
{
    static $has = null;
    if ($has === null) {
        $has = table_has_column($pdo, 'battle_rooms', 'team_count')
            && table_has_column($pdo, 'battle_players', 'team');
    }
    return $has;
}

// migrate_joshiki_battle_practice.sql（is_practice の列）を流してあるか。
// 流す前は全部の部屋が本番あつかい（練習の部屋が作れないだけで、対戦と合算は動く）
function battle_has_practice(PDO $pdo): bool
{
    static $has = null;
    if ($has === null) {
        $has = table_has_column($pdo, 'battle_rooms', 'is_practice');
    }
    return $has;
}

function battle_is_practice(array $room): bool
{
    return (int)($room['is_practice'] ?? 0) === 1;
}

// 合算に選べる回の上限（1回の合算で足す部屋の数）
const BATTLE_TOTAL_MAX = 20;

// 合算: 何回かの対戦（終了した本番の部屋）の点数を足した順位。$rooms は battle_rooms の行を古い順に。
//  * 1回ぶんの点数は battle_standings() のまま＝失格した回も、失格するまでに取った点は数える
//    （チーム戦の合計と同じ考え方。失格した回には印を付け、失格の回数も並べる）
//  * 出ていない回は 0点（列は空欄）。参加回数がそろわない時に講師が判断できるよう、参加回数と
//    正答率（正解 ÷ 出ていた回の問題数）も並べる。順位は合計点だけで決める（同点は同じ順位）
//  * チームの合算は、選んだ回がすべてチーム戦のときだけ。チームは番号（色）で足す
//    （回ごとに顔ぶれが変わってもよい。1人1回あたりの平均点も出す）
function battle_total_standings(PDO $pdo, array $rooms): array
{
    $n = count($rooms);
    $rounds = [];
    $students = [];
    $teams = [];
    $allTeam = $n > 0;
    foreach ($rooms as $i => $room) {
        $roomId = (int)$room['room_id'];
        $standings = battle_standings($pdo, $roomId);
        $rounds[] = [
            'room_id'     => $roomId,
            'no'          => $i + 1,
            'code'        => (string)$room['room_code'],
            'date'        => (string)($room['started_at'] ?? $room['created_at']),
            'level_label' => BATTLE_LEVELS[(int)$room['level']]['label'] ?? '',
            'count'       => (int)$room['question_count'],
            'teams'       => count(battle_room_teams($room)),
            'n_players'   => count($standings),
        ];
        foreach ($standings as $s) {
            $sid = $s['student_id'];
            if (!isset($students[$sid])) {
                $students[$sid] = [
                    'student_id' => $sid,
                    'name'       => $s['name'],
                    'classroom'  => $s['classroom'],
                    'total'      => 0,
                    'correct'    => 0,
                    'asked'      => 0,
                    'rounds'     => 0,
                    'n_dq'       => 0,
                    'per'        => array_fill(0, $n, null),
                ];
            }
            $p = &$students[$sid];
            $p['total'] += $s['score'];
            $p['correct'] += $s['correct'];
            $p['asked'] += (int)$room['question_count'];
            $p['rounds']++;
            if ($s['dq']) {
                $p['n_dq']++;
            }
            $p['per'][$i] = ['score' => $s['score'], 'rank' => $s['rank'], 'dq' => $s['dq'], 'team' => $s['team']];
            unset($p);
        }
        if (count(battle_room_teams($room)) === 0) {
            $allTeam = false;
            continue;
        }
        foreach (battle_team_standings($room, $standings) as $t) {
            $no = $t['team'];
            if (!isset($teams[$no])) {
                $teams[$no] = [
                    'team'    => $no,
                    'name'    => $t['name'],
                    'color'   => $t['color'],
                    'total'   => 0,
                    'members' => 0,   // 延べ人数（回ごとの人数の合計）。平均点＝1人1回あたり
                    'n_dq'    => 0,
                    'per'     => array_fill(0, $n, null),
                ];
            }
            $teams[$no]['total'] += $t['total'];
            $teams[$no]['members'] += $t['members'];
            $teams[$no]['n_dq'] += $t['n_dq'];
            $teams[$no]['per'][$i] = ['total' => $t['total'], 'rank' => $t['rank'], 'members' => $t['members']];
        }
    }

    $list = array_values($students);
    usort($list, function ($a, $b) {
        return [$b['total'], $a['name']] <=> [$a['total'], $b['name']];
    });
    $rank = 0;
    $prev = null;
    foreach ($list as $i => &$s) {
        if ($prev === null || $s['total'] !== $prev) {
            $rank = $i + 1;
            $prev = $s['total'];
        }
        $s['rank'] = $rank;
        $s['rate'] = $s['asked'] > 0 ? round(100 * $s['correct'] / $s['asked'], 1) : 0.0;
    }
    unset($s);

    $teamList = null;
    if ($allTeam) {
        $teamList = array_values($teams);
        usort($teamList, function ($a, $b) { return [$b['total'], $a['team']] <=> [$a['total'], $b['team']]; });
        $rank = 0;
        $prev = null;
        foreach ($teamList as $i => &$t) {
            if ($prev === null || $t['total'] !== $prev) {
                $rank = $i + 1;
                $prev = $t['total'];
            }
            $t['rank'] = $rank;
            $t['avg'] = $t['members'] > 0 ? round($t['total'] / $t['members'], 1) : 0.0;
        }
        unset($t);
    }

    return ['rounds' => $rounds, 'standings' => $list, 'team_standings' => $teamList];
}

// 部屋のチーム一覧 [[team, name, color], …]。個人戦なら空
function battle_room_teams(array $room): array
{
    $out = [];
    for ($t = 1, $n = (int)($room['team_count'] ?? 0); $t <= $n && isset(BATTLE_TEAMS[$t]); $t++) {
        $out[] = ['team' => $t, 'name' => BATTLE_TEAMS[$t]['name'], 'color' => BATTLE_TEAMS[$t]['color']];
    }
    return $out;
}

// チームの順位。battle_standings() の結果をチームごとに足す。
//   total = チーム全員の点数の合計（失格した生徒も、失格するまでに取ったぶんは数える）
//   avg   = 1人あたりの平均点（人数がそろわない時の目安。順位は total で決める）
// 1人もいないチームは載せない。同点は同じ順位（1,1,3…）
function battle_team_standings(array $room, array $standings): array
{
    $teams = [];
    foreach (battle_room_teams($room) as $t) {
        $teams[$t['team']] = $t + ['members' => 0, 'total' => 0, 'correct' => 0, 'n_dq' => 0];
    }
    foreach ($standings as $s) {
        $t = $s['team'] ?? null;
        if ($t === null || !isset($teams[$t])) {
            continue;
        }
        $teams[$t]['members']++;
        $teams[$t]['total'] += $s['score'];
        $teams[$t]['correct'] += $s['correct'];
        if ($s['dq']) {
            $teams[$t]['n_dq']++;
        }
    }
    $list = array_values(array_filter($teams, function ($t) { return $t['members'] > 0; }));
    usort($list, function ($a, $b) { return [$b['total'], $a['team']] <=> [$a['total'], $b['team']]; });
    $rank = 0;
    $prev = null;
    foreach ($list as $i => &$t) {
        if ($prev === null || $t['total'] !== $prev) {
            $rank = $i + 1;
            $prev = $t['total'];
        }
        $t['rank'] = $rank;
        $t['avg'] = round($t['total'] / $t['members'], 1);
    }
    unset($t);
    return $list;
}

// スタートの時にまだチームを選んでいない参加者を、人数の少ないチームへ入れる（同じ人数なら番号の若い方）
function battle_fill_teams(PDO $pdo, array $room): void
{
    $n = (int)($room['team_count'] ?? 0);
    if ($n < BATTLE_TEAM_MIN) {
        return;
    }
    $roomId = (int)$room['room_id'];
    $count = array_fill(1, $n, 0);
    $stmt = $pdo->prepare(
        "SELECT team, COUNT(*) AS c FROM battle_players
         WHERE room_id = :r AND status = 'playing' AND team IS NOT NULL GROUP BY team"
    );
    $stmt->execute(['r' => $roomId]);
    foreach ($stmt->fetchAll() as $row) {
        if (isset($count[(int)$row['team']])) {
            $count[(int)$row['team']] = (int)$row['c'];
        }
    }
    $stmt = $pdo->prepare(
        "SELECT student_id FROM battle_players
         WHERE room_id = :r AND status = 'playing' AND (team IS NULL OR team < 1 OR team > :n)"
    );
    $stmt->execute(['r' => $roomId, 'n' => $n]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    shuffle($ids);
    $upd = $pdo->prepare('UPDATE battle_players SET team = :t WHERE room_id = :r AND student_id = :s');
    foreach ($ids as $sid) {
        $t = array_search(min($count), $count, true);
        $upd->execute(['t' => $t, 'r' => $roomId, 's' => $sid]);
        $count[$t]++;
    }
}

// 部屋の進行表: seq ごとの [出題までのミリ秒（start_ms から）, 制限時間（秒）]。
// 出題時刻は保存せず、制限時間の積み上げで毎回作る（保存値とずれる心配が無い）。
// limit_sec が無い・0の部屋（migrate_joshiki_battle_calc.sql より前の部屋）は部屋の time_limit_sec で進む。
// 部屋を作ったあと進行表は変わらないので、1回のリクエストの中ではキャッシュする
function battle_schedule(array $room): array
{
    static $cache = [];
    $id = (int)$room['room_id'];
    if (isset($cache[$id])) {
        return $cache[$id];
    }
    $limits = [];
    try {
        $stmt = db()->prepare('SELECT seq, limit_sec FROM battle_room_questions WHERE room_id = :r');
        $stmt->execute(['r' => $id]);
        foreach ($stmt->fetchAll() as $row) {
            $limits[(int)$row['seq']] = (int)$row['limit_sec'];
        }
    } catch (Throwable $e) {
        $limits = [];   // limit_sec 列がまだ無い（ALTER 前）
    }
    $base = (int)$room['time_limit_sec'];
    $reveal = (int)$room['reveal_sec'];
    $sched = [];
    $off = 0;
    for ($i = 0, $n = (int)$room['question_count']; $i < $n; $i++) {
        $lim = ($limits[$i] ?? 0) > 0 ? $limits[$i] : $base;
        $sched[] = [$off, $lim];
        $off += ($lim + $reveal) * 1000;
    }
    $cache[$id] = $sched;
    return $sched;
}

function battle_open_ms(array $room, int $seq): int
{
    return (int)$room['start_ms'] + battle_schedule($room)[$seq][0];
}

function battle_close_ms(array $room, int $seq): int
{
    return battle_open_ms($room, $seq) + battle_schedule($room)[$seq][1] * 1000;
}

function battle_end_ms(array $room): int
{
    $sched = battle_schedule($room);
    $last = $sched[count($sched) - 1];
    return (int)$room['start_ms'] + $last[0] + ($last[1] + (int)$room['reveal_sec']) * 1000;
}

// いまの段階。phase = lobby / countdown / question / reveal / finished / cancelled
function battle_position(array $room, int $now): array
{
    $status = (string)$room['status'];
    if ($status === 'lobby' || $status === 'cancelled') {
        return ['phase' => $status, 'seq' => null];
    }
    $start = (int)$room['start_ms'];
    if ($now < $start) {
        return ['phase' => 'countdown', 'seq' => null];
    }
    if ($status === 'finished' || $now >= battle_end_ms($room)) {
        return ['phase' => 'finished', 'seq' => null];
    }
    $seq = battle_seq_at($room, $now);
    $phase = ($now < battle_close_ms($room, $seq)) ? 'question' : 'reveal';
    return ['phase' => $phase, 'seq' => $seq];
}

// ある時刻に「何問目だったか」（出題時刻がその時刻以前の、いちばん後ろの問題）。カウントダウン中は null
function battle_seq_at(array $room, int $ms): ?int
{
    $rel = $ms - (int)$room['start_ms'];
    if ($rel < 0) {
        return null;
    }
    $seq = 0;
    foreach (battle_schedule($room) as $i => $s) {
        if ($s[0] <= $rel) {
            $seq = $i;
        } else {
            break;
        }
    }
    return $seq;
}

function battle_load_room(PDO $pdo, int $roomId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM battle_rooms WHERE room_id = :r');
    $stmt->execute(['r' => $roomId]);
    $room = $stmt->fetch();
    return $room ?: null;
}

// 部屋の状態を「いま」に合わせる（読むたびに呼ぶ。cron は使わない）:
//  * 対戦中に通信が途絶えた生徒を失格にする（画面を閉じて sendBeacon も届かなかった場合の歯止め）
//  * 終了時刻を過ぎていたら finished にする
// 途絶えの判定は「終了時刻まで」で切る。終わったあとで結果を見に来た時に、
// 最後まで解いた生徒が「終わってから通信が無い」せいで失格にされないように
function battle_tick(PDO $pdo, array $room, int $now): array
{
    if ($room['status'] !== 'playing') {
        return $room;
    }
    $end = battle_end_ms($room);
    $limit = min($now, $end) - BATTLE_LOST_MS;
    if ($limit > (int)$room['start_ms']) {
        $stmt = $pdo->prepare(
            "SELECT student_id, last_seen_ms FROM battle_players
             WHERE room_id = :r AND status = 'playing' AND last_seen_ms < :lim"
        );
        $stmt->execute(['r' => $room['room_id'], 'lim' => $limit]);
        $upd = $pdo->prepare(
            "UPDATE battle_players
             SET status = 'dq', dq_reason = 'lost', dq_seq = :seq, dq_at = NOW()
             WHERE room_id = :r AND student_id = :s AND status = 'playing'"
        );
        foreach ($stmt->fetchAll() as $p) {
            $upd->execute([
                'seq' => battle_seq_at($room, (int)$p['last_seen_ms']),
                'r'   => $room['room_id'],
                's'   => $p['student_id'],
            ]);
        }
    }
    if ($now >= $end) {
        $pdo->prepare(
            "UPDATE battle_rooms SET status = 'finished', finished_at = NOW()
             WHERE room_id = :r AND status = 'playing'"
        )->execute(['r' => $room['room_id']]);
        $room['status'] = 'finished';
    }
    return $room;
}

// 放置された部屋の後片付け（部屋を作る時に呼ぶ）
function battle_sweep_rooms(PDO $pdo, int $now): void
{
    $pdo->exec(
        "UPDATE battle_rooms SET status = 'cancelled', finished_at = NOW()
         WHERE status = 'lobby' AND created_at < NOW() - INTERVAL " . (int)BATTLE_LOBBY_EXPIRE_H . " HOUR"
    );
    $rows = $pdo->query("SELECT * FROM battle_rooms WHERE status = 'playing'")->fetchAll();
    foreach ($rows as $room) {
        if ($now >= battle_end_ms($room)) {
            battle_tick($pdo, $room, $now);
        }
    }
}

// 画面に渡す部屋の情報（正解は含まない）
function battle_room_public(array $room, int $now): array
{
    $level = (int)$room['level'];
    $pos = battle_position($room, $now);
    return [
        'room_id'     => (int)$room['room_id'],
        'code'        => (string)$room['room_code'],
        'level'       => $level,
        'level_label' => BATTLE_LEVELS[$level]['label'] ?? '',
        'count'       => (int)$room['question_count'],
        'limit_sec'   => (int)$room['time_limit_sec'],                  // ふつうの問題の制限時間（表示用）
        'calc_sec'    => BATTLE_LEVELS[$level]['calc_sec'] ?? (int)$room['time_limit_sec'],   // 計算が要る問題（表示用）
        'reveal_sec'  => (int)$room['reveal_sec'],
        // 進行表 [[出題までのミリ秒, 制限時間秒], …]。画面はこれで何問目か・締め切りを計算する（問題の中身は入らない）
        'schedule'    => battle_schedule($room),
        'grace_ms'    => BATTLE_GRACE_MS,
        'point'       => BATTLE_POINT,
        'teams'       => battle_room_teams($room),   // チーム戦なら [[team, name, color], …]。個人戦は空
        'practice'    => battle_is_practice($room),  // 練習の回（合算に入らない）
        'status'      => (string)$room['status'],
        'start_ms'    => $room['start_ms'] !== null ? (int)$room['start_ms'] : null,
        'phase'       => $pos['phase'],
        'seq'         => $pos['seq'],
        'now_ms'      => $now,
    ];
}

// choice_order（'0'=正解、'1'〜'5'=wrong1〜5 を画面の並び順に並べた6文字）をランダムに作る
function battle_shuffle_order(): string
{
    $a = [0, 1, 2, 3, 4, 5];
    for ($i = 5; $i > 0; $i--) {
        $j = random_int(0, $i);
        $t = $a[$i];
        $a[$i] = $a[$j];
        $a[$j] = $t;
    }
    return implode('', $a);
}

function battle_correct_pos(string $order): int
{
    return (int)strpos($order, '0');
}

// 問題1行（battle_questions + choice_order）→ 画面に出す形（正解の位置は含めない）
function battle_question_payload(array $row): array
{
    $src = [$row['answer'], $row['wrong1'], $row['wrong2'], $row['wrong3'], $row['wrong4'], $row['wrong5']];
    $choices = [];
    foreach (str_split((string)$row['choice_order']) as $d) {
        $choices[] = (string)$src[(int)$d];
    }
    return [
        'text'     => (string)$row['question_text'],
        'choices'  => $choices,
        'category' => BATTLE_CATEGORIES[$row['category']] ?? '',
    ];
}

// 部屋の seq問目（問題本文・選択肢・正解の並び）
function battle_room_question(PDO $pdo, int $roomId, int $seq): ?array
{
    $stmt = $pdo->prepare(
        'SELECT rq.seq, rq.choice_order, q.question_id, q.category, q.question_text, q.answer,
                q.wrong1, q.wrong2, q.wrong3, q.wrong4, q.wrong5, q.explanation
         FROM battle_room_questions rq
         JOIN battle_questions q ON q.question_id = rq.question_id
         WHERE rq.room_id = :r AND rq.seq = :s'
    );
    $stmt->execute(['r' => $roomId, 's' => $seq]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// 順位表。対戦に参加した生徒（playing=完走・対戦中 / dq=失格）を点数順に並べる。
// 同点は同じ順位（1,1,3…）。失格者は順位を付けず最後にまとめる。
// $uptoSeq を渡すと、その問題までの点数で数える（正解発表の途中経過用）
function battle_standings(PDO $pdo, int $roomId, ?int $uptoSeq = null): array
{
    $cond = $uptoSeq === null ? '' : ' AND a.seq <= ' . (int)$uptoSeq;
    $team = battle_has_team($pdo) ? 'p.team' : 'NULL';
    $stmt = $pdo->prepare(
        "SELECT p.student_id, p.status, p.dq_reason, p.dq_seq, " . $team . " AS team, s.student_name, c.classroom_name,
                COALESCE(SUM(a.is_correct), 0) AS correct, COUNT(a.seq) AS answered
         FROM battle_players p
         JOIN students s ON s.student_id = p.student_id
         LEFT JOIN classrooms c ON c.classroom_id = s.classroom_id
         LEFT JOIN battle_answers a
           ON a.room_id = p.room_id AND a.student_id = p.student_id" . $cond . "
         WHERE p.room_id = :r AND p.status IN ('playing', 'dq')
         GROUP BY p.student_id, p.status, p.dq_reason, p.dq_seq, team, s.student_name, c.classroom_name
         ORDER BY (p.status = 'dq'), correct DESC, s.student_name"
    );
    $stmt->execute(['r' => $roomId]);
    $list = [];
    $rank = 0;
    $prev = null;
    $i = 0;
    foreach ($stmt->fetchAll() as $row) {
        $correct = (int)$row['correct'];
        $isDq = $row['status'] === 'dq';
        if (!$isDq) {
            $i++;
            if ($prev === null || $correct !== $prev) {
                $rank = $i;
                $prev = $correct;
            }
        }
        $list[] = [
            'student_id' => (int)$row['student_id'],
            'name'       => (string)$row['student_name'],
            'classroom'  => (string)($row['classroom_name'] ?? ''),
            'team'       => $row['team'] !== null ? (int)$row['team'] : null,
            'correct'    => $correct,
            'answered'   => (int)$row['answered'],
            'score'      => $correct * BATTLE_POINT,
            'rank'       => $isDq ? null : $rank,
            'dq'         => $isDq,
            'dq_reason'  => $isDq ? (BATTLE_DQ_REASONS[$row['dq_reason']] ?? '失格') : null,
            'dq_no'      => ($isDq && $row['dq_seq'] !== null) ? (int)$row['dq_seq'] + 1 : null,   // 画面は1始まり
        ];
    }
    return $list;
}

// 待合室の顔ぶれ（スタート時に参加できる＝最近まで画面を開いていた生徒だけ alive=true）
function battle_lobby_players(PDO $pdo, int $roomId, int $now): array
{
    $team = battle_has_team($pdo) ? 'p.team' : 'NULL';
    $stmt = $pdo->prepare(
        "SELECT p.student_id, p.last_seen_ms, " . $team . " AS team, s.student_name, c.classroom_name
         FROM battle_players p
         JOIN students s ON s.student_id = p.student_id
         LEFT JOIN classrooms c ON c.classroom_id = s.classroom_id
         WHERE p.room_id = :r AND p.status = 'waiting'
         ORDER BY p.joined_at, p.student_id"
    );
    $stmt->execute(['r' => $roomId]);
    $list = [];
    foreach ($stmt->fetchAll() as $row) {
        $list[] = [
            'student_id' => (int)$row['student_id'],
            'name'       => (string)$row['student_name'],
            'classroom'  => (string)($row['classroom_name'] ?? ''),
            'team'       => $row['team'] !== null ? (int)$row['team'] : null,
            'alive'      => (int)$row['last_seen_ms'] >= $now - BATTLE_LOBBY_ALIVE_MS,
        ];
    }
    return $list;
}

// 全問のふりかえり（終了後の画面用）。$studentId を渡すとその生徒の答えも付ける
function battle_review(PDO $pdo, int $roomId, ?int $studentId): array
{
    $stmt = $pdo->prepare(
        'SELECT rq.seq, rq.choice_order, q.category, q.question_text, q.answer,
                q.wrong1, q.wrong2, q.wrong3, q.wrong4, q.wrong5, q.explanation,
                a.choice AS my_choice,
                (SELECT COUNT(*) FROM battle_answers x WHERE x.room_id = rq.room_id AND x.seq = rq.seq AND x.is_correct = 1) AS n_correct
         FROM battle_room_questions rq
         JOIN battle_questions q ON q.question_id = rq.question_id
         LEFT JOIN battle_answers a ON a.room_id = rq.room_id AND a.seq = rq.seq AND a.student_id = :s
         WHERE rq.room_id = :r
         ORDER BY rq.seq'
    );
    $stmt->execute(['r' => $roomId, 's' => $studentId ?? 0]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $q = battle_question_payload($row);
        $q['no'] = (int)$row['seq'] + 1;
        $q['correct'] = battle_correct_pos((string)$row['choice_order']);
        $q['explanation'] = (string)$row['explanation'];
        $q['n_correct'] = (int)$row['n_correct'];
        if ($studentId !== null) {
            $q['mine'] = $row['my_choice'] !== null ? (int)$row['my_choice'] : null;
        }
        $out[] = $q;
    }
    return $out;
}
