<?php
declare(strict_types=1);

// 生徒マイページ「学習の記録」。デザインは mypage_mock.html が正（見た目は変えない）。
// データ取得元: study_sessions / answer_logs / xp_logs / retry_queue / question_catalog
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/api/self_study_common.php';   // 自習の記録（教科・手ごたえのラベル）

$actor = current_actor();

// ---- 未ログイン時: 共通ヘッダーのログイン窓で入ってもらう ----
if (!$actor || $actor['type'] !== 'student') {
    ?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>学習の記録 | 中京個別指導学院</title>
</head>
<body style="font-family:sans-serif;background:#FBFAF6;">
<script src="/assets/divp-header.js"></script>
<div style="max-width:560px;margin:60px auto;padding:0 16px;text-align:center;color:#33312B;">
  <p style="font-size:18px;font-weight:700;">学習の記録を見るにはログインが必要です</p>
  <p style="font-size:14px;color:#8B877C;">右上の「ログイン」ボタンから<br>生徒コードとPINを入力してください</p>
</div>
</body>
</html><?php
    exit;
}

$studentId = $actor['id'];
$pdo = db();

// ---- 生徒情報(教室名・学年) ----
$stmt = $pdo->prepare(
    'SELECT s.student_name, s.grade, s.classroom_id, c.classroom_name
     FROM students s JOIN classrooms c ON c.classroom_id = s.classroom_id
     WHERE s.student_id = :id'
);
$stmt->execute(['id' => $studentId]);
$student = $stmt->fetch();

function grade_label(?string $grade): string
{
    if (!$grade) return '';
    if (preg_match('/^es(\d)$/', $grade, $m)) return '小' . $m[1];
    if (preg_match('/^js(\d)$/', $grade, $m)) return '中' . $m[1];
    if (preg_match('/^hs(\d)$/', $grade, $m)) return '高' . $m[1];
    return $grade;
}

// ---- 表示期間(今週/先週/今月/全期間) ----
$period = (string)($_GET['period'] ?? 'today');
if (!in_array($period, ['today', 'yesterday', 'week', 'last_week', 'month', 'all'], true)) {
    $period = 'today';
}
$thisMonday = new DateTimeImmutable('monday this week');
switch ($period) {
    case 'today':
        $from = new DateTimeImmutable('today 00:00:00');
        $to = $from->modify('+1 day');
        break;
    case 'yesterday':
        $from = new DateTimeImmutable('yesterday 00:00:00');
        $to = $from->modify('+1 day');
        break;
    case 'last_week':
        $from = $thisMonday->modify('-7 days');
        $to = $thisMonday;
        break;
    case 'month':
        $from = new DateTimeImmutable('first day of this month 00:00:00');
        $to = $from->modify('+1 month');
        break;
    case 'all':
        $from = null;
        $to = null;
        break;
    default: // week
        $from = $thisMonday;
        $to = $thisMonday->modify('+7 days');
        break;
}
$periodLabels = ['today' => '今日', 'yesterday' => '昨日', 'week' => '今週', 'last_week' => '先週', 'month' => '今月', 'all' => 'これまで'];
$eyebrow = $periodLabels[$period] . 'の がんばり';

// 期間条件付きのWHERE句を組み立てる（$fromがnullなら全期間）
function period_where(string $column, ?DateTimeImmutable $from, ?DateTimeImmutable $to, array &$params): string
{
    if ($from === null) {
        return '';
    }
    $params['from'] = $from->format('Y-m-d 00:00:00');
    $params['to'] = $to->format('Y-m-d 00:00:00');
    return " AND {$column} >= :from AND {$column} < :to";
}

// 期間内の学習時間(分)
$params = ['id' => $studentId];
$where = period_where('started_at', $from, $to, $params);
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(duration_sec),0) FROM study_sessions WHERE student_id = :id{$where}"
);
$stmt->execute($params);
$weekMinutes = (int)floor(((int)$stmt->fetchColumn()) / 60);

// 期間内の解答数・正答率
$params = ['id' => $studentId];
$where = period_where('answered_at', $from, $to, $params);
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS total, COALESCE(SUM(is_correct),0) AS correct FROM answer_logs
     WHERE student_id = :id{$where}"
);
$stmt->execute($params);
$week = $stmt->fetch();
$weekSolved = (int)$week['total'];
$weekCorrect = (int)$week['correct'];
$weekRate = $weekSolved > 0 ? (int)round(100 * $weekCorrect / $weekSolved) : 0;

// ---- レベル(累計XPから算出: floor(sqrt(totalXp/100))+1) ----
$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM xp_logs WHERE student_id = :id');
$stmt->execute(['id' => $studentId]);
$totalXp = (int)$stmt->fetchColumn();
$level = (int)floor(sqrt($totalXp / 100)) + 1;
$levelFloor = ($level - 1) * ($level - 1) * 100;   // 現レベルの開始XP
$levelCeil  = $level * $level * 100;               // 次レベルに必要な累計XP
$xpToNext = $levelCeil - $totalXp;
$levelPct = (int)round(100 * ($totalXp - $levelFloor) / max(1, $levelCeil - $levelFloor));

// ---- 解き直し(pending件数) ----
$stmt = $pdo->prepare("SELECT COUNT(*) FROM retry_queue WHERE student_id = :id AND status = 'pending'");
$stmt->execute(['id' => $studentId]);
$retryCount = (int)$stmt->fetchColumn();

// 単元名・ツールURLの台帳（下の「今日の1問」の選定と、単元カルテの表示で使う）
$unitMeta = require __DIR__ . '/api/units.php';

// ---- 今日の1問（pending の中でいちばん多くまちがえた問題を1つ）----
// 問題文は最後に間違えた時の answer_logs から取る（retry.php と同じ引き方）。
// replay_json は migrate_retry_replay.sql を当てた環境にしか無いので先に確認する。
$hasReplayCol = false;
try {
    $hasReplayCol = (bool)$pdo->query("SHOW COLUMNS FROM retry_queue LIKE 'replay_json'")->fetchColumn();
} catch (Throwable $e) {
    $hasReplayCol = false;
}
// 上位10問を取り、その中から「実際に解き直せる問題」を1つ選ぶ（下の foreach）。
// 愛知大問1のような再表示方式の単元は replay_json が無い問題を出題できないため、
// いちばん多くまちがえた問題がそれだと「押しても空振りするカード」になってしまう。
$stmt = $pdo->prepare(
    "SELECT rq.unit_key, rq.question_key, rq.params_hash, rq.wrong_count,
            COALESCE(qc.label, rq.question_key) AS label,
            " . ($hasReplayCol ? 'rq.replay_json IS NOT NULL' : '0') . " AS has_replay,
            al.question_text
     FROM retry_queue rq
     LEFT JOIN question_catalog qc
       ON qc.unit_key = rq.unit_key AND qc.question_key = rq.question_key
     LEFT JOIN answer_logs al ON al.answer_id = (
        SELECT MAX(al2.answer_id) FROM answer_logs al2
        WHERE al2.student_id = rq.student_id AND al2.unit_key = rq.unit_key
          AND al2.question_key = rq.question_key AND al2.params_hash = rq.params_hash
          AND al2.is_correct = 0
     )
     WHERE rq.student_id = :id AND rq.status = 'pending'
     ORDER BY rq.wrong_count DESC, rq.updated_at DESC
     LIMIT 10"
);
$stmt->execute(['id' => $studentId]);
$tpCandidates = $stmt->fetchAll();

// 解き直せる問題を優先して選ぶ。1つも無ければ先頭を出す（カードは出すがボタンは出さない）
$todaysProblem = $tpCandidates[0] ?? null;
foreach ($tpCandidates as $cand) {
    $needsReplay = !empty($unitMeta[$cand['unit_key']]['replay']);
    if (!$needsReplay || !empty($cand['has_replay'])) {
        $todaysProblem = $cand;
        break;
    }
}

