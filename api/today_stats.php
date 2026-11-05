<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ranking.php';

// 今日このツール(unit_key)で解いた数・正解した数と、正解数・正答率それぞれの教室内順位を返す。
// 共通ヘッダー(divp-header.js)が名前の下に「今日の正解 N問・教室 K位/T人」
// 「正答率 M%・教室 K位/T人」の2行を出すための値。
// divp-core.js がツールを開いた時に読み、解いたあとも少し待って読み直す（順位は他の生徒しだいで動くため）。
// 「今日」はサーバーの日付（db.php で Asia/Tokyo / +09:00 に合わせてある）。
// 順位の数え方は mypage.php の教室内じゅんいと同じ ranking_rows / ranking_ranked
// （テスト生は除外・本人がテスト生なら含める・正解0の生徒は順位に載らない）。
$actor = require_login(['student']);

$unitKey = (string)($_GET['unit_key'] ?? '');
if (!preg_match('/^[a-z0-9_]{1,64}$/i', $unitKey)) {
    json_response(['ok' => false, 'error' => 'invalid_unit_key'], 400);
}

$pdo = db();
$studentId = (int)$actor['id'];

$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS solved, COALESCE(SUM(is_correct), 0) AS correct
     FROM answer_logs
     WHERE student_id = :id AND unit_key = :unit_key AND answered_at >= CURDATE()'
);
$stmt->execute(['id' => $studentId, 'unit_key' => $unitKey]);
$row = $stmt->fetch();

// 正解数の順位と正答率の順位（正答率は RANK_MIN_SOLVED 問以上解いた生徒だけで並べる）
$ranks = ['correct' => [null, 0], 'rate' => [null, 0]];
$stmt = $pdo->prepare('SELECT classroom_id, student_name FROM students WHERE student_id = :id');
$stmt->execute(['id' => $studentId]);
$student = $stmt->fetch();
if ($student && (int)$student['classroom_id'] > 0) {
    $today = new DateTimeImmutable('today');
    $viewerIsTest = mb_strpos((string)$student['student_name'], 'テスト') !== false;
    $rows = ranking_rows(
        $pdo,
        [(int)$student['classroom_id']],
        $today->format('Y-m-d 00:00:00'),
        $today->modify('+1 day')->format('Y-m-d 00:00:00'),
        $viewerIsTest,
        $unitKey
    );
    foreach (array_keys($ranks) as $metric) {
        $list = ranking_ranked($rows, $metric);
        $ranks[$metric][1] = count($list);
        foreach ($list as $r) {
            if ((int)$r['student_id'] === $studentId) { $ranks[$metric][0] = (int)$r['rank']; break; }
        }
    }
}

json_response([
    'ok'              => true,
    'solved'          => (int)($row['solved'] ?? 0),
    'correct'         => (int)($row['correct'] ?? 0),
    'rank'            => $ranks['correct'][0],   // 正解数の順位。正解0なら null（順位に載らない）
    'rank_total'      => $ranks['correct'][1],   // 今日このツールで1問以上正解した教室の生徒数
    'rate_rank'       => $ranks['rate'][0],      // 正答率の順位。RANK_MIN_SOLVED 問未満なら null
    'rate_rank_total' => $ranks['rate'][1],
    'rate_min_solved' => RANK_MIN_SOLVED,
]);