// ---- 教室内ランキング(自分の順位だけ表示。他の生徒の名前は出さない) ----
require_once __DIR__ . '/api/ranking.php';
$rankFromStr = $from ? $from->format('Y-m-d 00:00:00') : null;
$rankToStr = $to ? $to->format('Y-m-d 00:00:00') : null;
// テスト生は通常ランキングから除外。ただし本人がテスト生なら含める（動作確認用）
$viewerIsTest = mb_strpos((string)$student['student_name'], 'テスト') !== false;
$rankRows = ranking_rows($pdo, [(int)$student['classroom_id']], $rankFromStr, $rankToStr, $viewerIsTest);
$myRanks = [];
foreach (['solved' => '解いた問題', 'correct' => '正解数', 'rate' => '正答率', 'xp' => 'ゲットしたXP'] as $metric => $metricLabel) {
    $list = ranking_ranked($rankRows, $metric);
    $mine = null;
    foreach ($list as $r) {
        if ((int)$r['student_id'] === $studentId) { $mine = $r; break; }
    }
    $myRanks[] = [
        'label' => $metricLabel,
        'metric' => $metric,
        'rank' => $mine ? (int)$mine['rank'] : null,
        'total' => count($list),
    ];
}

// ---- 全教室混合ランキング(イベント期間中のみ表示。集計もイベント期間の実績) ----
$activeEvent = ranking_active_event(require __DIR__ . '/api/ranking_events.php');
$eventRanks = null;
if ($activeEvent) {
    $evFromStr = $activeEvent['from'] . ' 00:00:00';
    $evToStr = (new DateTimeImmutable($activeEvent['to']))->modify('+1 day')->format('Y-m-d 00:00:00');
    $evRows = ranking_rows($pdo, $activeEvent['classroom_ids'] ?? null, $evFromStr, $evToStr, $viewerIsTest);
    $evSolved = 0;   // 足切りメッセージ用に自分のイベント期間内解答数を控えておく
    foreach ($evRows as $r) {
        if ((int)$r['student_id'] === $studentId) { $evSolved = (int)$r['solved']; break; }
    }
    $eventRanks = [];
    foreach (['solved' => '解いた問題', 'correct' => '正解数', 'rate' => '正答率', 'xp' => 'ゲットしたXP'] as $metric => $metricLabel) {
        $list = ranking_ranked($evRows, $metric);
        $mine = null;
        foreach ($list as $r) {
            if ((int)$r['student_id'] === $studentId) { $mine = $r; break; }
        }
        $eventRanks[] = [
            'label' => $metricLabel,
            'metric' => $metric,
            'rank' => $mine ? (int)$mine['rank'] : null,
            'total' => count($list),
        ];
    }
}

// ---- かかった時間の記録: 自分のベスト＆教室内じゅんい（全期間） ----
// 100マスは answer_logs を残さないゲームなので単元カルテに出ない。
// 愛知 大問1 の本番セットは1問ずつカルテに出るが「10問通しで何分」はそこに出ない。
// どちらも api/time_ranking.php の台帳(time_units)に載っている単元だけカードにする。
require_once __DIR__ . '/api/time_ranking.php';
$timeCards = [];
foreach (time_units() as $tuk => $_c) {
    $conf = time_unit_conf($tuk);
    $sum = time_records_summary($pdo, $studentId, $tuk, null, null);
    if ($sum['plays'] <= 0) continue;
    $rank = null;
    $rankTotal = 0;
    // 速さを競う単元だけ教室内じゅんいを出す（本番形式の演習は順位を出さない）
    if ($conf['ranking']) {
        $rows = time_ranking_rows($pdo, [(int)$student['classroom_id']], $tuk, null, null, $viewerIsTest);
        $rankTotal = count($rows);
        foreach ($rows as $r) {
            if ((int)$r['student_id'] === $studentId) { $rank = (int)$r['rank']; break; }
        }
    }
    $timeCards[$tuk] = [
        'conf'       => $conf,
        'summary'    => $sum,
        'top'        => time_records_top($pdo, $studentId, $tuk, 5),
        'rank'       => $rank,
        'rank_total' => $rankTotal,
    ];
}

// ---- 単元カルテ(選択期間・種類別) ----
$params = ['id' => $studentId];
$where = period_where('al.answered_at', $from, $to, $params);
$stmt = $pdo->prepare(
    "SELECT al.unit_key, COALESCE(qc.label, al.question_key) AS label,
            COUNT(*) AS solved, COALESCE(SUM(al.is_correct),0) AS correct,
            MIN(al.answer_id) AS first_seen
     FROM answer_logs al
     LEFT JOIN question_catalog qc
       ON qc.unit_key = al.unit_key AND qc.question_key = al.question_key
     WHERE al.student_id = :id{$where}
     GROUP BY al.unit_key, al.question_key
     ORDER BY al.unit_key, first_seen"
);
$stmt->execute($params);
$karteRows = $stmt->fetchAll();

// $unitMeta は「今日の1問」の選定でも使うので上（135行付近）で読み込んでいる
$units = [];
foreach ($karteRows as $row) {
    $units[$row['unit_key']][] = $row;
}

// ---- 教科（unit_key の先頭要素）でのフィルタ用ラベル ----
$subjectLabels = [
    'math'     => '算数・数学',
    'english'  => '英語',
    'japanese' => '国語',
    'science'  => '理科',
    'social'   => '社会',
    'allgrade' => 'その他',
];
function subject_of(string $unitKey): string
{
    return explode('_', $unitKey)[0];
}
// カルテに出ている教科だけを、$subjectLabels の順で並べる
$karteSubjects = [];
foreach (array_keys($units) as $uk) {
    $karteSubjects[subject_of($uk)] = true;
}
$karteSubjectKeys = array_values(array_filter(
    array_keys($subjectLabels),
    fn($s) => isset($karteSubjects[$s])
));
// 台帳に無い教科があれば末尾に足す
foreach (array_keys($karteSubjects) as $s) {
    if (!in_array($s, $karteSubjectKeys, true)) {
        $karteSubjectKeys[] = $s;
    }
}

// ---- 学習の足あと(週表示の時だけ・日別の学習時間と解いた問題数) ----
$showWeekDots = in_array($period, ['week', 'last_week'], true);
$dailySec = [];
$dailySolved = [];
if ($showWeekDots) {
    $range = [
        'id'   => $studentId,
        'from' => $from->format('Y-m-d 00:00:00'),
        'to'   => $to->format('Y-m-d 00:00:00'),
    ];
    // 学習時間（秒）を日別に
    $stmt = $pdo->prepare(
        'SELECT DATE(started_at) AS d, COALESCE(SUM(duration_sec),0) AS sec FROM study_sessions
         WHERE student_id = :id AND started_at >= :from AND started_at < :to
         GROUP BY DATE(started_at)'
    );
    $stmt->execute($range);
    foreach ($stmt->fetchAll() as $row) {
        $dailySec[$row['d']] = (int)$row['sec'];
    }
    // 解いた問題数を日別に
    $stmt = $pdo->prepare(
        'SELECT DATE(answered_at) AS d, COUNT(*) AS cnt FROM answer_logs
         WHERE student_id = :id AND answered_at >= :from AND answered_at < :to
         GROUP BY DATE(answered_at)'
    );
    $stmt->execute($range);
    foreach ($stmt->fetchAll() as $row) {
        $dailySolved[$row['d']] = (int)$row['cnt'];
    }
}
$dayLabels = ['月', '火', '水', '木', '金', '土', '日'];
$todayStr = (new DateTimeImmutable('today'))->format('Y-m-d');

// ---- がんばりメッセージ ----
if ($weekSolved === 0) {
    $heroMsg = ($period === 'week') ? '今週も がんばろう！' : (($period === 'today') ? '今日も がんばろう！' : 'この期間の記録はありません');
} elseif ($weekRate >= 80) {
    $heroMsg = 'よく取り組めています！';
} else {
    $heroMsg = 'コツコツ 続けていこう！';
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>学習の記録 | 中京個別指導学院</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@500;700;900&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
<style>
  :root{
    /* ブランドトークン: 朱色はロゴの実色に合わせて微調整可 */
    --paper:#FBFAF6;        /* ノートの紙 */
    --grid:#ECE9E0;         /* 方眼の線 */
    --ink:#33312B;          /* 墨(本文) */
    --ink-soft:#8B877C;     /* 薄墨(補足) */
    --shu:#C73E2E;          /* 朱色(丸つけ・アクセント) */
    --shu-soft:#F6E3DF;     /* 朱の淡色(バー地) */
    --ai:#2C5F8A;           /* 藍(リンク・講師側でも共用) */
    --kin:#C9A227;          /* 金(XP・レベル) */
    --white:#FFFFFF;
    --radius:14px;
    --shadow:0 1px 3px rgba(51,49,43,.08), 0 6px 16px rgba(51,49,43,.06);
  }
  *{margin:0;padding:0;box-sizing:border-box}
  body{
    font-family:'Zen Kaku Gothic New',sans-serif;
    color:var(--ink);
    background-color:var(--paper);
    /* 方眼ノート */
    background-image:
      linear-gradient(var(--grid) 1px, transparent 1px),
      linear-gradient(90deg, var(--grid) 1px, transparent 1px);
    background-size:24px 24px;
    line-height:1.6;
    -webkit-font-smoothing:antialiased;
    /* Androidが端末フォント設定で本文を縮小するのを防ぎ、指定サイズで表示する */
    -webkit-text-size-adjust:100%;text-size-adjust:100%;
  }
  .wrap{max-width:560px;margin:0 auto;padding:0 16px 64px}

  /* ---------- ヘッダー ---------- */
  header{
    display:flex;align-items:center;justify-content:space-between;
    padding:14px 2px 10px;
  }
  header img.logo{height:34px;width:auto;display:block}
  .headright{display:flex;flex-direction:column;align-items:flex-end;gap:6px}
  .who{text-align:right;font-size:12px;color:var(--ink-soft)}
  .logout-btn{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:11px;
    padding:4px 12px;border-radius:999px;cursor:pointer;
    background:transparent;color:var(--ink-soft);border:1.5px solid var(--grid);
  }
  .logout-btn:hover{color:var(--shu);border-color:var(--shu)}
  .tolist{display:inline-block;margin:0 2px 6px;font-size:13px;color:var(--ai);
    text-decoration:none;font-family:'Zen Maru Gothic',sans-serif;font-weight:700}
  .who b{display:block;font-size:15px;color:var(--ink);
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700}

  /* ---------- 今週のがんばり(花丸カード) ---------- */
  .hero{
    position:relative;background:var(--white);border-radius:var(--radius);
    box-shadow:var(--shadow);padding:22px 20px 20px;margin-top:6px;
    border-top:4px solid var(--shu);overflow:hidden;
  }
  .eyebrow{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;
    font-size:13px;letter-spacing:.14em;color:var(--shu);
  }
  .hero h1{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;
    font-size:22px;letter-spacing:.02em;margin-top:2px;
  }
  .stats{display:flex;flex-wrap:wrap;gap:16px 22px;margin-top:14px}
  .stat .num{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;
    font-size:38px;line-height:1;font-feature-settings:'tnum';
  }
  .stat .num small{font-size:15px;font-weight:700;margin-left:2px;color:var(--ink-soft)}
  .stat .lbl{font-size:12px;color:var(--ink-soft);margin-top:4px}
  /* レベルバー */
  .level{margin-top:18px;padding-top:14px;border-top:1px dashed var(--grid)}
  .level .row{display:flex;justify-content:space-between;align-items:baseline;font-size:13px}
  .level .lv{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;
    font-size:17px;color:var(--kin)}
  .bar{height:10px;background:#F2EEE2;border-radius:5px;margin-top:6px;overflow:hidden}
  .bar>i{display:block;height:100%;border-radius:5px;
    background:linear-gradient(90deg,#E4C455,var(--kin))}

  /* ---------- 解き直しボタン ---------- */
  .retry{
    display:flex;align-items:center;justify-content:space-between;
    margin-top:16px;background:var(--shu);color:#fff;border-radius:var(--radius);
    padding:16px 18px;box-shadow:var(--shadow);text-decoration:none;
  }
  .retry .t{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:16px}
  .retry .t small{display:block;font-size:11px;font-weight:500;opacity:.85;letter-spacing:.05em}
  .retry .badge{
    background:#fff;color:var(--shu);border-radius:999px;
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:16px;
    min-width:44px;text-align:center;padding:6px 12px;
  }

  /* ---------- 今日の1問（いちばん多くまちがえた問題）---------- */
  .today{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:16px 18px;margin-top:16px;border-top:4px solid var(--shu)}
  .today-head{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
  .today-badge{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:15px;color:var(--shu)}
  .today-sub{font-size:11px;color:var(--ink-soft)}
  .today-unit{margin-top:8px;font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:14px;
    display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .today .chip{display:inline-block;font-size:11px;font-weight:700;color:var(--shu);
    background:var(--shu-soft);border-radius:999px;padding:1px 10px;font-family:'Zen Maru Gothic',sans-serif}
  .today-q{margin-top:8px;font-size:16px;overflow-x:auto;padding:10px 12px;background:var(--paper);
    border:1px dashed var(--grid);border-radius:10px}
  /* 連立方程式（中2計算マスター等）を中かっこでまとめて表示する。SYS(...)マーカーの変換先 */
  .sysbrace{display:inline-flex;align-items:center}
  .sysbrace::before{content:'{';font-weight:100;font-size:2.6em;line-height:0;
    transform:translateY(-.04em) scaleX(.55);transform-origin:left center;margin-right:.06em}
  .sysrows{display:inline-flex;flex-direction:column;gap:6px;text-align:left}
  .today-go{display:block;text-align:center;margin-top:12px;background:var(--shu);color:#fff;
    border-radius:10px;padding:12px;text-decoration:none;
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:14px}

  /* ---------- 教室内ランキング(自分の順位のみ) ---------- */
  .rankcard{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:16px 18px 8px;margin-top:16px;border-top:4px solid var(--kin)}
  .rankcard .rc-title{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:14px;color:var(--kin)}
  .rankrow{display:flex;justify-content:space-between;align-items:baseline;
    padding:10px 0;border-bottom:1px solid #F3F0E8;font-size:14px}
  .rankrow:last-child{border-bottom:none}
  .rankrow .pos{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:20px;
    font-feature-settings:'tnum'}
  .rankrow .pos small{font-size:11px;font-weight:500;color:var(--ink-soft);margin-left:4px}
  .rankrow .pos.top3{color:var(--kin)}
  .rankrow .none{font-size:12px;color:var(--ink-soft)}

  /* ---------- かかった時間の記録（100マス・愛知 大問1 本番セット） ---------- */
  /* .hyaku-* は100マス専用ではなく「かかった時間の記録」カード共通（愛知 大問1 も使う）。
     クラス名は既存のまま流用している */
  .hyaku-best{display:flex;align-items:baseline;gap:8px;margin:8px 0 2px;flex-wrap:wrap}
  .hyaku-best .t{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:34px;
    color:var(--ai);font-feature-settings:'tnum';line-height:1}
  .hyaku-best .u{font-size:12px;color:var(--ink-soft)}
  .hyaku-list{list-style:none;margin:6px 0 4px;padding:0}
  .hyaku-list li{display:flex;align-items:center;gap:10px;padding:7px 0;
    border-bottom:1px solid #F3F0E8;font-size:14px;font-feature-settings:'tnum'}
  .hyaku-list li:last-child{border-bottom:none}
  .hyaku-list .rk{width:1.6em;text-align:center;font-weight:900;color:var(--ink-soft)}
  .hyaku-list .tm{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:17px;color:var(--ai);flex:1}
  .hyaku-list .mc{font-size:11px;color:var(--ink-soft)}
  .hyaku-list .dt{font-size:11px;color:var(--ink-soft)}

  /* ---------- 単元カルテ ---------- */
  .section-title{
    display:flex;align-items:center;gap:10px;margin:28px 2px 10px;
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:16px;
  }
  .section-title::after{content:"";flex:1;border-top:2px dotted var(--ink-soft);opacity:.4}
  .karte{background:var(--white);border-radius:var(--radius);
    box-shadow:var(--shadow);padding:18px 18px 8px;margin-bottom:14px}
  .karte h2{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:16px;
    display:flex;align-items:baseline;gap:8px;
  }
  .karte h2 small{font-size:11px;font-weight:500;color:var(--ink-soft)}
  .qrow{padding:12px 0;border-bottom:1px solid #F3F0E8}
  .qrow:last-child{border-bottom:none}
  .qhead{display:flex;justify-content:space-between;align-items:baseline;font-size:14px}
  .qhead .rate{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:17px;
    font-feature-settings:'tnum';
  }
  .qhead .rate small{font-size:11px;font-weight:500;color:var(--ink-soft);margin-left:4px}
  .qbar{position:relative;height:12px;background:var(--shu-soft);
    border-radius:6px;margin-top:6px;overflow:visible}
  .qbar>i{display:block;height:100%;border-radius:6px;background:var(--shu)}
  /* 90%以上は丸つけの「◎」マークが付く */
  .qbar .maru{
    position:absolute;right:-4px;top:50%;transform:translateY(-50%);
    width:22px;height:22px;border-radius:50%;background:var(--white);
    border:2.5px solid var(--shu);display:flex;align-items:center;justify-content:center;
    font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:11px;color:var(--shu);
  }
  .low i{background:#D89A45}          /* 60%未満は橙: がんばりどころ */
  .low .qhead .rate{color:#B07B2E}

  /* ---------- 学習の足あと(週ドット) ---------- */
  .week{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:16px 18px;display:flex;justify-content:space-between}
  .day{text-align:center;font-size:11px;color:var(--ink-soft)}
  .dot{width:26px;height:26px;border-radius:50%;margin:0 auto 5px;
    border:2px dashed var(--grid)}
  .dot.on{border:none;background:var(--shu)}
  .dot.today{outline:2px solid var(--ai);outline-offset:2px}
  .dname{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;color:var(--ink)}
  .dstat{font-size:11px;line-height:1.5;font-feature-settings:'tnum'}
  .dstat b{font-weight:900;color:var(--ink)}
  .dstat.zero,.dstat.zero b{color:#C7C2B6;font-weight:700}

  footer{margin-top:28px;text-align:center;font-size:11px;color:var(--ink-soft)}

  /* ---------- 期間タブ ---------- */
  .ptabs{display:flex;gap:8px;margin-top:8px}
  .ptab{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:12px;
    padding:4px 14px;border-radius:999px;text-decoration:none;
    background:var(--white);color:var(--ink-soft);border:1.5px solid var(--grid);
  }
  .ptab.active{background:var(--shu);color:#fff;border-color:var(--shu)}

  /* ---------- 教科タブ（単元カルテの絞り込み） ---------- */
  .stabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 2px 12px}
  .stab{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:12px;
    padding:4px 14px;border-radius:999px;cursor:pointer;
    background:var(--white);color:var(--ink-soft);border:1.5px solid var(--grid);
  }
  .stab.active{background:var(--ai);color:#fff;border-color:var(--ai)}

  /* ---------- 自習の記録 ---------- */
  .selfstudy{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:16px 18px;margin-top:14px;border-top:4px solid var(--kin)}
  .ss-head{display:flex;align-items:baseline;justify-content:space-between;gap:8px;flex-wrap:wrap}
  .ss-title{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:15px;color:var(--ink)}
  .ss-sum{font-size:12px;color:var(--ink-soft);font-feature-settings:'tnum'}
  .ss-sum b{color:var(--ink);font-weight:900}
  .ss-open{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:13px;
    margin-top:12px;width:100%;padding:10px 14px;border-radius:999px;cursor:pointer;
    background:var(--shu);color:#fff;border:none;
  }
  .ss-open.cancel{background:transparent;color:var(--ink-soft);border:1.5px solid var(--grid)}
  /* 入力フォーム */
  .ss-form{margin-top:12px;display:none}
  .ss-form.open{display:block}
  .ss-row{display:flex;gap:8px;margin-bottom:8px}
  .ss-row>div{flex:1;min-width:0}
  .ss-lbl{font-size:11px;color:var(--ink-soft);font-family:'Zen Maru Gothic',sans-serif;
    font-weight:700;display:block;margin-bottom:3px}
  .ss-form input[type=text],.ss-form input[type=date],.ss-form input[type=number],
  .ss-form select,.ss-form textarea{
    width:100%;font-family:'Zen Kaku Gothic New',sans-serif;font-size:14px;color:var(--ink);
    padding:8px 10px;border:1.5px solid var(--grid);border-radius:8px;background:var(--paper);
    /* iOSが16px未満の入力欄でズームするのを防ぐため、フォーカス時も含め拡大表示させない */
    -webkit-appearance:none;appearance:none;
  }
  .ss-form textarea{resize:vertical;min-height:56px;line-height:1.5}
  .ss-form input:focus,.ss-form select:focus,.ss-form textarea:focus{
    outline:none;border-color:var(--shu);background:var(--white)}
  .ss-feels{display:flex;gap:6px}
  .ss-feel{
    flex:1;padding:6px 2px;border-radius:8px;cursor:pointer;text-align:center;
    background:var(--paper);border:1.5px solid var(--grid);
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:9px;color:var(--ink-soft);
  }
  .ss-feel .face{display:block;font-size:18px;line-height:1.3}
  .ss-feel.on{background:#FFF8E1;border-color:var(--kin);color:#8A6D12}
  .ss-save{
    font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:14px;
    width:100%;margin-top:4px;padding:11px 14px;border-radius:999px;cursor:pointer;
    background:var(--kin);color:#fff;border:none;
  }
  .ss-save:disabled{opacity:.5;cursor:default}
  .ss-msg{font-size:12px;margin-top:8px;text-align:center;color:var(--shu);font-weight:700}
  /* 一覧 */
  .ss-list{margin-top:12px}
  .ss-item{border-top:1px dashed var(--grid);padding:10px 0}
  .ss-item:first-child{border-top:none}
  .ss-line1{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:12px;color:var(--ink-soft)}
  .ss-date{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;color:var(--ink);
    font-feature-settings:'tnum'}
  .ss-chip{font-size:10px;font-weight:700;padding:1px 8px;border-radius:999px;
    background:var(--shu-soft);color:var(--shu);font-family:'Zen Maru Gothic',sans-serif}
  .ss-mark{font-size:10px;font-weight:700;padding:1px 8px;border-radius:999px;
    background:#EDF3F8;color:var(--ai);font-family:'Zen Maru Gothic',sans-serif}
  .ss-mark.yet{background:var(--paper);color:#C7C2B6;border:1px dashed var(--grid)}
  .ss-line2{font-size:14px;font-weight:500;margin-top:2px;word-break:break-word}
  .ss-line2 small{color:var(--ink-soft);font-weight:400;margin-left:6px}
  .ss-memo{font-size:12px;color:var(--ink-soft);margin-top:3px;white-space:pre-wrap;word-break:break-word}
  .ss-cmt{margin-top:6px;padding:8px 10px;border-radius:8px;background:#FDF6F5;
    border-left:3px solid var(--shu);font-size:12px;white-space:pre-wrap;word-break:break-word}
  .ss-cmt b{display:block;font-family:'Zen Maru Gothic',sans-serif;font-size:10px;color:var(--shu)}
  .ss-acts{margin-top:5px;display:flex;gap:10px}
  .ss-act{font-size:11px;color:var(--ai);background:none;border:none;cursor:pointer;
    padding:0;text-decoration:underline;font-family:'Zen Kaku Gothic New',sans-serif}
  .ss-act.del{color:var(--ink-soft)}
  .ss-empty{font-size:13px;color:var(--ink-soft);padding:10px 0}
</style>
</head>
<body>
<div class="wrap">

  <header>
    <img class="logo" src="https://chukyokobetsu.com/manage/wp-content/themes/chukyo/images/common/logo_chukyo.png"
         alt="中京個別指導学院">
    <div class="headright">
      <div class="who"><b><?= h($student['student_name']) ?> さん</b><?= h($student['classroom_name']) ?>教室<?= $student['grade'] ? '・' . h(grade_label($student['grade'])) : '' ?></div>
      <button type="button" class="logout-btn" id="logoutBtn">ログアウト</button>
    </div>
  </header>

  <a class="tolist" href="/learning/index.php">← 学習ツールの目次へ</a>

  <!-- 期間タブ -->
  <nav class="ptabs">
<?php foreach ($periodLabels as $key => $label): ?>
    <a class="ptab<?= $period === $key ? ' active' : '' ?>" href="?period=<?= $key ?>"><?= h($label) ?></a>
<?php endforeach; ?>
  </nav>

  <!-- がんばりカード -->
  <section class="hero">
    <div class="eyebrow"><?= h($eyebrow) ?></div>
    <h1><?= h($heroMsg) ?></h1>
    <div class="stats">
      <div class="stat"><div class="num"><?= $weekMinutes ?><small>分</small></div><div class="lbl">学習時間</div></div>
      <div class="stat"><div class="num"><?= $weekSolved ?><small>問</small></div><div class="lbl">解いた問題</div></div>
      <div class="stat"><div class="num"><?= $weekCorrect ?><small>問</small></div><div class="lbl">正解</div></div>
      <div class="stat"><div class="num"><?= $weekRate ?><small>%</small></div><div class="lbl">正答率</div></div>
    </div>
    <div class="level">
      <div class="row"><span class="lv">Lv. <?= $level ?></span><span style="color:var(--ink-soft);font-size:12px">つぎのレベルまで あと<?= $xpToNext ?>XP</span></div>
      <div class="bar"><i style="width:<?= $levelPct ?>%"></i></div>
    </div>
  </section>

<?php if ($todaysProblem):
    $tpMeta = $unitMeta[$todaysProblem['unit_key']] ?? ['title' => $todaysProblem['unit_key'], 'sub' => '', 'url' => null];
?>
  <!-- 今日の1問（いちばん多くまちがえた問題の解き直し） -->
  <section class="today">
    <div class="today-head">
      <span class="today-badge">今日の1問</span>
      <span class="today-sub">いちばん多くまちがえた問題だよ（これまで<?= (int)$todaysProblem['wrong_count'] ?>回）</span>
    </div>
    <div class="today-unit"><?= h($tpMeta['title'] ?? '') ?><span class="chip"><?= h($todaysProblem['label']) ?></span></div>
<?php if (!empty($todaysProblem['question_text'])): ?>
    <div class="today-q" data-math="<?= h($todaysProblem['question_text']) ?>"><?= h($todaysProblem['question_text']) ?></div>
<?php endif; ?>
<?php
    /* 再表示方式の単元（units.php の 'replay' => true）で replay が保存されていない問題は、
       ツール側が出題できず「解き直せる問題は まだありません」で終わる＝押しても空振りする。
       その場合はボタンを出さない（カード自体は「まちがえた問題」の確認として残す）。 */
    $tpCanGo = !empty($tpMeta['url'])
        && (empty($tpMeta['replay']) || !empty($todaysProblem['has_replay']));
?>
<?php if ($tpCanGo):
    // focus に params_hash を渡すと、ツールがモード選択画面を飛ばしてこの1問だけを直接出題する
    $tpFocus = !empty($todaysProblem['params_hash']) ? '&focus=' . rawurlencode($todaysProblem['params_hash']) : '';
?>
    <a class="today-go" href="<?= h($tpMeta['url']) ?>?retry=1<?= h($tpFocus) ?>">この問題を解き直す →</a>
<?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($retryCount > 0): ?>
  <!-- 解き直し -->
  <a class="retry" href="/retry.php">
    <span class="t">過去のまちがいを解き直す<small>2回連続で正解すると リストから消えるよ</small></span>
    <span class="badge"><?= $retryCount ?>問</span>
  </a>
<?php endif; ?>

  <!-- 自習の記録（生徒の自己申告。ツールの実績とは別枠でXPも付かない） -->
  <section class="selfstudy" id="selfStudy"
           data-from="<?= $from ? h($from->format('Y-m-d')) : '' ?>"
           data-to="<?= $to ? h($to->format('Y-m-d')) : '' ?>"
           data-today="<?= h($todayStr) ?>"
           data-minday="<?= h((new DateTimeImmutable('today'))->modify('-' . SELF_STUDY_BACKDATE_DAYS . ' days')->format('Y-m-d')) ?>">
    <div class="ss-head">
      <span class="ss-title">自習の記録</span>
      <span class="ss-sum" id="ssSum">よみこみ中…</span>
    </div>

    <button type="button" class="ss-open" id="ssOpen">＋ 自習したことを書く</button>

    <form class="ss-form" id="ssForm" autocomplete="off">
      <input type="hidden" id="ssLogId" value="">
      <div class="ss-row">
        <div>
          <label class="ss-lbl" for="ssDate">いつ</label>
          <input type="date" id="ssDate" required>
        </div>
        <div>
          <label class="ss-lbl" for="ssSubject">教科</label>
          <select id="ssSubject" required>
<?php foreach (SELF_STUDY_SUBJECTS as $key => $label): ?>
            <option value="<?= h($key) ?>"><?= h($label) ?></option>
<?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="ss-row">
        <div>
          <label class="ss-lbl" for="ssMaterial">なにを（教材名）</label>
          <input type="text" id="ssMaterial" list="ssMaterials" placeholder="学校のワーク" maxlength="100" required>
          <datalist id="ssMaterials"></datalist>
        </div>
      </div>
      <div class="ss-row">
        <div>
          <label class="ss-lbl" for="ssRange">どこを（範囲）</label>
          <input type="text" id="ssRange" placeholder="p.24〜27" maxlength="100">
        </div>
        <div style="flex:0 0 96px;">
          <label class="ss-lbl" for="ssMinutes">時間（分）</label>
          <input type="number" id="ssMinutes" min="0" max="600" step="5" inputmode="numeric" placeholder="30">
        </div>
      </div>
      <div class="ss-row">
        <div>
          <label class="ss-lbl">手ごたえ</label>
          <div class="ss-feels" id="ssFeels">
<?php foreach (SELF_STUDY_FEELINGS as $v => $label): ?>
            <button type="button" class="ss-feel" data-v="<?= (int)$v ?>">
              <span class="face"><?= h(SELF_STUDY_FEELING_FACES[$v]) ?></span><?= h($label) ?>
            </button>
<?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="ss-row">
        <div>
          <label class="ss-lbl" for="ssMemo">ひとこと・質問（先生が読みます）</label>
          <textarea id="ssMemo" maxlength="500" placeholder="ここが分からなかった、など"></textarea>
        </div>
      </div>
      <button type="submit" class="ss-save" id="ssSave">この内容で記録する</button>
      <div class="ss-msg" id="ssMsg"></div>
    </form>

    <div class="ss-list" id="ssList"></div>
  </section>

  <!-- 教室内ランキング(自分の順位のみ) -->
  <section class="rankcard">
    <div class="rc-title"><?= h($student['classroom_name']) ?>教室の中での じゅんい（<?= h($periodLabels[$period]) ?>）</div>
<?php foreach ($myRanks as $mr): ?>
    <div class="rankrow">
      <span><?= h($mr['label']) ?></span>
<?php if ($mr['rank'] !== null): ?>
      <span class="pos<?= $mr['rank'] <= 3 ? ' top3' : '' ?>"><?= $mr['rank'] ?>位<small><?= $mr['total'] ?>人中</small></span>
<?php elseif ($mr['metric'] === 'rate' && $weekSolved < RANK_MIN_SOLVED): ?>
      <span class="none">あと<?= RANK_MIN_SOLVED - $weekSolved ?>問とくと じゅんいが出るよ</span>
<?php else: ?>
      <span class="none">もんだいをとくと じゅんいが出るよ</span>
<?php endif; ?>
    </div>
<?php endforeach; ?>
  </section>

<?php foreach ($timeCards as $tuk => $tcard):
    $tc = $tcard['conf'];
    $isRecent = $tc['order'] === 'recent';   // 速さを競わない単元は新しい順
    $hasScore = $tc['total'] !== null;       // miss_count から得点(満点-ミス)を出す
?>
  <!-- かかった時間の記録（全期間・自分の記録） -->
  <section class="rankcard" style="border-top-color:var(--ai);">
    <div class="rc-title" style="color:var(--ai);"><?= h($tc['label']) ?><?= $isRecent ? ' かかった時間' : ' タイムアタック' ?></div>
    <div class="hyaku-best">
      <span class="t"><?= h(fmt_time_unit((int)$tcard['summary']['best'], $tuk)) ?></span>
      <span class="u"><?= $isRecent ? 'いちばん速かったとき' : 'ベストタイム' ?> ・ これまで <?= (int)$tcard['summary']['plays'] ?>回</span>
    </div>
<?php if ($tcard['rank'] !== null): ?>
    <div class="rankrow">
      <span><?= h($student['classroom_name']) ?>教室での じゅんい（速さ）</span>
      <span class="pos<?= $tcard['rank'] <= 3 ? ' top3' : '' ?>"><?= (int)$tcard['rank'] ?>位<small><?= (int)$tcard['rank_total'] ?>人中</small></span>
    </div>
<?php endif; ?>
    <ol class="hyaku-list">
<?php foreach ($tcard['top'] as $i => $t):
    // 速い順の単元はメダル、新しい順の単元は「・」（順位の意味を持たせない）
    $medal = $isRecent ? '・' : (['🥇', '🥈', '🥉'][$i] ?? (string)($i + 1));
    $miss = (int)$t['miss_count'];
    $missTxt = $hasScore
        ? ($tc['miss_label'] . ' ' . max(0, (int)$tc['total'] - $miss) . '/' . (int)$tc['total'])
        : ($tc['miss_label'] . ' ' . $miss);
?>
      <li>
        <span class="rk"><?= h((string)$medal) ?></span>
        <span class="tm"><?= h(fmt_time_unit((int)$t['time_ms'], $tuk)) ?></span>
        <span class="mc"><?= h($missTxt) ?></span>
        <span class="dt"><?= h(substr((string)$t['created_at'], 5, 5)) ?></span>
      </li>
<?php endforeach; ?>
    </ol>
  </section>
<?php endforeach; ?>

<?php if ($eventRanks !== null):
    $evFromD = new DateTimeImmutable($activeEvent['from']);
    $evToD = new DateTimeImmutable($activeEvent['to']);
    $evPeriodLabel = $evFromD->format('n') . '月' . $evFromD->format('j') . '日〜'
                   . $evToD->format('n') . '月' . $evToD->format('j') . '日';
?>
  <!-- 全教室混合ランキング(イベント期間限定) -->
  <section class="rankcard" style="border-top-color:var(--shu);">
    <div class="rc-title" style="color:var(--shu);"><?= h($activeEvent['label']) ?> ぜんきょうしつでの じゅんい</div>
    <div style="font-size:11px;color:var(--ink-soft);"><?= h($evPeriodLabel) ?>の きろくで きそうよ</div>
<?php foreach ($eventRanks as $mr): ?>
    <div class="rankrow">
      <span><?= h($mr['label']) ?></span>
<?php if ($mr['rank'] !== null): ?>
      <span class="pos<?= $mr['rank'] <= 3 ? ' top3' : '' ?>"><?= $mr['rank'] ?>位<small><?= $mr['total'] ?>人中</small></span>
<?php elseif ($mr['metric'] === 'rate' && $evSolved < RANK_MIN_SOLVED): ?>
      <span class="none">あと<?= RANK_MIN_SOLVED - $evSolved ?>問とくと じゅんいが出るよ</span>
<?php else: ?>
      <span class="none">もんだいをとくと じゅんいが出るよ</span>
<?php endif; ?>
    </div>
<?php endforeach; ?>
  </section>
<?php endif; ?>

  <!-- 単元カルテ -->
  <div class="section-title">単元カルテ</div>

<?php if (count($karteSubjectKeys) > 1): ?>
  <nav class="stabs" id="karteTabs">
    <button class="stab active" data-subject="all">すべて</button>
<?php foreach ($karteSubjectKeys as $s): ?>
    <button class="stab" data-subject="<?= h($s) ?>"><?= h($subjectLabels[$s] ?? $s) ?></button>
<?php endforeach; ?>
  </nav>
<?php endif; ?>

<?php if (count($units) === 0): ?>
  <section class="karte">
    <h2><?= $period === 'all' ? 'まだ記録がありません' : 'この期間の記録はありません' ?></h2>
    <div class="qrow"><div class="qhead"><span style="color:var(--ink-soft);font-size:13px;">問題を解くと、ここに種類別の成績が表示されます</span></div></div>
  </section>
<?php else: ?>
<?php foreach ($units as $unitKey => $rows):
    $meta = $unitMeta[$unitKey] ?? ['title' => $unitKey, 'sub' => ''];
?>
  <section class="karte" data-subject="<?= h(subject_of($unitKey)) ?>">
    <h2><?= h($meta['title']) ?> <?php if ($meta['sub']): ?><small><?= h($meta['sub']) ?></small><?php endif; ?></h2>
<?php foreach ($rows as $row):
    $solved = (int)$row['solved'];
    $correct = (int)$row['correct'];
    $rate = $solved > 0 ? (int)round(100 * $correct / $solved) : 0;
    $isLow = $rate < 60;
    $isMaru = $rate >= 90;
?>
    <div class="qrow<?= $isLow ? ' low' : '' ?>">
      <div class="qhead"><span><?= h($row['label']) ?></span><span class="rate"><?= $rate ?><small>% (<?= $correct ?>/<?= $solved ?>)</small></span></div>
      <div class="qbar"><i style="width:<?= $rate ?>%"></i><?php if ($isMaru): ?><span class="maru">◎</span><?php endif; ?></div>
    </div>
<?php endforeach; ?>
  </section>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($showWeekDots): ?>
  <!-- 学習の足あと -->
  <div class="section-title">学習の足あと</div>
  <section class="week">
<?php for ($i = 0; $i < 7; $i++):
    $day = $from->modify("+{$i} days");
    $dayStr = $day->format('Y-m-d');
    $minutes = isset($dailySec[$dayStr]) ? (int)floor($dailySec[$dayStr] / 60) : 0;
    $solved  = $dailySolved[$dayStr] ?? 0;
    $isToday = $dayStr === $todayStr;
    $active  = $minutes > 0 || $solved > 0;
    $classes = 'dot' . ($active ? ' on' : '') . ($isToday ? ' today' : '');
?>
    <div class="day">
      <div class="<?= $classes ?>"></div>
      <div class="dname"><?= $dayLabels[$i] ?></div>
      <div class="dstat<?= $minutes > 0 ? '' : ' zero' ?>"><b><?= $minutes ?></b>分</div>
      <div class="dstat<?= $solved > 0 ? '' : ' zero' ?>"><b><?= $solved ?></b>問</div>
    </div>
<?php endfor; ?>
  </section>
<?php endif; ?>

  <footer>中京個別指導学院 学習の記録<?= $showWeekDots ? ' ・ 分=学習時間 / 問=解いた問題数' : '' ?></footer>
</div>
<script>
// ログアウト（divp-header と同じ /api/logout.php にPOST）
(function () {
  var btn = document.getElementById('logoutBtn');
  if (!btn) return;
  btn.addEventListener('click', function () {
    fetch('/api/logout.php', { method: 'POST' }).then(function () {
      location.href = '/mypage.php';
    });
  });
})();

// 「今日の1問」の問題文を KaTeX で整形。retry.php と同じ規則:
// 全体がLaTeXのものと、Unicodeの√/²・分数F(a/b)混じりの日本語文の両方に対応する。
function _mescape(t){ return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function _texWhole(src){ try { return katex.renderToString(src, { throwOnError: true, displayMode: false }); } catch (e) { return _mescape(src); } }
function _K(latex, fallback){
  try { if (typeof katex === 'undefined') throw 0;
    return katex.renderToString(latex, { throwOnError: false, displayMode: false }); }
  catch (e) { return _mescape(fallback != null ? fallback : latex); }
}
function _toLatex(token){
  var m = token.match(/^\([-－]√([\d.]+)\)²$/);   if (m) return '(-\\sqrt{' + m[1] + '})^2';
  m = token.match(/^\(√([\d.]+)\)²$/);            if (m) return '(\\sqrt{' + m[1] + '})^2';
  m = token.match(/^±√([\d.]+)$/);                 if (m) return '\\pm\\sqrt{' + m[1] + '}';
  m = token.match(/^(\d+)√([\d.]+)$/);             if (m) return m[1] + '\\sqrt{' + m[2] + '}';
  m = token.match(/^[-－]√([\d.]+)$/);             if (m) return '-\\sqrt{' + m[1] + '}';
  m = token.match(/^√\((\d+)\/(\d+)\)$/);          if (m) return '\\sqrt{\\dfrac{' + m[1] + '}{' + m[2] + '}}';
  // F(分子/分母) は数字だけとは限らない（math_js2_keisan.html の分数係数・多項式の
  // 通分結果など、文字式が分子・分母どちらにも来る）。²³は\dfrac内で通常の上付きに戻す。
  m = token.match(/^F\(([^()\/]+)\/([^()\/]+)\)$/);
  if (m) {
    var fnum = m[1].replace(/²/g, '^{2}').replace(/³/g, '^{3}');
    var fden = m[2].replace(/²/g, '^{2}').replace(/³/g, '^{3}');
    return '\\dfrac{' + fnum + '}{' + fden + '}';
  }
  m = token.match(/^[-－]√\(\(-(\d+)\)²\)$/);      if (m) return '-\\sqrt{(-' + m[1] + ')^2}';
  m = token.match(/^√\(\(-(\d+)\)²\)$/);           if (m) return '\\sqrt{(-' + m[1] + ')^2}';
  m = token.match(/^√([\d.]+)$/);                  if (m) return '\\sqrt{' + m[1] + '}';
  return token;
}
function _plain(t){ return _mescape(t).replace(/(?<!\d)-([\d])/g, '－$1').replace(/\n/g, '<br>'); }
function _renderMath(str){
  var re = /[-－]√\(\(-\d+\)²\)|√\(\(-\d+\)²\)|\([-－]√[\d.]+\)²|\(√[\d.]+\)²|√\(\d+\/\d+\)|±√[\d.]+|\d+√[\d.]+|[-－]√[\d.]+|√[\d.]+|F\([^()\/]+\/[^()\/]+\)/g;
  var out = '', last = 0, mt;
  while ((mt = re.exec(str)) !== null) {
    out += _plain(str.slice(last, mt.index));
    out += _K(_toLatex(mt[0]), mt[0]);
    last = mt.index + mt[0].length;
  }
  out += _plain(str.slice(last));
  return out;
}
function renderMathToHTML(src){
  src = String(src == null ? '' : src);
  // SYS(式1|式2) は連立方程式（math_js2_keisan.html等）。中かっこでまとめて縦に並べる。
  // 各式は再帰的に renderMathToHTML へ通すので、式の中の F(分子/分母) 等もそのまま効く。
  var sysM = /^SYS\(([\s\S]*)\)$/.exec(src);
  if (sysM) {
    var sysParts = sysM[1].split('|');
    if (sysParts.length === 2) {
      return '<span class="sysbrace"><span class="sysrows"><span>' + renderMathToHTML(sysParts[0])
        + '</span><span>' + renderMathToHTML(sysParts[1]) + '</span></span></span>';
    }
  }
  if (/[\\^_{}]/.test(src)) return _texWhole(src);
  if (/[√²³]/.test(src) || /F\([^()\/]+\/[^()\/]+\)/.test(src)) return _renderMath(src);
  return _mescape(src).replace(/\n/g, '<br>');
}
document.querySelectorAll('.today-q').forEach(function (el) {
  el.innerHTML = renderMathToHTML(el.getAttribute('data-math') || '');
});

// 単元カルテの教科タブ: data-subject で .karte を表示/非表示
(function () {
  var tabs = document.getElementById('karteTabs');
  if (!tabs) return;
  var cards = Array.prototype.slice.call(document.querySelectorAll('.karte[data-subject]'));
  tabs.addEventListener('click', function (e) {
    var btn = e.target.closest('.stab');
    if (!btn) return;
    var sel = btn.getAttribute('data-subject');
    tabs.querySelectorAll('.stab').forEach(function (b) { b.classList.toggle('active', b === btn); });
    cards.forEach(function (c) {
      c.style.display = (sel === 'all' || c.getAttribute('data-subject') === sel) ? '' : 'none';
    });
  });
})();

// ---------- 自習の記録 ----------
// 一覧・保存・削除はすべて /api/*_self_study.php 経由。画面はここだけで組み立てる
// （PHPとJSに同じ描画を二重に持たない）。XPは付かない＝がんばりカードの数字は動かない。
(function () {
  var root = document.getElementById('selfStudy');
  if (!root) return;

  var FEELS = <?= json_encode(SELF_STUDY_FEELING_FACES, JSON_UNESCAPED_UNICODE) ?>;
  var FEEL_LABELS = <?= json_encode(SELF_STUDY_FEELINGS, JSON_UNESCAPED_UNICODE) ?>;
  var periodFrom = root.getAttribute('data-from');   // 空 = 全期間タブ
  var periodTo   = root.getAttribute('data-to');
  var today      = root.getAttribute('data-today');
  var minDay     = root.getAttribute('data-minday');

  var elForm = document.getElementById('ssForm');
  var elOpen = document.getElementById('ssOpen');
  var elList = document.getElementById('ssList');
  var elSum  = document.getElementById('ssSum');
  var elMsg  = document.getElementById('ssMsg');
  var elSave = document.getElementById('ssSave');
  var elFeels = document.getElementById('ssFeels');
  var f = {
    id: document.getElementById('ssLogId'),
    date: document.getElementById('ssDate'),
    subject: document.getElementById('ssSubject'),
    material: document.getElementById('ssMaterial'),
    range: document.getElementById('ssRange'),
    minutes: document.getElementById('ssMinutes'),
    memo: document.getElementById('ssMemo')
  };

  function esc(t) {
    return String(t == null ? '' : t)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function mmdd(d) { var p = String(d).split('-'); return p[1] + '/' + p[2]; }
  function feeling() {
    var on = elFeels.querySelector('.ss-feel.on');
    return on ? Number(on.getAttribute('data-v')) : null;
  }

  function resetForm() {
    f.id.value = '';
    f.date.value = today;
    f.subject.selectedIndex = 0;
    f.material.value = '';
    f.range.value = '';
    f.minutes.value = '';
    f.memo.value = '';
    elFeels.querySelectorAll('.ss-feel').forEach(function (b) { b.classList.remove('on'); });
    elSave.textContent = 'この内容で記録する';
    elMsg.textContent = '';
  }

  function openForm(open) {
    elForm.classList.toggle('open', open);
    elOpen.textContent = open ? '× 入力をやめる' : '＋ 自習したことを書く';
    elOpen.classList.toggle('cancel', open);
    if (!open) resetForm();
  }

  function render(data) {
    var items = data.items || [];

    // 期間タブの範囲に入る記録だけを集計して見出しに出す（全期間タブなら全部）
    var n = 0, min = 0;
    items.forEach(function (it) {
      if (periodFrom && (it.study_date < periodFrom || it.study_date >= periodTo)) return;
      n++;
      min += it.minutes || 0;
    });
    elSum.innerHTML = n === 0
      ? 'この期間の記録はまだありません'
      : '<b>' + n + '</b>件 ・ <b>' + min + '</b>分';

    // 教材名の入力候補（過去に自分が書いたもの）
    document.getElementById('ssMaterials').innerHTML =
      (data.materials || []).map(function (m) { return '<option value="' + esc(m) + '">'; }).join('');

    if (items.length === 0) {
      elList.innerHTML = '<div class="ss-empty">「＋ 自習したことを書く」から、家でやった勉強を残せます。<br>先生が読んで、確認印とひとことを返します。</div>';
      return;
    }

    elList.innerHTML = items.map(function (it) {
      var checked = !!it.checked_at;
      var h = [];
      h.push('<div class="ss-item" data-id="' + it.log_id + '">');
      h.push('<div class="ss-line1">');
      h.push('<span class="ss-date">' + esc(mmdd(it.study_date)) + '</span>');
      h.push('<span class="ss-chip">' + esc(it.subject_label) + '</span>');
      if (it.minutes) h.push('<span>' + it.minutes + '分</span>');
      if (it.feeling) h.push('<span title="' + esc(FEEL_LABELS[it.feeling] || '') + '">' + esc(FEELS[it.feeling] || '') + '</span>');
      h.push(checked
        ? '<span class="ss-mark">✓ 先生かくにん済み</span>'
        : '<span class="ss-mark yet">みてもらう前</span>');
      h.push('</div>');
      h.push('<div class="ss-line2">' + esc(it.material)
        + (it.range_text ? '<small>' + esc(it.range_text) + '</small>' : '') + '</div>');
      if (it.memo) h.push('<div class="ss-memo">' + esc(it.memo) + '</div>');
      if (it.teacher_comment) {
        h.push('<div class="ss-cmt"><b>' + esc(it.teacher_name || '先生') + 'から</b>'
          + esc(it.teacher_comment) + '</div>');
      }
      // 確認印が押された記録はもう直せない（先生のコメントと食い違うため）
      if (!checked) {
        h.push('<div class="ss-acts">'
          + '<button type="button" class="ss-act" data-act="edit">なおす</button>'
          + '<button type="button" class="ss-act del" data-act="del">けす</button></div>');
      }
      h.push('</div>');
      return h.join('');
    }).join('');

    elList._items = items;
  }

  function load() {
    fetch('/api/list_self_study.php?limit=30', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.ok) render(d); else elSum.textContent = ''; })
      .catch(function () { elSum.textContent = ''; });
  }

  elOpen.addEventListener('click', function () { openForm(!elForm.classList.contains('open')); });

  elFeels.addEventListener('click', function (e) {
    var btn = e.target.closest('.ss-feel');
    if (!btn) return;
    var was = btn.classList.contains('on');
    elFeels.querySelectorAll('.ss-feel').forEach(function (b) { b.classList.remove('on'); });
    if (!was) btn.classList.add('on');   // もう一度押すと選び直せる（未選択に戻す）
  });

  var ERRORS = {
    invalid_date: '日付を選んでください',
    date_out_of_range: '書けるのは今日から1か月前までの日付です',
    invalid_material: '教材名を入れてください',
    invalid_minutes: '時間は0〜600分で入れてください',
    already_checked: '先生が確認した記録は直せません',
    not_found: 'その記録は見つかりませんでした'
  };

  elForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (f.date.value < minDay || f.date.value > today) {
      elMsg.textContent = ERRORS.date_out_of_range;
      return;
    }
    elSave.disabled = true;
    elMsg.textContent = '';
    var body = {
      study_date: f.date.value,
      subject: f.subject.value,
      material: f.material.value.trim(),
      range_text: f.range.value.trim(),
      minutes: f.minutes.value,
      feeling: feeling(),
      memo: f.memo.value.trim()
    };
    if (f.id.value) body.log_id = Number(f.id.value);

    fetch('/api/save_self_study.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        elSave.disabled = false;
        if (d && d.ok) { openForm(false); load(); return; }
        elMsg.textContent = (d && ERRORS[d.error]) || 'うまく保存できませんでした';
      })
      .catch(function () {
        elSave.disabled = false;
        elMsg.textContent = 'つうしんに失敗しました';
      });
  });

  elList.addEventListener('click', function (e) {
    var btn = e.target.closest('.ss-act');
    if (!btn) return;
    var id = Number(btn.closest('.ss-item').getAttribute('data-id'));
    var it = (elList._items || []).filter(function (x) { return x.log_id === id; })[0];
    if (!it) return;

    if (btn.getAttribute('data-act') === 'edit') {
      f.id.value = it.log_id;
      f.date.value = it.study_date;
      f.subject.value = it.subject;
      f.material.value = it.material;
      f.range.value = it.range_text || '';
      f.minutes.value = it.minutes || '';
      f.memo.value = it.memo || '';
      elFeels.querySelectorAll('.ss-feel').forEach(function (b) {
        b.classList.toggle('on', Number(b.getAttribute('data-v')) === it.feeling);
      });
      elSave.textContent = 'なおした内容で保存する';
      elForm.classList.add('open');
      elOpen.textContent = '× 入力をやめる';
      elOpen.classList.add('cancel');
      elForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    if (!window.confirm(mmdd(it.study_date) + '「' + it.material + '」の記録をけしますか？')) return;
    fetch('/api/delete_self_study.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ log_id: id })
    })
      .then(function (r) { return r.json(); })
      .then(function () { load(); })
      .catch(function () {});
  });

  resetForm();
  load();
})();
</script>
</body>
</html>
