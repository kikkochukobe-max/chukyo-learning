<?php
declare(strict_types=1);

// 講師確認ページ: 生徒一覧（教室・期間フィルタ付き）→ 生徒詳細（単元カルテ+誤答一覧）
// デザインは生徒マイページと同じトークンで基調を朱→藍に反転、情報密度を上げる（テーブル可）。
// 誤解答の詳細・端末情報はこの講師画面だけに出す（マイページには出さない）。
// 権限: super_admin=全教室 / classroom_admin・teacher=担当教室(teacher_classrooms)のみ
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function grade_label(?string $grade): string
{
    if (!$grade) return '';
    if (preg_match('/^es(\d)$/', $grade, $m)) return '小' . $m[1];
    if (preg_match('/^js(\d)$/', $grade, $m)) return '中' . $m[1];
    if (preg_match('/^hs(\d)$/', $grade, $m)) return '高' . $m[1];
    return $grade;
}

// 学年ソート用の数値キー: 小1〜6 → 中1〜3 → 高1〜3 の順（未設定は0で最小）。
// 保存形式のブレ（es4/js1・小1/中3・数字のみ）どれでも並ぶようにする
function grade_sort_key(?string $grade): int
{
    if ($grade === null || $grade === '') return 0;
    // 全角数字（中２など）でも拾えるよう半角へ正規化してから判定する
    $grade = strtr($grade, ['０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9']);
    if (preg_match('/(es|小)\s*(\d)/u', $grade, $m)) return 100 + (int)$m[2];
    if (preg_match('/(js|中)\s*(\d)/u', $grade, $m)) return 200 + (int)$m[2];
    if (preg_match('/(hs|高)\s*(\d)/u', $grade, $m)) return 300 + (int)$m[2];
    if (preg_match('/(\d+)/', $grade, $m)) return (int)$m[1];
    return 0;
}

// unit_key の先頭要素（フォルダ名と同じ）を教科として扱う
const SUBJECT_LABELS = [
    'math' => '数学', 'english' => '英語', 'science' => '理科',
    'japanese' => '国語', 'social' => '社会', 'allgrade' => 'その他',
];

function subject_of(string $unitKey): string
{
    return explode('_', $unitKey, 2)[0];
}

function subject_label(string $subject): string
{
    return SUBJECT_LABELS[$subject] ?? $subject;
}

$actor = current_actor();

// ---- 未ログイン時: 講師ログインフォーム ----
if (!$actor || $actor['type'] !== 'teacher') {
    ?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>講師ページ | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root{--paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;--ai:#2C5F8A;--white:#fff;
    --radius:14px;--shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06)}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6;zoom:1.2}
  .box{max-width:360px;margin:80px auto;background:var(--white);border-radius:var(--radius);
    box-shadow:var(--shadow);border-top:4px solid var(--ai);padding:28px}
  h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:18px;color:var(--ai)}
  p.sub{font-size:12px;color:var(--ink-soft);margin-top:2px}
  label{display:block;font-size:12px;font-weight:700;margin-top:14px}
  input{width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;margin-top:4px}
  button{margin-top:18px;width:100%;background:var(--ai);color:#fff;border:none;border-radius:8px;
    padding:11px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Zen Maru Gothic',sans-serif}
  .err{color:#c0392b;font-size:12px;margin-top:8px;min-height:16px}
</style>
</head>
<body>
<div class="box">
  <h1>講師ページ</h1>
  <p class="sub">講師アカウントでログインしてください</p>
  <label>ログインID<input type="text" id="lid" autocomplete="username"></label>
  <label>パスワード<input type="password" id="lpw" autocomplete="current-password"></label>
  <button id="lbtn" type="button">ログイン</button>
  <div class="err" id="lerr"></div>
</div>
<script>
document.getElementById('lbtn').addEventListener('click', async () => {
  const err = document.getElementById('lerr');
  err.textContent = '';
  try {
    const res = await fetch('/api/auth.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        actor_type: 'teacher',
        login_id: document.getElementById('lid').value.trim(),
        password: document.getElementById('lpw').value,
      }),
    });
    const data = await res.json().catch(() => null);
    if (res.ok && data && data.ok) { location.reload(); }
    else if (data && data.error === 'locked') { err.textContent = '失敗が続いたためロック中です。10分後にやり直してください'; }
    else { err.textContent = 'ログインIDかパスワードが違います'; }
  } catch (e) { err.textContent = '通信エラーが発生しました'; }
});
document.getElementById('lpw').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') document.getElementById('lbtn').click();
});
</script>
</body>
</html><?php
    exit;
}

$teacherId = $actor['id'];
$pdo = db();

// ---- 講師の権限と担当教室 ----
$stmt = $pdo->prepare('SELECT role, teacher_name, must_change_password FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $teacherId]);
$me = $stmt->fetch();
$role = $me['role'];

// 初期パスワードのままなら、変更するまで先に進ませない
if ((int)$me['must_change_password'] === 1) {
    header('Location: /password.php');
    exit;
}

if ($role === 'super_admin') {
    $classrooms = $pdo->query('SELECT classroom_id, classroom_name FROM classrooms ORDER BY classroom_id')->fetchAll();
} else {
    $stmt = $pdo->prepare(
        'SELECT c.classroom_id, c.classroom_name FROM classrooms c
         JOIN teacher_classrooms tc ON tc.classroom_id = c.classroom_id
         WHERE tc.teacher_id = :id ORDER BY c.classroom_id'
    );
    $stmt->execute(['id' => $teacherId]);
    $classrooms = $stmt->fetchAll();
}
$allowedClassroomIds = array_map(fn($c) => (int)$c['classroom_id'], $classrooms);

// ---- 期間 ----
$period = (string)($_GET['period'] ?? 'week');
if (!in_array($period, ['today', 'yesterday', 'week', 'last_week', 'month', 'all'], true)) {
    $period = 'week';
}

// 任意期間（カレンダー指定）: ランキング画面で from/to が両方そろって妥当なら、期間タブより優先する。
// 他のビューにURLのfrom/toが残っていても無視する（ランキング限定の機能）。
$inRanking = ((string)($_GET['view'] ?? '')) === 'ranking' && !isset($_GET['student_id']);
$ymd = function ($s) {   // 'YYYY-MM-DD' として妥当なら正規化して返す。不正なら null（例: 2/30 を弾く）
    $s = (string)$s;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return ($d !== false && $d->format('Y-m-d') === $s) ? $s : null;
};
$customFrom = $inRanking ? $ymd($_GET['from'] ?? '') : null;
$customTo   = $inRanking ? $ymd($_GET['to'] ?? '') : null;
$isCustom = ($customFrom !== null && $customTo !== null && $customFrom <= $customTo);

$thisMonday = new DateTimeImmutable('monday this week');
if ($isCustom) {
    $from = new DateTimeImmutable($customFrom . ' 00:00:00');
    $to   = (new DateTimeImmutable($customTo . ' 00:00:00'))->modify('+1 day');  // 終了日を含める（排他上限にするため+1日）
} else {
    switch ($period) {
        case 'today':     $from = new DateTimeImmutable('today 00:00:00'); $to = $from->modify('+1 day'); break;
        case 'yesterday': $from = new DateTimeImmutable('yesterday 00:00:00'); $to = $from->modify('+1 day'); break;
        case 'last_week': $from = $thisMonday->modify('-7 days'); $to = $thisMonday; break;
        case 'month':     $from = new DateTimeImmutable('first day of this month 00:00:00'); $to = $from->modify('+1 month'); break;
        case 'all':       $from = null; $to = null; break;
        default:          $from = $thisMonday; $to = $thisMonday->modify('+7 days'); break;
    }
}
$periodLabels = ['today' => '今日', 'yesterday' => '昨日', 'week' => '今週', 'last_week' => '先週', 'month' => '今月', 'all' => '全期間'];
$fromStr = $from ? $from->format('Y-m-d 00:00:00') : null;
$toStr = $to ? $to->format('Y-m-d 00:00:00') : null;

// 期間フィルタSQL片（プレースホルダ名を変えて複数回使えるように）
function pf(string $col, ?string $fromStr, string $tag, array &$params): string
{
    global $toStr;
    if ($fromStr === null) return '';
    $params["from{$tag}"] = $fromStr;
    $params["to{$tag}"] = $toStr;
    return " AND {$col} >= :from{$tag} AND {$col} < :to{$tag}";
}

// ---- 教科フィルタ（unit_key の先頭が教科） ----
$filterSubject = (string)($_GET['subject'] ?? '');
if ($filterSubject !== '' && !preg_match('/^[a-z]+$/', $filterSubject)) {
    $filterSubject = '';
}

// 教科フィルタSQL片（pf と同様、プレースホルダ名を変えて複数回使える）
function sf(string $col, string $tag, array &$params): string
{
    global $filterSubject;
    if ($filterSubject === '') return '';
    $params["subj{$tag}"] = $filterSubject . '\\_%';
    return " AND {$col} LIKE :subj{$tag}";
}

$unitMeta = require __DIR__ . '/api/units.php';
require_once __DIR__ . '/api/time_ranking.php';   // 100マス等のタイム集計（生徒詳細・ランキング共用）
$detailStudentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

// ============================================================
// ランキングビュー（担当教室のみ。教室別/チェックした教室の混合どちらも可）
// イベント期間中は台帳(ranking_events.php)で決めた教室混合を権限に関係なく見られる
// （生徒がマイページで見ている順位と同じ集計。期間もイベント期間で固定）
// ============================================================
$rankView = ((string)($_GET['view'] ?? '')) === 'ranking' && $detailStudentId === 0;
$rankData = null;
$rankEvent = null;
$evMode = false;
$rankUnit = '';       // モード（単元）フィルタ。'' なら全モード
$rankGrade = '';      // 学年フィルタ。'' なら全学年
$rankGradeOptions = [];
if ($rankView) {
    require_once __DIR__ . '/api/ranking.php';
    $showTest = isset($_GET['showtest']);   // テスト生を表示するトグル（既定は除外）

    // モード（単元）フィルタ: units.php の台帳に載っている unit_key のみ有効
    $rankUnit = (string)($_GET['unit'] ?? '');
    if ($rankUnit !== '' && !isset($unitMeta[$rankUnit])) {
        $rankUnit = '';
    }
    // 学年プルダウンの選択肢: 担当教室に在籍する学年だけ。表記は問わない
    // （grade は自由入力なので es/js/hs 以外の表記=例「中3」も拾う。生徒一覧と同じ方針）。
    // 標準表記(es1-6/js1-3/hs1-3)は小→中→高の順で前に、それ以外は後ろに並べる。
    if (count($allowedClassroomIds) > 0) {
        $existingGrades = $pdo->query(
            'SELECT DISTINCT grade FROM students
              WHERE is_active = 1 AND grade IS NOT NULL AND grade <> \'\'
                AND classroom_id IN (' . implode(',', $allowedClassroomIds) . ')'
        )->fetchAll(PDO::FETCH_COLUMN);
        $gradeOrder = ['es1','es2','es3','es4','es5','es6','js1','js2','js3','hs1','hs2','hs3'];
        $known  = array_values(array_filter($gradeOrder, fn($g) => in_array($g, $existingGrades, true)));
        $others = array_values(array_filter($existingGrades, fn($g) => !in_array($g, $gradeOrder, true)));
        sort($others);
        $rankGradeOptions = array_merge($known, $others);
    }
    // 学年フィルタ: 実在する学年（$rankGradeOptions）に含まれるものだけ有効
    $rankGrade = (string)($_GET['grade'] ?? '');
    if ($rankGrade !== '' && !in_array($rankGrade, $rankGradeOptions, true)) {
        $rankGrade = '';
    }

    // 志望校フィルタ: 有効な志望校のみ選べる。選ぶと「その学校を私立/公立に志望している生徒」を
    // 全教室横断で集計する（教室チェックは無視。権限に関係なく全講師が見られる方針）。
    $rankSchoolOptions = $pdo->query(
        "SELECT target_school_id, name, kind FROM target_schools
          WHERE is_active = 1 ORDER BY kind, sort_order, name"
    )->fetchAll();
    $rankSchoolIds = array_map(fn($s) => (int)$s['target_school_id'], $rankSchoolOptions);
    $rankSchool = (int)($_GET['school'] ?? 0);
    if ($rankSchool > 0 && !in_array($rankSchool, $rankSchoolIds, true)) {
        $rankSchool = 0;
    }
    $rankSchoolName = '';
    if ($rankSchool > 0) {
        foreach ($rankSchoolOptions as $s) {
            if ((int)$s['target_school_id'] === $rankSchool) {
                $rankSchoolName = ($s['kind'] === 'private' ? '私立・' : '公立・') . $s['name'];
                break;
            }
        }
    }

    $rankEvent = ranking_active_event(require __DIR__ . '/api/ranking_events.php');
    $evMode = $rankEvent !== null && (string)($_GET['ev'] ?? '') === '1';
    if ($evMode) {
        // イベントは「生徒のマイページと同じ集計」を見せるものなので単元/学年フィルタは掛けない
        $rankUnit = '';
        $rankGrade = '';
        $evFromStr = $rankEvent['from'] . ' 00:00:00';
        $evToStr = (new DateTimeImmutable($rankEvent['to']))->modify('+1 day')->format('Y-m-d 00:00:00');
        $rows = ranking_rows($pdo, $rankEvent['classroom_ids'] ?? null, $evFromStr, $evToStr, $showTest);
        $cids = [];
    } elseif ($rankSchool > 0) {
        // 志望校ランキング: 全教室横断（教室チェックは無視）。全講師が同じ集計を見る。
        // 担当外教室の生徒は下の描画で名前のみ表示（詳細リンクなし）になる。
        $cids = [];
        $rows = ranking_rows($pdo, null, $fromStr, $toStr, $showTest, $rankUnit ?: null, $rankGrade ?: null, $rankSchool);
    } else {
        $cids = $_GET['cids'] ?? [];
        if (!is_array($cids)) {
            $cids = [$cids];
        }
        $cids = array_values(array_intersect(array_map('intval', $cids), $allowedClassroomIds));
        if (count($cids) === 0) {
            $cids = $allowedClassroomIds;   // 未指定は担当全教室の混合
        }
        $rows = ranking_rows($pdo, $cids, $fromStr, $toStr, $showTest, $rankUnit ?: null, $rankGrade ?: null);
    }
    $rankData = [
        'cids'   => $cids,
        'solved'  => ranking_ranked($rows, 'solved'),
        'correct' => ranking_ranked($rows, 'correct'),
        'rate'    => ranking_ranked($rows, 'rate'),
        'xp'      => ranking_ranked($rows, 'xp'),
    ];

    // 100マス（タイムアタック）ランキング: メインと同じスコープ（教室×期間 or イベント）で集計。
    // 志望校モードは「速さ」となじまないので出さない。
    $timeRankUnit = 'math_es_hyakumasu';
    $timeRankLabel = time_rank_units()[$timeRankUnit] ?? $timeRankUnit;
    $timeRankRows = [];
    if ($rankSchool === 0) {
        if ($evMode) {
            $timeRankRows = time_ranking_rows($pdo, $rankEvent['classroom_ids'] ?? null, $timeRankUnit, $evFromStr, $evToStr, $showTest);
        } else {
            $scopeCids = !empty($cids) ? $cids : $allowedClassroomIds;
            $timeRankRows = time_ranking_rows($pdo, $scopeCids, $timeRankUnit, $fromStr, $toStr, $showTest);
        }
    }
}

// ============================================================
// 生徒詳細ビュー
// ============================================================
$detail = null;
if ($detailStudentId > 0) {
    $stmt = $pdo->prepare(
        'SELECT s.*, c.classroom_name FROM students s
         JOIN classrooms c ON c.classroom_id = s.classroom_id
         WHERE s.student_id = :id'
    );
    $stmt->execute(['id' => $detailStudentId]);
    $detail = $stmt->fetch();
    if (!$detail || ($role !== 'super_admin' && !in_array((int)$detail['classroom_id'], $allowedClassroomIds, true))) {
        header('Location: /teacher.php');
        exit;
    }

    // この期間に記録がある教科（タブ表示用。教科フィルタはかけない）
    $params = ['id' => $detailStudentId];
    $w = pf('answered_at', $fromStr, 'sj', $params);
    $stmt = $pdo->prepare("SELECT DISTINCT unit_key FROM answer_logs WHERE student_id = :id{$w}");
    $stmt->execute($params);
    $dSubjects = array_values(array_unique(array_map(
        fn($r) => subject_of($r['unit_key']), $stmt->fetchAll()
    )));
    sort($dSubjects);
    if ($filterSubject !== '' && !in_array($filterSubject, $dSubjects, true)) {
        $filterSubject = '';
    }

    // 期間サマリー
    $params = ['id' => $detailStudentId];
    $w = pf('started_at', $fromStr, 'a', $params) . sf('unit_key', 'a', $params);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(duration_sec),0) FROM study_sessions WHERE student_id = :id{$w}");
    $stmt->execute($params);
    $dMinutes = (int)floor(((int)$stmt->fetchColumn()) / 60);

    $params = ['id' => $detailStudentId];
    $w = pf('answered_at', $fromStr, 'b', $params) . sf('unit_key', 'b', $params);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(is_correct),0) AS correct FROM answer_logs WHERE student_id = :id{$w}");
    $stmt->execute($params);
    $dAns = $stmt->fetch();
    $dSolved = (int)$dAns['total'];
    $dRate = $dSolved > 0 ? (int)round(100 * (int)$dAns['correct'] / $dSolved) : 0;

    // 解き直し（分数表示）: 分母=解き直しキューに入った問題数, 分子=2連続正解でクリア(mastered)した数。
    // retry_queue は現在の状態を表すので、一覧の解き直し列と同じく期間フィルタはかけない（教科タブには合わせる）。
    $params = ['id' => $detailStudentId];
    $w = sf('unit_key', 'rq', $params);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'mastered'),0) AS mastered
         FROM retry_queue WHERE student_id = :id{$w}"
    );
    $stmt->execute($params);
    $dRetry = $stmt->fetch();
    $dRetryTotal = (int)$dRetry['total'];
    $dRetryMastered = (int)$dRetry['mastered'];

    // 足あと用: 直近35日(=5週)の日別集計。日付タップで4カードをその日の値に差し替えるため
    // JSON でクライアントに渡す。教科フィルタ(sf)はカードと揃える。期間フィルタはかけない
    // （足あとは日別カレンダーで期間タブとは独立）。
    $footFrom = (new DateTimeImmutable('today'))->modify('-34 days')->format('Y-m-d 00:00:00');
    $daily = [];   // 'Y-m-d' => [min, solved, correct, redo]
    $touchDay = function (string $d) use (&$daily) {
        if (!isset($daily[$d])) $daily[$d] = ['min' => 0, 'solved' => 0, 'correct' => 0, 'redo' => 0];
    };
    // 学習時間（分）
    $params = ['id' => $detailStudentId, 'ff' => $footFrom];
    $w = sf('unit_key', 'fa', $params);
    $stmt = $pdo->prepare("SELECT DATE(started_at) d, COALESCE(SUM(duration_sec),0) sec FROM study_sessions
         WHERE student_id = :id AND started_at >= :ff{$w} GROUP BY DATE(started_at)");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) { $touchDay($r['d']); $daily[$r['d']]['min'] = (int)floor((int)$r['sec'] / 60); }
    // 解答数・正解数
    $params = ['id' => $detailStudentId, 'ff' => $footFrom];
    $w = sf('unit_key', 'fb', $params);
    $stmt = $pdo->prepare("SELECT DATE(answered_at) d, COUNT(*) total, COALESCE(SUM(is_correct),0) correct FROM answer_logs
         WHERE student_id = :id AND answered_at >= :ff{$w} GROUP BY DATE(answered_at)");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) { $touchDay($r['d']); $daily[$r['d']]['solved'] = (int)$r['total']; $daily[$r['d']]['correct'] = (int)$r['correct']; }
    // 解き直し（その日に解いた再挑戦=retry_of がある解答の数）
    $params = ['id' => $detailStudentId, 'ff' => $footFrom];
    $w = sf('unit_key', 'fc', $params);
    $stmt = $pdo->prepare("SELECT DATE(answered_at) d, COUNT(*) cnt FROM answer_logs
         WHERE student_id = :id AND retry_of IS NOT NULL AND answered_at >= :ff{$w} GROUP BY DATE(answered_at)");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) { $touchDay($r['d']); $daily[$r['d']]['redo'] = (int)$r['cnt']; }

    // 単元カルテ
    $params = ['id' => $detailStudentId];
    $w = pf('al.answered_at', $fromStr, 'c', $params) . sf('al.unit_key', 'c', $params);
    $stmt = $pdo->prepare(
        "SELECT al.unit_key, COALESCE(qc.label, al.question_key) AS label,
                COUNT(*) AS solved, COALESCE(SUM(al.is_correct),0) AS correct,
                MIN(al.answer_id) AS first_seen
         FROM answer_logs al
         LEFT JOIN question_catalog qc ON qc.unit_key = al.unit_key AND qc.question_key = al.question_key
         WHERE al.student_id = :id{$w}
         GROUP BY al.unit_key, al.question_key
         ORDER BY al.unit_key, first_seen"
    );
    $stmt->execute($params);
    $dUnits = [];
    foreach ($stmt->fetchAll() as $row) {
        $dUnits[$row['unit_key']][] = $row;
    }

    // 直近の誤答（講師のみ閲覧可の情報）。同じ問題(params_hash)は最新の1件にまとめ、最大60件。
    // 重複排除はSQLサブクエリだと同名プレースホルダを再利用できないため、多めに取ってPHPで畳む。
    $DWRONG_LIMIT = 60;      // 表示・印刷する誤答の上限（重複排除後）
    $DWRONG_SCAN  = 600;     // 重複を畳む前に走査する行数の上限
    $params = ['id' => $detailStudentId];
    $w = pf('al.answered_at', $fromStr, 'd', $params) . sf('al.unit_key', 'd', $params);
    $stmt = $pdo->prepare(
        "SELECT al.answered_at, al.unit_key, al.params_hash,
                COALESCE(qc.label, al.question_key) AS label,
                al.question_text, al.correct_answer, al.student_answer
         FROM answer_logs al
         LEFT JOIN question_catalog qc ON qc.unit_key = al.unit_key AND qc.question_key = al.question_key
         WHERE al.student_id = :id AND al.is_correct = 0{$w}
         ORDER BY al.answer_id DESC LIMIT {$DWRONG_SCAN}"
    );
    $stmt->execute($params);
    $dWrongs = [];
    $seenHash = [];
    foreach ($stmt->fetchAll() as $row) {
        $ph = $row['params_hash'];
        // params_hash がある問題は最新1件だけ（ORDER BY DESC なので先に来た行が最新）。
        // ハッシュが無い(NULL/空)問題は同一判定できないのでそのまま残す。
        if ($ph !== null && $ph !== '') {
            if (isset($seenHash[$ph])) continue;
            $seenHash[$ph] = true;
        }
        $dWrongs[] = $row;
        if (count($dWrongs) >= $DWRONG_LIMIT) break;
    }

    // 直近の学習セッション（端末情報つき・講師のみ）
    $params = ['id' => $detailStudentId];
    $w = pf('ss.started_at', $fromStr, 'e', $params) . sf('ss.unit_key', 'e', $params);
    $stmt = $pdo->prepare(
        "SELECT ss.started_at, ss.duration_sec, ss.total_questions, ss.correct_count, ss.unit_key,
                COALESCE(d.label, LEFT(ss.device_id, 8)) AS device_label
         FROM study_sessions ss
         LEFT JOIN devices d ON d.device_id = ss.device_id
         WHERE ss.student_id = :id{$w}
           AND (ss.total_questions > 0 OR COALESCE(ss.duration_sec, 0) >= 60)
         ORDER BY ss.session_id DESC LIMIT 20"
    );
    $stmt->execute($params);
    $dSessions = $stmt->fetchAll();

    // タイムアタック記録（100マス等・全期間のベストと上位10件）。
    // answer_logs を残さないゲームなので単元カルテには出ない → 専用に集計して見せる。
    $dTimeUnits = [];
    foreach (time_rank_units() as $tuk => $tlabel) {
        $tsum = time_records_summary($pdo, $detailStudentId, $tuk, null, null);
        if ($tsum['plays'] > 0) {
            $dTimeUnits[$tuk] = [
                'label'   => $tlabel,
                'summary' => $tsum,
                'top'     => time_records_top($pdo, $detailStudentId, $tuk, 10),
            ];
        }
    }
}

// ============================================================
// 生徒一覧ビュー
// ============================================================
$students = [];
if (!$detail && !$rankView) {
    $filterClassroom = isset($_GET['classroom_id']) ? (int)$_GET['classroom_id'] : 0;
    if ($filterClassroom > 0 && $role !== 'super_admin' && !in_array($filterClassroom, $allowedClassroomIds, true)) {
        $filterClassroom = 0;
    }

    // 学年フィルタ。表示対象に実在する学年だけをタブに出す（表記は問わない）。
    // 標準表記(es1-6/js1-3/hs1-3)は小→中→高の順で前に、それ以外の表記は後ろに並べる。
    $filterGrade = (string)($_GET['grade'] ?? '');
    $gradeScopeIds = $filterClassroom > 0 ? [$filterClassroom] : $allowedClassroomIds;
    $gradeOptions = [];
    if (count($gradeScopeIds) > 0) {
        $existingGrades = $pdo->query(
            "SELECT DISTINCT grade FROM students
              WHERE is_active = 1 AND grade IS NOT NULL AND grade <> ''
                AND classroom_id IN (" . implode(',', array_map('intval', $gradeScopeIds)) . ")"
        )->fetchAll(PDO::FETCH_COLUMN);
        // 学年は保存形式にブレがある（es4/js1 も 小4/中1 も混在）ので grade_sort_key で統一的に並べる。
        // 小1→…→高3。学年として解釈できない値（例: 「講師」）は sort_key=0 になるので末尾へ回す。
        $gradeOptions = $existingGrades;
        usort($gradeOptions, function (string $a, string $b): int {
            $ka = grade_sort_key($a) ?: 9999;
            $kb = grade_sort_key($b) ?: 9999;
            return $ka !== $kb ? $ka <=> $kb : strcmp($a, $b);
        });
    }
    // 選択中の学年が対象範囲に無ければ解除（教室切替で空リストにならないように）
    if ($filterGrade !== '' && !in_array($filterGrade, $gradeOptions, true)) {
        $filterGrade = '';
    }

    // テスト生（名前に「テスト」を含む）は既定で非表示。?showtest=1 で表示（ランキングと同方針）
    $showTest = isset($_GET['showtest']);

    // 同名プレースホルダは再利用できない(エミュレーション無効)ため、サブクエリごとに別名にする
    $params = [];
    $wSess = pf('ss.started_at', $fromStr, 's', $params) . sf('ss.unit_key', 's', $params);
    $wAns1 = pf('al.answered_at', $fromStr, 'n1', $params) . sf('al.unit_key', 'n1', $params);
    $wAns2 = pf('al.answered_at', $fromStr, 'n2', $params) . sf('al.unit_key', 'n2', $params);
    $wRetry = sf('rq.unit_key', 'r', $params);

    $sql =
        "SELECT s.student_id, s.login_id, s.student_name, s.grade, c.classroom_name,
                (SELECT COALESCE(SUM(ss.duration_sec),0) FROM study_sessions ss
                  WHERE ss.student_id = s.student_id{$wSess}) AS sec,
                (SELECT COUNT(*) FROM answer_logs al
                  WHERE al.student_id = s.student_id{$wAns1}) AS solved,
                (SELECT COALESCE(SUM(al.is_correct),0) FROM answer_logs al
                  WHERE al.student_id = s.student_id{$wAns2}) AS correct,
                (SELECT COUNT(*) FROM retry_queue rq
                  WHERE rq.student_id = s.student_id AND rq.status = 'pending'{$wRetry}) AS retries,
                (SELECT MAX(al.answered_at) FROM answer_logs al
                  WHERE al.student_id = s.student_id) AS last_at
         FROM students s
         JOIN classrooms c ON c.classroom_id = s.classroom_id
         WHERE s.is_active = 1";

    if ($role !== 'super_admin') {
        if (count($allowedClassroomIds) === 0) {
            $sql .= ' AND 1=0';
        } else {
            $sql .= ' AND s.classroom_id IN (' . implode(',', $allowedClassroomIds) . ')';
        }
    }
    if ($filterClassroom > 0) {
        $sql .= ' AND s.classroom_id = :cid';
        $params['cid'] = $filterClassroom;
    }
    if ($filterGrade !== '') {
        $sql .= ' AND s.grade = :grade';
        $params['grade'] = $filterGrade;
    }
    if (!$showTest) {
        $sql .= " AND s.student_name NOT LIKE '%テスト%'";
    }
    $sql .= ' ORDER BY c.classroom_id, s.login_id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
}

function qtab(array $extra): string
{
    return '?' . http_build_query(array_merge($_GET, $extra));
}

// スマホ用フィルタ ドロップダウン。$options = [ [表示名, 遷移先URL, 選択中?], ... ]
// 選ぶと onchange でそのURLへ遷移する（PC のpillタブと同じ挙動をselectで再現）。
function sp_select(string $label, array $options): string
{
    $h = '<label class="sp-fsel"><span>' . h($label) . '</span>'
        . '<select class="sp-sel" onchange="if(this.value)location.href=this.value">';
    foreach ($options as $o) {
        $h .= '<option value="' . h($o[1]) . '"' . (!empty($o[2]) ? ' selected' : '') . '>'
            . h($o[0]) . '</option>';
    }
    return $h . '</select></label>';
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>講師ページ | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@500;700;900&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
<script src="/assets/print-watermark.js"></script>
<style>
  :root{
    --paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;
    --shu:#C73E2E;--ai:#2C5F8A;--ai-soft:#E3ECF4;--kin:#C9A227;--white:#fff;
    --radius:14px;--shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06);
  }
  *{margin:0;padding:0;box-sizing:border-box}
  body{
    font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6;-webkit-font-smoothing:antialiased;zoom:1.2;
    -webkit-text-size-adjust:100%;text-size-adjust:100%;
  }
  .wrap{max-width:1240px;margin:0 auto;padding:0 16px 64px}
  header{display:flex;align-items:center;justify-content:flex-start;padding:14px 2px 10px;flex-wrap:wrap;gap:8px}
  .brand{display:flex;align-items:center;gap:10px}
  header img.logo{height:34px;width:auto;display:block}
  /* 学習ツール一覧: 他の管理ボタン(青の枠線)と区別する別色。ロゴのすぐ右に置く一番使う導線 */
  .toollink{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:12px;white-space:nowrap;
    color:#fff;background:var(--shu);border:1px solid var(--shu);border-radius:999px;
    padding:5px 14px;text-decoration:none;box-shadow:0 1px 2px rgba(199,62,46,.25)}
  .toollink:hover{background:#b0331f;border-color:#b0331f}
  .who{font-size:12px;color:var(--ink-soft);display:flex;align-items:center;gap:10px;margin-left:auto}
  .who b{font-size:14px;color:var(--ai);font-family:'Zen Maru Gothic',sans-serif}
  .logout{font-size:11px;color:var(--ai);border:1px solid var(--ai);border-radius:999px;
    padding:3px 12px;background:none;cursor:pointer;font-family:'Zen Maru Gothic',sans-serif;font-weight:700}

  .bar-row{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;align-items:center}
  .ptab{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:12px;
    padding:4px 14px;border-radius:999px;text-decoration:none;
    background:var(--white);color:var(--ink-soft);border:1.5px solid var(--grid)}
  .ptab.active{background:var(--ai);color:#fff;border-color:var(--ai)}
  .stab.active{background:var(--shu);border-color:var(--shu)}
  .subject-head{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:13px;color:var(--ai);
    border-left:4px solid var(--ai);padding-left:8px;margin-top:14px}

  .card{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    border-top:4px solid var(--ai);padding:18px;margin-top:14px}
  .card h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:18px;color:var(--ai)}
  .card h2{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:15px;margin-bottom:8px}

  table{border-collapse:collapse;width:100%;font-size:13px;margin-top:8px}
  th{font-size:11px;color:var(--ink-soft);font-weight:700;text-align:left;
    border-bottom:2px solid var(--ai-soft);padding:6px 8px;white-space:nowrap}
  td{border-bottom:1px solid #F3F0E8;padding:7px 8px;vertical-align:top}
  tr:last-child td{border-bottom:none}
  /* 数字は等幅で桁を揃える。Zen Kaku Gothic New のWeb版は tnum 非対応で
     読み込み後にプロポーショナル幅へ戻ってしまうため、数字だけ等幅数字を持つ
     システムフォントで描画し、漢字(「位」等)は Zen にフォールバックさせる */
  .num{text-align:right;white-space:nowrap;
    font-family:system-ui,'Segoe UI','Helvetica Neue',Arial,'Zen Kaku Gothic New',sans-serif;
    font-variant-numeric:tabular-nums;font-feature-settings:'tnum' 1}
  a.sname{color:var(--ai);font-weight:700;text-decoration:none}
  /* 生徒一覧は列幅を固定比率にし、教室を切り替えても幅がブレないようにする。
     table-layout:fixed + width:100% で、余った幅は colgroup の比率どおりに全列へ配分
     （1列だけが膨らまない）。長い氏名は…で省略 */
  #students-table{table-layout:fixed;width:100%}
  #students-table td:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .sort-hint{font-size:12px;color:var(--ink-soft);margin-top:8px;line-height:1.5}
  table.sortable th[data-sort]{cursor:pointer;user-select:none}
  table.sortable th[data-sort]:hover{color:var(--ai)}
  table.sortable th[data-sort]::after{content:'\2195';font-size:10px;margin-left:3px;opacity:.35}
  table.sortable th.sort-asc::after{content:'\25B2';opacity:1;color:var(--ai)}
  table.sortable th.sort-desc::after{content:'\25BC';opacity:1;color:var(--ai)}
  .lowrate{color:#B07B2E;font-weight:700}
  .okrate{color:#166534;font-weight:700}
  .chip{display:inline-block;font-size:11px;font-weight:700;color:var(--ai);
    background:var(--ai-soft);border-radius:999px;padding:0 10px;white-space:nowrap;
    font-family:'Zen Maru Gothic',sans-serif}
  .stats{display:flex;gap:26px;margin-top:8px;align-items:flex-start}
  /* 値の高さを固定して中央寄せ。1行の数字も2行の分数(解き直し)も同じ行に揃い、
     下のラベルも一直線に並ぶ（分数だけ上下にずれない） */
  .stat{display:flex;flex-direction:column}
  .stat .n{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:30px;line-height:1;
    display:flex;align-items:flex-end;height:44px}
  .stat .n small{font-size:13px;color:var(--ink-soft);margin-left:2px}
  .stat .l{font-size:11px;color:var(--ink-soft);margin-top:2px}
  /* 解き直し：分子(クリア数)/分母(解き直し問題数) の分数表示 */
  .stat .frac{display:inline-flex;flex-direction:column;align-items:center;line-height:1.02;font-size:18px}
  .stat .frac b{padding:0 7px 2px;border-bottom:2.5px solid currentColor;font-weight:900}
  .stat .frac i{padding:2px 7px 0;font-style:normal;font-weight:900}
  .back{font-size:13px;color:var(--ai);text-decoration:none;font-family:'Zen Maru Gothic',sans-serif;font-weight:700}
  .math{overflow-x:auto}
  .wrong-ans{color:var(--shu);font-weight:700}
  .scroll{overflow-x:auto}
  /* ランキングはPCの広い横幅では2枚ずつ横並び、狭い画面では1枚ずつ縦積み */
  .rank-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start;margin-top:14px}
  .rank-grid .card{margin:0}
  @media (max-width:820px){.rank-grid{grid-template-columns:1fr}}
  .fsel{font-size:12px;font-weight:700;color:var(--ink-soft);display:inline-flex;align-items:center;gap:6px}
  .fsel select,.fsel input[type=date]{font-family:'Zen Kaku Gothic New',sans-serif;font-size:13px;font-weight:500;color:var(--ink);
    border:1.5px solid var(--grid);border-radius:8px;padding:4px 8px;background:var(--white);cursor:pointer;width:auto}
  footer{margin-top:28px;text-align:center;font-size:11px;color:var(--ink-soft)}

  /* ---------- 足あと（生徒詳細・日別カレンダー） ---------- */
  .foot{margin-top:14px;border-top:1px dashed var(--grid);padding-top:12px}
  .foot-head{display:flex;align-items:center;gap:8px;margin-bottom:8px}
  .foot-title{font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:13px;color:var(--ink)}
  .foot-scope{font-size:12px;color:var(--ink-soft);flex:1 1 auto}
  .foot-scope.on{color:var(--ai);font-weight:700}
  .foot-more{flex:0 0 auto;font-family:'Zen Maru Gothic',sans-serif;font-weight:700;font-size:12px;
    color:var(--ai);background:none;border:1.5px solid var(--ai);border-radius:999px;padding:3px 12px;cursor:pointer}
  .foot-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px 4px}
  .foot-cell{border:none;background:none;padding:2px 0 3px;cursor:pointer;text-align:center;font:inherit;border-radius:8px}
  .foot-cell .fd{font-size:10px;color:var(--ink-soft);margin-bottom:3px;
    font-family:system-ui,'Segoe UI',Arial,sans-serif;font-variant-numeric:tabular-nums;white-space:nowrap}
  .foot-cell .fd.sun{color:var(--shu)}
  .foot-cell .fd.sat{color:var(--ai)}
  .foot-cell .sq{width:100%;aspect-ratio:1/1;max-width:26px;margin:0 auto 4px;border-radius:6px;
    background:var(--grid);border:1.5px solid transparent}
  .foot-cell .sq.l1{background:#F3D2CC}
  .foot-cell .sq.l2{background:#E59C8F}
  .foot-cell .sq.l3{background:#D4614E}
  .foot-cell .sq.l4{background:var(--shu)}
  /* 生徒マイページの足あとと同じく 分＝学習時間 / 問＝解いた問題数 を各日に表示（0はグレー） */
  .foot-cell .fm,.foot-cell .fs{font-size:10px;line-height:1.35;color:var(--ink-soft);
    font-family:system-ui,'Segoe UI',Arial,sans-serif;font-variant-numeric:tabular-nums;white-space:nowrap}
  .foot-cell .fm b,.foot-cell .fs b{font-weight:900;color:var(--ink)}
  .foot-cell .fm.z,.foot-cell .fm.z b,.foot-cell .fs.z,.foot-cell .fs.z b{color:#C7C2B6}
  .foot-cell.today .sq{border-color:var(--ink-soft)}
  .foot-cell.today .fd{color:var(--ink);font-weight:700}
  .foot-cell.sel .sq{border-color:var(--ai);box-shadow:0 0 0 2px var(--ai)}
  .foot-cell.sel .fd{color:var(--ai);font-weight:700}

  /* スマホ専用UI（フィルタのドロップダウン・密なリスト）はPCでは隠す。sp-hideはPC表示 */
  .sp-only{display:none}

  /* ---------- スマホ: フィルタはドロップダウン、一覧は「順位付き1行」で省スペースに ---------- */
  @media (max-width:640px){
    /* zoom(1.2)を解除し、代わりに個別に文字を大きくする（zoom拡大だと横がはみ出すため） */
    body{zoom:1;background-size:20px 20px}
    .wrap{padding:0 10px 48px}

    /* --- ヘッダー: 1行目=ロゴ+学習ツール一覧 … ログアウト(右上)、2行目=名前や他ボタン --- */
    header{padding:10px 0 6px;gap:8px 10px;flex-wrap:wrap;align-items:center}
    .brand{flex:1 1 auto;gap:9px;order:1}
    header img.logo{height:30px}
    .toollink{flex:0 0 auto;font-size:13px;padding:6px 14px}
    #logout-btn{order:2;margin-left:auto}
    .who{flex:1 1 100%;order:3;margin-left:0;flex-wrap:wrap;justify-content:flex-start;gap:6px 8px;align-items:center;font-size:13px}
    .who b{font-size:16px}
    .who>span{font-size:12px}
    .logout{flex:0 0 auto;white-space:nowrap;font-size:13px;padding:5px 13px}

    /* 余白を詰める */
    .card{padding:12px;margin-top:10px;border-radius:10px}
    .bar-row{gap:6px;margin-top:6px}
    .stats{gap:14px 22px;flex-wrap:wrap}

    /* 文字を大きく */
    .ptab,.stab{font-size:14px;padding:6px 14px}
    .card h1{font-size:20px}
    .card h2{font-size:17px}
    .subject-head{font-size:15px}
    table{font-size:15px;margin-top:6px}
    th{font-size:13px;padding:6px 6px}
    td{padding:8px 6px}
    .chip{font-size:13px;padding:1px 10px}
    .fsel,.fsel select,.fsel input[type=date]{font-size:14px}
    .stat .n{font-size:32px;height:40px}
    .stat .n small{font-size:15px}
    .stat .l{font-size:13px}
    .back,.sort-hint{font-size:14px}
    footer{font-size:12px}

    /* --- 共通: 横長テーブル(.mcard) を「1行=1カード・項目名つき」に組み替える --- */
    table.mcard{display:block!important;width:100%!important;min-width:0!important;table-layout:auto!important;margin-top:6px}
    table.mcard colgroup{display:none}
    table.mcard th{display:none}
    table.mcard tr:has(th){display:none}            /* thead を使わず先頭tr=見出しの表を隠す */
    table.mcard tbody{display:block}
    table.mcard tr{display:block;background:var(--white);border:1.5px solid var(--grid);
      border-radius:10px;box-shadow:var(--shadow);padding:9px 13px 10px;margin:9px 0}
    table.mcard td{display:flex;justify-content:space-between;align-items:baseline;gap:14px;
      border:none!important;padding:3px 0;font-size:15px;text-align:left!important;white-space:normal!important}
    table.mcard td::before{content:attr(data-label);color:var(--ink-soft);font-size:12px;
      font-weight:700;white-space:nowrap;flex:0 0 auto}
    table.mcard td.math{flex-direction:column;align-items:flex-start;gap:1px}   /* 数式は項目名の下に折り返す */
    table.mcard td.math::before{margin-bottom:1px}
    table.mcard td .katex{white-space:normal}

    /* --- スマホ切替: PC用フィルタタブ(.sp-hide)を隠し、ドロップダウン(.sp-only)を出す --- */
    .sp-hide{display:none!important}
    .sp-only{display:block}
    .sp-only.row{display:flex;flex-wrap:wrap;gap:8px 10px;align-items:center;margin-top:8px}
    .sp-fsel{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:var(--ink-soft);flex:1 1 30%}
    .sp-sel{font-family:'Zen Kaku Gothic New',sans-serif;font-size:15px;font-weight:700;color:var(--ink);
      border:1.5px solid var(--grid);border-radius:9px;padding:7px 9px;background:var(--white);cursor:pointer;
      flex:1 1 auto;min-width:0;max-width:100%}
    /* ランキング/テスト生/イベント等のpillは縮ませない（1文字ずつ折り返す事故を防ぐ） */
    .sp-only.row .ptab{flex:0 0 auto;white-space:nowrap}
    .sp-only.row .back{flex:0 0 auto;white-space:nowrap}

    /* --- 生徒一覧: テーブルを隠し、「数字ドロップダウン＋順位付き1行」の密なリストに --- */
    .sort-hint{display:none}
    #students-table{display:none}
    #sp-students .sp-metric-bar{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:2px 0 6px}
    #sp-students .sp-metric-bar label{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:700;color:var(--ink-soft)}
    #sp-metric-sel{flex:0 0 auto}
    .sp-taphint{font-size:11px;color:var(--ink-soft);text-align:right;line-height:1.3}

    /* 生徒一覧の1行: 学年 / 氏名 / 生徒コード（数字系はランキングで見る） */
    .sp-list{list-style:none;margin:4px 0 0;padding:0}
    .sp-list li{display:flex;align-items:baseline;gap:10px;padding:9px 2px;border-bottom:1px solid #F0EDE4}
    .sp-list li:last-child{border-bottom:none}
    /* 生徒コード(左端) → 学年 → 氏名(可変) → 問題数(固定幅・右揃えで縦に揃う) */
    .sp-list .r-code{flex:0 0 auto;color:var(--ink-soft);font-size:14px;
      font-family:system-ui,'Segoe UI',Arial,sans-serif;font-variant-numeric:tabular-nums}
    .sp-list .r-grade{flex:0 0 2.6em;color:var(--ink-soft);font-size:13px;white-space:nowrap}
    .sp-list .r-name{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
      font-size:16px;font-weight:700;font-family:'Zen Maru Gothic',sans-serif}
    .sp-list .r-name a.sname{color:var(--ai);text-decoration:none;font-weight:700;font-family:'Zen Maru Gothic',sans-serif}
    .sp-list .r-solved{flex:0 0 3.6em;text-align:right;color:var(--ink-soft);font-size:13px;white-space:nowrap;
      font-family:system-ui,'Segoe UI',Arial,sans-serif;font-variant-numeric:tabular-nums}

    /* --- ランキング: 種類ドロップダウンで選んだ表だけ表示。1行=順位/学年/氏名/数字 --- */
    .sp-rank-bar{margin:10px 0 2px}
    .card[data-rank]{display:none}
    .card[data-rank].rank-active{display:block}
    table.rankt{display:block!important;width:100%!important;min-width:0!important;table-layout:auto!important;margin-top:4px}
    table.rankt colgroup{display:none}
    table.rankt th{display:none}
    table.rankt tr:has(th){display:none}
    table.rankt tbody{display:block}
    table.rankt tr{display:flex;align-items:baseline;gap:9px;padding:8px 2px;border-bottom:1px solid #F0EDE4}
    table.rankt tr:last-child{border-bottom:none}
    table.rankt td{border:none!important;padding:0;font-size:15px;white-space:nowrap}
    table.rankt td::before{display:none!important}
    table.rankt td:nth-child(1){order:1;flex:0 0 2.6em;text-align:right;font-weight:700}      /* 順位 */
    table.rankt td:nth-child(4){order:2;flex:0 0 2.7em;color:var(--ink-soft);font-size:13px}  /* 学年 */
    table.rankt td:nth-child(2){order:3;flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;font-weight:700}/* 氏名 */
    table.rankt td:nth-child(2) a.sname{font-weight:700}
    table.rankt td:nth-child(3){display:none}                                                /* 教室(非表示) */
    table.rankt td:nth-child(5){order:4;flex:0 0 auto;font-weight:700;text-align:right;
      font-family:system-ui,'Segoe UI',Arial,sans-serif;font-variant-numeric:tabular-nums}   /* 数字 */
    table.rankt td:nth-child(6){display:none}                                                /* 解答数/回数(非表示) */
  }
</style>
</head>
<body>
<div class="wrap">

  <header>
    <div class="brand">
      <img class="logo" src="https://chukyokobetsu.com/manage/wp-content/themes/chukyo/images/common/logo_chukyo.png"
           alt="中京個別指導学院">
      <a class="toollink" href="/learning/">学習ツール一覧</a>
    </div>
    <div class="who">
      <b><?= h($me['teacher_name']) ?> 先生</b>
      <span><?= h($role) ?></span>
      <a class="logout" href="/admin.php" style="text-decoration:none;">アカウント管理</a>
      <a class="logout" href="/password.php" style="text-decoration:none;">パスワード変更</a>
    </div>
    <button class="logout" id="logout-btn" type="button">ログアウト</button>
  </header>

<?php if ($detail): ?>
  <!-- ============ 生徒詳細 ============ -->
  <div class="bar-row sp-hide">
    <a class="back" href="<?= h(qtab(['student_id' => null])) ?>">← 生徒一覧へ</a>
<?php foreach ($periodLabels as $key => $label): ?>
    <a class="ptab<?= $period === $key ? ' active' : '' ?>" href="<?= h(qtab(['period' => $key])) ?>"><?= h($label) ?></a>
<?php endforeach; ?>
<?php if (count($dSubjects) > 1): ?>
    <span style="flex:1"></span>
    <a class="ptab stab<?= $filterSubject === '' ? ' active' : '' ?>" href="<?= h(qtab(['subject' => null])) ?>">全教科</a>
<?php foreach ($dSubjects as $sj): ?>
    <a class="ptab stab<?= $filterSubject === $sj ? ' active' : '' ?>" href="<?= h(qtab(['subject' => $sj])) ?>"><?= h(subject_label($sj)) ?></a>
<?php endforeach; ?>
<?php endif; ?>
  </div>
  <div class="bar-row sp-only row">
    <a class="back" href="<?= h(qtab(['student_id' => null])) ?>">← 一覧</a>
<?php
    $spOpt = [];
    foreach ($periodLabels as $key => $label) $spOpt[] = [$label, qtab(['period' => $key]), $period === $key];
    echo sp_select('期間', $spOpt);
    if (count($dSubjects) > 1) {
        $spOpt = [['全教科', qtab(['subject' => null]), $filterSubject === '']];
        foreach ($dSubjects as $sj) $spOpt[] = [subject_label($sj), qtab(['subject' => $sj]), $filterSubject === $sj];
        echo sp_select('教科', $spOpt);
    }
?>
  </div>

  <div class="card">
    <h1><?= h($detail['student_name']) ?> <span style="font-size:12px;color:var(--ink-soft);font-weight:500;">
      <?= h($detail['login_id']) ?> ・ <?= h($detail['classroom_name']) ?>教室<?= $detail['grade'] ? '・' . h(grade_label($detail['grade'])) : '' ?></span></h1>
    <div class="stats">
      <div class="stat"><div class="n" id="stat-min"><?= $dMinutes ?><small>分</small></div><div class="l">学習時間</div></div>
      <div class="stat"><div class="n" id="stat-solved"><?= $dSolved ?><small>問</small></div><div class="l">解いた問題</div></div>
      <div class="stat"><div class="n" id="stat-rate"><?= $dRate ?><small>%</small></div><div class="l">正答率</div></div>
      <div class="stat" title="解き直しキューに入った問題数のうち、2連続正解でクリアした数（全期間）。日付を選ぶとその日に解き直した問題数">
        <div class="n" id="stat-redo"><?php if ($dRetryTotal > 0): ?><span class="frac"><b><?= $dRetryMastered ?></b><i><?= $dRetryTotal ?></i></span><?php else: ?>—<?php endif; ?></div>
        <div class="l">解き直し</div></div>
    </div>

    <!-- 足あと（直近2週間、「さらに見る」で1か月。日付タップで上の4カードがその日に切替） -->
    <div class="foot" id="foot">
      <div class="foot-head">
        <span class="foot-title">足あと</span>
        <span class="foot-scope" id="foot-scope"><?= h($periodLabels[$period]) ?>のまとめ</span>
        <button type="button" class="foot-more" id="foot-more" aria-expanded="false">さらに見る ▼</button>
      </div>
      <div class="foot-grid" id="foot-grid"></div>
    </div>
    <script type="application/json" id="foot-data"><?= json_encode([
        'today'  => (new DateTimeImmutable('today'))->format('Y-m-d'),
        'period' => $periodLabels[$period],
        'daily'  => $daily,
    ], JSON_UNESCAPED_UNICODE) ?></script>
  </div>

  <div class="card">
    <h2>単元カルテ（<?= h($periodLabels[$period]) ?><?= $filterSubject !== '' ? '・' . h(subject_label($filterSubject)) : '' ?>）</h2>
<?php if (count($dUnits) === 0): ?>
    <p style="font-size:13px;color:var(--ink-soft);">この期間の解答記録はありません</p>
<?php else: ?>
<?php
    // 教科ごとにグループ化して見出しを付ける（教科で絞り込み中は見出し不要）
    $dBySubject = [];
    foreach ($dUnits as $unitKey => $rows) {
        $dBySubject[subject_of($unitKey)][$unitKey] = $rows;
    }
    ksort($dBySubject);
?>
<?php foreach ($dBySubject as $sj => $subjectUnits): ?>
<?php if ($filterSubject === '' && count($dSubjects) > 1): ?>
    <p class="subject-head"><?= h(subject_label($sj)) ?></p>
<?php endif; ?>
<?php foreach ($subjectUnits as $unitKey => $rows):
    $meta = $unitMeta[$unitKey] ?? ['title' => $unitKey, 'sub' => ''];
?>
    <p style="font-size:13px;font-weight:700;margin-top:8px;"><?= h($meta['title']) ?> <span style="font-size:11px;color:var(--ink-soft);font-weight:500;"><?= h($meta['sub']) ?></span></p>
    <div class="scroll">
    <table class="mcard">
      <tr><th>種類</th><th class="num">解答数</th><th class="num">正解</th><th class="num">正答率</th></tr>
<?php foreach ($rows as $row):
    $solved = (int)$row['solved'];
    $correct = (int)$row['correct'];
    $rate = $solved > 0 ? (int)round(100 * $correct / $solved) : 0;
?>
      <tr>
        <td data-label="種類"><?= h($row['label']) ?></td>
        <td class="num" data-label="解答数"><?= $solved ?></td>
        <td class="num" data-label="正解"><?= $correct ?></td>
        <td class="num <?= $rate < 60 ? 'lowrate' : ($rate >= 90 ? 'okrate' : '') ?>" data-label="正答率"><?= $rate ?>%</td>
      </tr>
<?php endforeach; ?>
    </table>
    </div>
<?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>
  </div>

<?php if (!empty($dTimeUnits)): ?>
  <div class="card" style="border-top-color:var(--kin);">
    <h2>タイムアタック記録（全期間・速い順）</h2>
<?php foreach ($dTimeUnits as $tuk => $tinfo): ?>
    <p style="font-size:13px;font-weight:700;margin-top:8px;"><?= h($tinfo['label']) ?>
      <span style="font-size:11px;color:var(--ink-soft);font-weight:500;">ベスト <?= h(fmt_time_ms((int)$tinfo['summary']['best'])) ?> ・ これまで<?= (int)$tinfo['summary']['plays'] ?>回</span></p>
    <div class="scroll">
    <table class="mcard">
      <tr><th class="num">順位</th><th class="num">タイム</th><th class="num">ミス</th><th>表示</th><th>日時</th></tr>
<?php foreach ($tinfo['top'] as $ti => $trow):
    $tmode = isset($trow['meta']['mode']) ? ($trow['meta']['mode'] === 'grid' ? '100マス' : 'よこ') : '';
?>
      <tr>
        <td class="num" data-label="順位" style="font-weight:700;<?= $ti < 3 ? 'color:var(--kin);' : '' ?>"><?= $ti + 1 ?>位</td>
        <td class="num" data-label="タイム" style="font-weight:700;"><?= h(fmt_time_ms((int)$trow['time_ms'])) ?></td>
        <td class="num" data-label="ミス"><?= (int)$trow['miss_count'] ?></td>
        <td data-label="表示"><?= h($tmode) ?></td>
        <td data-label="日時" style="white-space:nowrap;"><?= h(substr((string)$trow['created_at'], 0, 16)) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    </div>
<?php endforeach; ?>
  </div>
<?php endif; ?>

  <div class="card">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <h2 style="margin:0;">直近の誤答（最大60件・同じ問題は1件にまとめ）</h2>
<?php if (count($dWrongs) > 0): ?>
      <button type="button" id="print-wrongs-btn" class="ptab" style="cursor:pointer;border-color:var(--shu);color:var(--shu);">🖨 解き直しプリント</button>
<?php endif; ?>
    </div>
<?php if (count($dWrongs) === 0): ?>
    <p style="font-size:13px;color:var(--ink-soft);">この期間の誤答はありません</p>
<?php else: ?>
<?php $wrongModes = array_keys(array_reduce($dWrongs, function ($c, $w) { $c[$w['label']] = true; return $c; }, [])); ?>
<?php if (count($wrongModes) > 1): ?>
    <div class="bar-row" id="wrong-mode-filter" style="margin:6px 0 2px;">
      <span style="font-size:12px;color:var(--ink-soft);font-weight:700;align-self:center;">種類でしぼる</span>
      <button class="ptab active" type="button" data-mode="">すべて</button>
<?php foreach ($wrongModes as $m): ?>
      <button class="ptab" type="button" data-mode="<?= h($m) ?>"><?= h($m) ?></button>
<?php endforeach; ?>
    </div>
<?php endif; ?>
    <div class="scroll">
    <table id="wrongs-table" class="mcard">
      <tr><th>日時</th><th>単元</th><th>種類</th><th>問題</th><th>正解</th><th>生徒の答え</th></tr>
<?php foreach ($dWrongs as $wr):
    $wUnitTitle = ($unitMeta[$wr['unit_key']] ?? null)['title'] ?? $wr['unit_key'];
?>
      <tr data-mode="<?= h($wr['label']) ?>">
        <td data-label="日時" style="white-space:nowrap;"><?= h(substr($wr['answered_at'], 5, 11)) ?></td>
        <td data-label="単元" style="white-space:nowrap;font-size:12px;"><?= h($wUnitTitle) ?></td>
        <td data-label="種類"><span class="chip"><?= h($wr['label']) ?></span></td>
        <td class="math" data-label="問題" data-math="<?= h($wr['question_text']) ?>"><?= h($wr['question_text']) ?></td>
        <td class="math" data-label="正解" data-math="<?= h($wr['correct_answer']) ?>"><?= h($wr['correct_answer']) ?></td>
        <td class="math wrong-ans" data-label="生徒の答え" data-math="<?= h($wr['student_answer']) ?>"><?= h($wr['student_answer']) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    </div>
    <script type="application/json" id="print-wrongs-data"><?= json_encode([
      'student' => $detail['student_name'],
      'meta'    => $detail['classroom_name'] . '教室' . ($detail['grade'] ? '・' . grade_label($detail['grade']) : ''),
      'period'  => $periodLabels[$period] . ($filterSubject !== '' ? '・' . subject_label($filterSubject) : ''),
      'items'   => array_map(function ($w) use ($unitMeta) {
          return [
              'unit'  => (($unitMeta[$w['unit_key']] ?? null)['title'] ?? $w['unit_key']),
              'label' => $w['label'],
              'q'     => $w['question_text'],
              'a'     => $w['correct_answer'],
              'sa'    => $w['student_answer'],
          ];
      }, $dWrongs),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>
  </div>

  <div class="card">
    <h2>学習セッション（最大20件）</h2>
<?php if (count($dSessions) === 0): ?>
    <p style="font-size:13px;color:var(--ink-soft);">この期間の学習セッションはありません</p>
<?php else: ?>
    <div class="scroll">
    <table class="mcard">
      <tr><th>開始日時</th><th>単元</th><th class="num">時間</th><th class="num">解答数</th><th class="num">正解</th><th>端末</th></tr>
<?php foreach ($dSessions as $ss):
    $unitTitle = ($unitMeta[$ss['unit_key']] ?? null)['title'] ?? $ss['unit_key'];
?>
      <tr>
        <td data-label="開始日時" style="white-space:nowrap;"><?= h(substr($ss['started_at'], 5, 11)) ?></td>
        <td data-label="単元"><?= h($unitTitle) ?></td>
        <td class="num" data-label="時間"><?= $ss['duration_sec'] !== null ? floor((int)$ss['duration_sec'] / 60) . '分' : '-' ?></td>
        <td class="num" data-label="解答数"><?= (int)$ss['total_questions'] ?></td>
        <td class="num" data-label="正解"><?= (int)$ss['correct_count'] ?></td>
        <td data-label="端末"><?= h($ss['device_label']) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    </div>
<?php endif; ?>
  </div>

<?php elseif ($rankView): ?>
  <!-- ============ ランキング ============ -->
  <div class="bar-row sp-hide">
    <a class="back" href="<?= h(qtab(['view' => null, 'cids' => null, 'ev' => null, 'unit' => null, 'grade' => null, 'school' => null, 'from' => null, 'to' => null])) ?>">← 生徒一覧へ</a>
<?php foreach ($periodLabels as $key => $label): ?>
    <a class="ptab<?= !$evMode && !$isCustom && $period === $key ? ' active' : '' ?>" href="<?= h(qtab(['period' => $key, 'ev' => null, 'from' => null, 'to' => null])) ?>"><?= h($label) ?></a>
<?php endforeach; ?>
<?php if ($rankEvent !== null): ?>
    <a class="ptab<?= $evMode ? ' active' : '' ?>" style="<?= $evMode ? 'background:var(--kin);border-color:var(--kin);' : 'border-color:var(--kin);color:var(--kin);' ?>" href="<?= h(qtab(['ev' => '1', 'from' => null, 'to' => null])) ?>"><?= h($rankEvent['label']) ?></a>
<?php endif; ?>
    <span style="flex:1"></span>
    <a class="ptab<?= $showTest ? ' active' : '' ?>" href="<?= h(qtab(['showtest' => $showTest ? null : '1'])) ?>"><?= $showTest ? 'テスト生を隠す' : 'テスト生を表示' ?></a>
  </div>
  <div class="bar-row sp-only row">
    <a class="back" href="<?= h(qtab(['view' => null, 'cids' => null, 'ev' => null, 'unit' => null, 'grade' => null, 'school' => null, 'from' => null, 'to' => null])) ?>">← 一覧</a>
<?php
    $spOpt = [];
    foreach ($periodLabels as $key => $label) $spOpt[] = [$label, qtab(['period' => $key, 'ev' => null, 'from' => null, 'to' => null]), !$evMode && !$isCustom && $period === $key];
    echo sp_select('期間', $spOpt);
?>
<?php if ($rankEvent !== null): ?>
    <a class="ptab<?= $evMode ? ' active' : '' ?>" style="<?= $evMode ? 'background:var(--kin);border-color:var(--kin);' : 'border-color:var(--kin);color:var(--kin);' ?>" href="<?= h(qtab(['ev' => '1', 'from' => null, 'to' => null])) ?>"><?= h($rankEvent['label']) ?></a>
<?php endif; ?>
    <a class="ptab<?= $showTest ? ' active' : '' ?>" href="<?= h(qtab(['showtest' => $showTest ? null : '1'])) ?>"><?= $showTest ? 'テスト生を隠す' : 'テスト生を表示' ?></a>
  </div>

<?php if ($evMode): ?>
  <div class="card">
    <h1><?= h($rankEvent['label']) ?> <span style="font-size:12px;color:var(--ink-soft);font-weight:500;">（<?= h((new DateTimeImmutable($rankEvent['from']))->format('n/j')) ?>〜<?= h((new DateTimeImmutable($rankEvent['to']))->format('n/j')) ?>・<?= isset($rankEvent['classroom_ids']) ? '対象教室混合' : '全教室混合' ?>）</span></h1>
    <p style="font-size:11px;color:var(--ink-soft);margin-top:4px;">生徒のマイページに表示している順位と同じ集計です（期間タブとは独立にイベント期間内の実績で集計）</p>
  </div>
<?php else: ?>
<?php
    $rankTitleSuffix = $isCustom
        ? (new DateTimeImmutable($customFrom))->format('Y/n/j') . '〜' . (new DateTimeImmutable($customTo))->format('Y/n/j')
        : $periodLabels[$period];
    if ($rankUnit !== '') $rankTitleSuffix .= '・' . (($unitMeta[$rankUnit] ?? null)['title'] ?? $rankUnit);
    if ($rankGrade !== '') $rankTitleSuffix .= '・' . grade_label($rankGrade);
    if ($rankSchool > 0) $rankTitleSuffix .= '・' . $rankSchoolName . '志望';
?>
  <div class="card">
    <h1>ランキング <span style="font-size:12px;color:var(--ink-soft);font-weight:500;">（<?= h($rankTitleSuffix) ?>）</span></h1>
    <form method="get" style="margin-top:10px;display:flex;flex-direction:column;gap:10px;">
      <input type="hidden" name="view" value="ranking">
      <input type="hidden" name="period" value="<?= h($period) ?>">
<?php if ($showTest): ?>
      <input type="hidden" name="showtest" value="1">
<?php endif; ?>
      <div class="bar-row" style="margin:0;align-items:center;">
        <label class="fsel">開始<input type="date" name="from" value="<?= h($customFrom ?? '') ?>"></label>
        <label class="fsel">終了<input type="date" name="to" value="<?= h($customTo ?? '') ?>"></label>
        <span style="font-size:11px;color:var(--ink-soft);">日付を入れて「表示」で任意期間ランキング。空にして期間タブを押すと通常表示に戻ります</span>
      </div>
      <div class="bar-row" style="margin:0;">
        <label class="fsel">モード
          <select name="unit">
            <option value="">全モード</option>
<?php foreach ($unitMeta as $uk => $um): ?>
            <option value="<?= h($uk) ?>"<?= $rankUnit === $uk ? ' selected' : '' ?>><?= h($um['title']) ?></option>
<?php endforeach; ?>
          </select>
        </label>
<?php if (count($rankGradeOptions) > 0): ?>
        <label class="fsel">学年
          <select name="grade">
            <option value="">全学年</option>
<?php foreach ($rankGradeOptions as $g): ?>
            <option value="<?= h($g) ?>"<?= $rankGrade === $g ? ' selected' : '' ?>><?= h(grade_label($g)) ?></option>
<?php endforeach; ?>
          </select>
        </label>
<?php endif; ?>
<?php
    $privSchools = array_values(array_filter($rankSchoolOptions, fn($s) => $s['kind'] === 'private'));
    $pubSchools  = array_values(array_filter($rankSchoolOptions, fn($s) => $s['kind'] === 'public'));
?>
<?php if (count($rankSchoolOptions) > 0): ?>
        <label class="fsel">志望校
          <select name="school">
            <option value="">志望校で絞らない</option>
<?php if (count($privSchools) > 0): ?>
            <optgroup label="私立">
<?php foreach ($privSchools as $s): ?>
              <option value="<?= (int)$s['target_school_id'] ?>"<?= $rankSchool === (int)$s['target_school_id'] ? ' selected' : '' ?>><?= h($s['name']) ?></option>
<?php endforeach; ?>
            </optgroup>
<?php endif; ?>
<?php if (count($pubSchools) > 0): ?>
            <optgroup label="公立">
<?php foreach ($pubSchools as $s): ?>
              <option value="<?= (int)$s['target_school_id'] ?>"<?= $rankSchool === (int)$s['target_school_id'] ? ' selected' : '' ?>><?= h($s['name']) ?></option>
<?php endforeach; ?>
            </optgroup>
<?php endif; ?>
          </select>
        </label>
<?php endif; ?>
      </div>
<?php if ($rankSchool > 0): ?>
      <div class="bar-row" style="margin:0;">
        <span style="font-size:12px;color:var(--kin);font-weight:700;">「<?= h($rankSchoolName) ?>」志望の生徒を全教室から集計中（教室の絞り込みは無効）</span>
      </div>
<?php elseif (count($classrooms) > 1): ?>
      <div class="bar-row" style="margin:0;">
<?php foreach ($classrooms as $c): ?>
        <label style="font-size:13px;display:inline-flex;align-items:center;gap:4px;background:var(--white);border:1.5px solid var(--grid);border-radius:999px;padding:3px 12px;cursor:pointer;">
          <input type="checkbox" name="cids[]" value="<?= (int)$c['classroom_id'] ?>"
            <?= in_array((int)$c['classroom_id'], $rankData['cids'], true) ? 'checked' : '' ?>>
          <?= h($c['classroom_name']) ?>
        </label>
<?php endforeach; ?>
      </div>
<?php endif; ?>
      <div class="bar-row" style="margin:0;align-items:center;">
        <button type="submit" class="ptab active" style="cursor:pointer;">表示</button>
<?php if (count($classrooms) > 1): ?>
        <span style="font-size:11px;color:var(--ink-soft);">1教室だけチェックすると教室別、複数チェックすると混合ランキング</span>
<?php endif; ?>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php
    $rankSections = [
        ['key' => 'solved',  'title' => '解答数ランキング', 'unit' => '問'],
        ['key' => 'correct', 'title' => '正解数ランキング', 'unit' => '正解'],
        ['key' => 'rate',    'title' => '正答率ランキング', 'unit' => '%'],
        ['key' => 'xp',      'title' => 'XPランキング',     'unit' => 'XP'],
    ];
?>
  <div class="sp-only sp-rank-bar">
    <label class="sp-fsel"><span>ランキングの種類</span>
      <select class="sp-sel" id="sp-rank-type">
        <option value="solved">解答数</option>
        <option value="correct">正解数</option>
        <option value="rate">正答率</option>
        <option value="xp">XP</option>
<?php if ($rankSchool === 0): ?>
        <option value="time"><?= h($timeRankLabel) ?></option>
<?php endif; ?>
      </select>
    </label>
  </div>
  <div class="rank-grid">
<?php foreach ($rankSections as $sec): $list = $rankData[$sec['key']]; ?>
  <div class="card" data-rank="<?= h($sec['key']) ?>">
    <h2><?= h($sec['title']) ?><?php if ($sec['key'] === 'rate'): ?> <span style="font-size:11px;color:var(--ink-soft);font-weight:500;">（<?= RANK_MIN_SOLVED ?>問以上解いた生徒のみ）</span><?php endif; ?></h2>
<?php if (count($list) === 0): ?>
    <p style="font-size:13px;color:var(--ink-soft);">この期間の対象者はいません</p>
<?php else: ?>
    <div class="scroll">
    <table class="rankt" style="table-layout:fixed;width:auto;min-width:412px;">
      <colgroup>
        <col style="width:72px"><col style="width:124px"><col style="width:76px"><col style="width:52px"><col style="width:88px"><?php if ($sec['key'] === 'rate'): ?><col style="width:88px"><?php endif; ?>
      </colgroup>
      <tr><th class="num">順位</th><th>生徒</th><th>教室</th><th>学年</th>
        <th class="num"><?= h($sec['unit']) ?></th><?php if ($sec['key'] === 'rate'): ?><th class="num">解答数</th><?php endif; ?></tr>
<?php foreach ($list as $r): ?>
      <tr>
        <td class="num" data-label="順位" style="font-weight:700;<?= $r['rank'] <= 3 ? 'color:var(--kin);' : '' ?>"><?= $r['rank'] ?>位</td>
        <?php // 担当外教室の生徒は詳細を開けないのでリンクにしない（イベントランキングで載りうる） ?>
<?php if (in_array((int)$r['classroom_id'], $allowedClassroomIds, true)): ?>
        <td data-label="生徒"><a class="sname" href="<?= h(qtab(['view' => null, 'cids' => null, 'ev' => null, 'unit' => null, 'grade' => null, 'school' => null, 'student_id' => $r['student_id']])) ?>"><?= h($r['student_name']) ?></a></td>
<?php else: ?>
        <td data-label="生徒"><?= h($r['student_name']) ?></td>
<?php endif; ?>
        <td data-label="教室"><?= h($r['classroom_name']) ?></td>
        <td data-label="学年"><?= h(grade_label($r['grade'])) ?></td>
        <td class="num" data-label="<?= h($sec['unit']) ?>"><?= $sec['key'] === 'rate' ? $r['value'] . '%' : (int)$r['value'] ?></td>
<?php if ($sec['key'] === 'rate'): ?>
        <td class="num" data-label="解答数"><?= (int)$r['solved'] ?></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </table>
    </div>
<?php endif; ?>
  </div>
<?php endforeach; ?>
  </div>

<?php if ($rankSchool === 0): ?>
  <!-- 100マス たし算 タイムアタック ランキング（速い順） -->
  <div class="card" data-rank="time" style="border-top-color:var(--kin);">
    <h2><?= h($timeRankLabel) ?> タイムアタック <span style="font-size:11px;color:var(--ink-soft);font-weight:500;">（ベストタイムの速い順）</span></h2>
<?php if (count($timeRankRows) === 0): ?>
    <p style="font-size:13px;color:var(--ink-soft);">この期間に 100マスで遊んだ生徒はいません</p>
<?php else: ?>
    <div class="scroll">
    <table class="rankt" style="table-layout:fixed;width:auto;min-width:460px;">
      <colgroup>
        <col style="width:64px"><col style="width:130px"><col style="width:78px"><col style="width:52px"><col style="width:92px"><col style="width:64px">
      </colgroup>
      <tr><th class="num">順位</th><th>生徒</th><th>教室</th><th>学年</th><th class="num">ベスト</th><th class="num">回数</th></tr>
<?php foreach ($timeRankRows as $r): ?>
      <tr>
        <td class="num" data-label="順位" style="font-weight:700;<?= $r['rank'] <= 3 ? 'color:var(--kin);' : '' ?>"><?= $r['rank'] ?>位</td>
<?php if (in_array((int)$r['classroom_id'], $allowedClassroomIds, true)): ?>
        <td data-label="生徒"><a class="sname" href="<?= h(qtab(['view' => null, 'cids' => null, 'ev' => null, 'unit' => null, 'grade' => null, 'school' => null, 'student_id' => $r['student_id']])) ?>"><?= h($r['student_name']) ?></a></td>
<?php else: ?>
        <td data-label="生徒"><?= h($r['student_name']) ?></td>
<?php endif; ?>
        <td data-label="教室"><?= h($r['classroom_name']) ?></td>
        <td data-label="学年"><?= h(grade_label($r['grade'])) ?></td>
        <td class="num" data-label="ベスト" style="font-weight:700;"><?= h(fmt_time_ms((int)$r['best_ms'])) ?></td>
        <td class="num" data-label="回数"><?= (int)$r['plays'] ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    </div>
<?php endif; ?>
  </div>
<?php endif; ?>

<?php else: ?>
  <!-- ============ 生徒一覧 ============ -->
<?php
    // 台帳(units.php)に載っている単元から教科タブを作る
    $ledgerSubjects = array_values(array_unique(array_map('subject_of', array_keys($unitMeta))));
    sort($ledgerSubjects);
?>
  <div class="bar-row sp-hide">
<?php foreach ($periodLabels as $key => $label): ?>
    <a class="ptab<?= $period === $key ? ' active' : '' ?>" href="<?= h(qtab(['period' => $key])) ?>"><?= h($label) ?></a>
<?php endforeach; ?>
<?php if (count($ledgerSubjects) > 1): ?>
    <a class="ptab stab<?= $filterSubject === '' ? ' active' : '' ?>" href="<?= h(qtab(['subject' => null])) ?>">全教科</a>
<?php foreach ($ledgerSubjects as $sj): ?>
    <a class="ptab stab<?= $filterSubject === $sj ? ' active' : '' ?>" href="<?= h(qtab(['subject' => $sj])) ?>"><?= h(subject_label($sj)) ?></a>
<?php endforeach; ?>
<?php endif; ?>
    <a class="ptab" style="border-color:var(--kin);color:var(--kin);" href="<?= h(qtab(['view' => 'ranking'])) ?>">ランキング</a>
    <a class="ptab<?= $showTest ? ' active' : '' ?>" href="<?= h(qtab(['showtest' => $showTest ? null : '1'])) ?>"><?= $showTest ? 'テスト生を隠す' : 'テスト生を表示' ?></a>
  </div>

<?php if (count($classrooms) > 1): ?>
  <div class="bar-row sp-hide">
    <a class="ptab<?= empty($_GET['classroom_id']) ? ' active' : '' ?>" href="<?= h(qtab(['classroom_id' => null])) ?>">全教室</a>
<?php foreach ($classrooms as $c): ?>
    <a class="ptab<?= (int)($_GET['classroom_id'] ?? 0) === (int)$c['classroom_id'] ? ' active' : '' ?>"
       href="<?= h(qtab(['classroom_id' => $c['classroom_id']])) ?>"><?= h($c['classroom_name']) ?></a>
<?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (count($gradeOptions) > 1): ?>
  <div class="bar-row sp-hide">
    <span style="font-size:12px;color:var(--ink-soft);font-weight:700;align-self:center;">学年</span>
    <a class="ptab<?= $filterGrade === '' ? ' active' : '' ?>" href="<?= h(qtab(['grade' => null])) ?>">全学年</a>
<?php foreach ($gradeOptions as $g): ?>
    <a class="ptab<?= $filterGrade === $g ? ' active' : '' ?>" href="<?= h(qtab(['grade' => $g])) ?>"><?= h(grade_label($g)) ?></a>
<?php endforeach; ?>
  </div>
<?php endif; ?>

  <!-- スマホ用: フィルタは全部ドロップダウンにまとめる -->
  <div class="bar-row sp-only row">
<?php
    $spOpt = [];
    foreach ($periodLabels as $key => $label) $spOpt[] = [$label, qtab(['period' => $key]), $period === $key];
    echo sp_select('期間', $spOpt);

    if (count($ledgerSubjects) > 1) {
        $spOpt = [['全教科', qtab(['subject' => null]), $filterSubject === '']];
        foreach ($ledgerSubjects as $sj) $spOpt[] = [subject_label($sj), qtab(['subject' => $sj]), $filterSubject === $sj];
        echo sp_select('教科', $spOpt);
    }
    if (count($classrooms) > 1) {
        $curCid = (int)($_GET['classroom_id'] ?? 0);
        $spOpt = [['全教室', qtab(['classroom_id' => null]), empty($_GET['classroom_id'])]];
        foreach ($classrooms as $c) $spOpt[] = [$c['classroom_name'], qtab(['classroom_id' => $c['classroom_id']]), $curCid === (int)$c['classroom_id']];
        echo sp_select('教室', $spOpt);
    }
    if (count($gradeOptions) > 1) {
        $spOpt = [['全学年', qtab(['grade' => null]), $filterGrade === '']];
        foreach ($gradeOptions as $g) $spOpt[] = [grade_label($g), qtab(['grade' => $g]), $filterGrade === $g];
        echo sp_select('学年', $spOpt);
    }
?>
    <a class="ptab" style="border-color:var(--kin);color:var(--kin);" href="<?= h(qtab(['view' => 'ranking'])) ?>">ランキング</a>
    <a class="ptab<?= $showTest ? ' active' : '' ?>" href="<?= h(qtab(['showtest' => $showTest ? null : '1'])) ?>"><?= $showTest ? 'テスト生を隠す' : 'テスト生を表示' ?></a>
  </div>

  <div class="card">
    <h1>生徒一覧 <span style="font-size:12px;color:var(--ink-soft);font-weight:500;">（<?= h($periodLabels[$period]) ?><?= $filterSubject !== '' ? '・' . h(subject_label($filterSubject)) : '' ?><?= $filterGrade !== '' ? '・' . h(grade_label($filterGrade)) : '' ?>の学習状況）</span></h1>
<?php if (count($students) === 0): ?>
    <p style="font-size:13px;color:var(--ink-soft);margin-top:8px;">表示できる生徒がいません</p>
<?php else: ?>
    <p class="sort-hint">列の見出し（生徒コード・教室・氏名など）をクリックすると、その項目で並び替えできます（もう一度クリックで昇順⇄降順、▲▼が今の並び順）。</p>
    <!-- BUILD-MARKER: sort-grade-v5 2026-07-15 (全角数字対応) -->
    <div class="scroll">
    <table id="students-table" class="sortable" data-build="sort-grade-v5">
      <colgroup>
        <col style="width:132px"><col style="width:78px"><col style="width:84px"><col style="width:60px">
        <col style="width:78px"><col style="width:66px"><col style="width:74px"><col style="width:96px"><col style="width:112px">
      </colgroup>
      <thead>
      <tr><th data-sort="text">生徒</th><th data-sort="num">コード</th><th data-sort="text">教室</th><th data-sort="grade">学年</th>
        <th class="num" data-sort="num">学習時間</th><th class="num" data-sort="num">解答数</th><th class="num" data-sort="num">正答率</th>
        <th class="num" data-sort="num">解き直し残数</th><th data-sort="text">最終学習</th></tr>
      </thead>
      <tbody>
<?php foreach ($students as $s):
    $solved = (int)$s['solved'];
    $rate = $solved > 0 ? (int)round(100 * (int)$s['correct'] / $solved) : null;
?>
      <tr>
        <td class="c-name" data-val="<?= h($s['student_name']) ?>"><a class="sname" href="<?= h(qtab(['student_id' => $s['student_id']])) ?>"><?= h($s['student_name']) ?></a></td>
        <td class="c-code" data-label="コード" data-val="<?= h($s['login_id']) ?>"><?= h($s['login_id']) ?></td>
        <td data-label="教室" data-val="<?= h($s['classroom_name']) ?>"><?= h($s['classroom_name']) ?></td>
        <td data-label="学年" data-val="<?= grade_sort_key($s['grade']) ?>"><?= h(grade_label($s['grade'])) ?></td>
        <td class="num" data-label="学習時間" data-val="<?= (int)$s['sec'] ?>"><?= floor((int)$s['sec'] / 60) ?>分</td>
        <td class="num" data-label="解答数" data-val="<?= $solved ?>"><?= $solved ?></td>
        <td class="num <?= $rate !== null && $rate < 60 ? 'lowrate' : ($rate !== null && $rate >= 90 ? 'okrate' : '') ?>" data-label="正答率" data-val="<?= $rate !== null ? $rate : -1 ?>">
          <?= $rate !== null ? $rate . '%' : '-' ?></td>
        <td class="num" data-label="解き直し残数" data-val="<?= (int)$s['retries'] ?>"><?= (int)$s['retries'] ?></td>
        <td class="c-last" style="white-space:nowrap;" data-label="最終学習" data-val="<?= $s['last_at'] ? h($s['last_at']) : '' ?>"><?= $s['last_at'] ? h(substr($s['last_at'], 0, 16)) : '-' ?></td>
      </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <!-- スマホ用: 「表示する数字」で並び替え、順位/学年/氏名/数字の1行リストをJSで生成 -->
    <div class="sp-only" id="sp-students">
      <div class="sp-metric-bar">
        <label>ならびかえ
          <select class="sp-sel" id="sp-metric-sel">
            <option value="grade">学年</option>
            <option value="code">生徒コード</option>
          </select>
        </label>
        <span class="sp-taphint">名前をタップで詳細へ</span>
      </div>
      <ol id="sp-student-list" class="sp-list"></ol>
    </div>
<?php endif; ?>
  </div>
<?php endif; ?>

  <footer>中京個別指導学院 講師ページ</footer>
</div>
<script>
document.getElementById('logout-btn').addEventListener('click', async () => {
  await fetch('/api/logout.php', { method: 'POST', credentials: 'same-origin' });
  location.reload();
});
// ===== 生徒一覧の列ソート（見出しクリックで昇順⇄降順） =====
(function () {
  var table = document.getElementById('students-table');
  if (!table) return;
  var tbody = table.tBodies[0];
  var headers = table.querySelectorAll('thead th[data-sort]');
  headers.forEach(function (th, col) {
    th.addEventListener('click', function () {
      var type = th.getAttribute('data-sort');
      // 今の並び順を反転。他列の矢印は消す
      var asc = !th.classList.contains('sort-asc');
      headers.forEach(function (h) { h.classList.remove('sort-asc', 'sort-desc'); });
      th.classList.add(asc ? 'sort-asc' : 'sort-desc');
      var rows = Array.prototype.slice.call(tbody.rows);
      rows.sort(function (a, b) {
        if (type === 'grade') {
          // PHP側のdata-valに頼らず、表示ラベル（小1/中2/高3）から順位を作る。
          // 小=100, 中=200, 高=300 + 学年数字 → 小1〜高3が正しい順に並ぶ
          var ag = gradeKey(a.cells[col].textContent), bg = gradeKey(b.cells[col].textContent);
          return asc ? ag - bg : bg - ag;
        }
        var av = cellVal(a.cells[col]), bv = cellVal(b.cells[col]);
        if (type === 'num') {
          var an = parseFloat(av), bn = parseFloat(bv);
          if (isNaN(an)) an = -Infinity;
          if (isNaN(bn)) bn = -Infinity;
          return asc ? an - bn : bn - an;
        }
        return asc ? av.localeCompare(bv, 'ja') : bv.localeCompare(av, 'ja');
      });
      rows.forEach(function (r) { tbody.appendChild(r); });
    });
  });
  function cellVal(cell) {
    var v = cell.getAttribute('data-val');
    return v !== null ? v : cell.textContent.trim();
  }
  function gradeKey(text) {
    // 全角数字（中２など）を半角へ正規化してから判定する
    var t = (text || '').replace(/\s/g, '')
      .replace(/[０-９]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0xFEE0); });
    var m = t.match(/(小|中|高)\s*(\d+)/);
    if (m) return { '小': 100, '中': 200, '高': 300 }[m[1]] + parseInt(m[2], 10);
    var n = t.match(/(\d+)/);
    return n ? parseInt(n[1], 10) : 0;
  }
})();
// ===== 数式整形の共通処理 =====
// (1) 全体がLaTeXのセル、(2) 日本語に Unicode の √ / ² ・分数F(a/b) が混じった文
//     （正誤問題など）の両方をKaTeXで整形する。混在文はクイズ本体
//     (math_js3_heihokonmaster.html) と同じ規則で数式トークンを LaTeX 化して描画する。
function _mescape(t){ return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function _texWhole(src){ try { return katex.renderToString(src, { throwOnError: true, displayMode: false }); } catch (e) { return _mescape(src); } }
// KaTeXでLaTeX片を描画。未読込・失敗時は fallback（なければ生LaTeX）にする。
function _K(latex, fallback){
  try { if (typeof katex === 'undefined') throw 0;
    return katex.renderToString(latex, { throwOnError: false, displayMode: false }); }
  catch (e) { return _mescape(fallback != null ? fallback : latex); }
}
// 数式トークン → LaTeX（クイズ本体 toLatex と同じ変換規則）
function _toLatex(token){
  var m = token.match(/^\([-－]√([\d.]+)\)²$/);   if (m) return '(-\\sqrt{' + m[1] + '})^2';
  m = token.match(/^\(√([\d.]+)\)²$/);            if (m) return '(\\sqrt{' + m[1] + '})^2';
  m = token.match(/^±√([\d.]+)$/);                 if (m) return '\\pm\\sqrt{' + m[1] + '}';
  m = token.match(/^(\d+)√([\d.]+)$/);             if (m) return m[1] + '\\sqrt{' + m[2] + '}';
  m = token.match(/^[-－]√([\d.]+)$/);             if (m) return '-\\sqrt{' + m[1] + '}';
  m = token.match(/^√\((\d+)\/(\d+)\)$/);          if (m) return '\\sqrt{\\dfrac{' + m[1] + '}{' + m[2] + '}}';
  m = token.match(/^F\((\d+)\/(\d+)\)$/);          if (m) return '\\dfrac{' + m[1] + '}{' + m[2] + '}';
  m = token.match(/^[-－]√\(\(-(\d+)\)²\)$/);      if (m) return '-\\sqrt{(-' + m[1] + ')^2}';
  m = token.match(/^√\(\(-(\d+)\)²\)$/);           if (m) return '\\sqrt{(-' + m[1] + ')^2}';
  m = token.match(/^√([\d.]+)$/);                  if (m) return '\\sqrt{' + m[1] + '}';
  return token;
}
// 地の文（数式トークン以外）だけをエスケープ＋整形。改行→<br> はここだけで行い、
// KaTeX出力（SVGパスに改行を含む）は絶対に触らない。
function _plain(t){ return _mescape(t).replace(/(?<!\d)-([\d])/g, '－$1').replace(/\n/g, '<br>'); }
// 日本語文中の数式トークンだけをKaTeX描画し、地の文はエスケープして返す
function _renderMath(str){
  var re = /[-－]√\(\(-\d+\)²\)|√\(\(-\d+\)²\)|\([-－]√[\d.]+\)²|\(√[\d.]+\)²|√\(\d+\/\d+\)|±√[\d.]+|\d+√[\d.]+|[-－]√[\d.]+|√[\d.]+|F\(\d+\/\d+\)/g;
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
  if (/[\\^_{}]/.test(src)) return _texWhole(src);   // 既にLaTeX（生徒の答えの ^2 など）
  if (/√/.test(src) || /F\(\d+\/\d+\)/.test(src)) return _renderMath(src);   // √・分数トークン混じり文（正誤問題・平方根）
  // 上付き ² ³ を KaTeX の ^2 ^3 に正規化してから判定する。ツールは問題文・正解を
  // Unicode上付き（x²）で、生徒の答えを normalizeInput 後（x^2 など）で保存するため、
  // 同じ数式でも列ごとに立体／斜体が割れていた。純粋な数式は3列とも KaTeX に寄せて統一する。
  var s = src.replace(/²/g, '^2').replace(/³/g, '^3');
  if (/[0-9A-Za-z]/.test(s) && /^[\s0-9A-Za-z+\-*/=(),.^]+$/.test(s)) return _texWhole(s);
  if (/[²³]/.test(src)) return _renderMath(src);   // 日本語混じりで上付きを含む文は従来のトークン描画
  return _mescape(src).replace(/\n/g, '<br>');
}
document.querySelectorAll('.math').forEach(function (el) {
  el.innerHTML = renderMathToHTML(el.getAttribute('data-math') || '');
});

// ===== 解き直しプリント（誤答をアナログで解き直す用紙）=====
(function () {
  var btn = document.getElementById('print-wrongs-btn');
  var dataEl = document.getElementById('print-wrongs-data');
  if (!btn || !dataEl) return;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  // 全体LaTeX / Unicode√混じり日本語文 のどちらもKaTeX整形（共通処理に委譲）
  function fmt(src) {
    return renderMathToHTML(src);
  }

  btn.addEventListener('click', function () {
    var data;
    try { data = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
    var items = data.items || [];
    // 種類(モード)で絞り込み中なら、その種類だけ印刷する
    var modeFilter = window.__wrongModeFilter || '';
    if (modeFilter) items = items.filter(function (it) { return it.label === modeFilter; });
    if (!items.length) { alert('印刷できる誤答がありません'); return; }

    var PER_PAGE = 5;   // 1枚あたりの問題数（A4に確実に収まる数。増やすと溢れて空白ページが出る）
    var pages = [];
    for (var i = 0; i < items.length; i += PER_PAGE) pages.push(items.slice(i, i + PER_PAGE));

    var n = 0;
    var body = pages.map(function (page) {
      var qs = page.map(function (it) {
        n++;
        return '<div class="q">'
          + '<div class="q-head"><span class="q-no">' + n + '</span>'
          + '<span class="q-meta">' + esc(it.unit) + '　<span class="q-tag">' + esc(it.label) + '</span></span></div>'
          + '<div class="q-body">' + fmt(it.q) + '</div>'
          + '<div class="q-space"></div>'
          + '</div>';
      }).join('');
      return '<div class="page"><div class="sheet-head">'
        + '<div><div class="sh-title">解き直しプリント</div>'
        + '<div class="sh-sub">' + esc(data.period || '') + '</div></div>'
        + '<div class="sh-name"><span class="sh-label">なまえ</span>' + esc(data.student) + '</div>'
        + '</div>' + qs + '</div>';
    }).join('');

    // 講師用の解答（別紙・最後のページ）
    var m = 0;
    var keyRows = items.map(function (it) {
      m++;
      return '<tr><td class="k-no">' + m + '</td>'
        + '<td>' + fmt(it.q) + '</td>'
        + '<td class="k-ans">' + fmt(it.a) + '</td>'
        + '<td class="k-wa">' + fmt(it.sa) + '</td></tr>';
    }).join('');
    var keyPage = '<div class="page key-page"><div class="sheet-head">'
      + '<div><div class="sh-title">解答（講師用）</div>'
      + '<div class="sh-sub">' + esc(data.student) + '　' + esc(data.meta || '') + '</div></div></div>'
      + '<table class="key"><tr><th>No.</th><th>問題</th><th>正解</th><th>前回の誤答</th></tr>'
      + keyRows + '</table></div>';

    var html = '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">'
      + '<title>解き直しプリント ' + esc(data.student) + '</title>'
      + '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">'
      + '<style>'
      + '@page{size:A4;margin:14mm 14mm 12mm;}'
      + '*{box-sizing:border-box;}'
      + 'body{font-family:"Zen Kaku Gothic New",system-ui,sans-serif;color:#222;margin:0;}'
      /* 各ページの箱をA4印刷領域ぶんの高さに揃える。透かしロゴが毎ページ同じ位置に来る（高さがバラつくと上下にずれる） */
      + '.page{page-break-after:always;min-height:260mm;}.page:last-child{page-break-after:auto;}'
      + '.sheet-head{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid #C73E2E;padding-bottom:6px;margin-bottom:14px;}'
      + '.sh-title{font-size:20px;font-weight:700;}'
      + '.sh-sub{font-size:12px;color:#777;margin-top:2px;}'
      + '.sh-name{font-size:13px;color:#555;}'
      + '.sh-label{display:inline-block;border:1px solid #bbb;border-radius:4px;padding:1px 8px;margin-right:8px;color:#888;}'
      + '.sh-name{border-bottom:1px solid #999;min-width:150px;text-align:right;padding-bottom:2px;}'
      + '.q{margin-bottom:11px;page-break-inside:avoid;}'
      + '.q-head{display:flex;align-items:baseline;gap:10px;margin-bottom:6px;}'
      + '.q-no{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;background:#C73E2E;color:#fff;border-radius:6px;font-weight:700;font-size:13px;padding:0 4px;}'
      + '.q-meta{font-size:12px;color:#888;}'
      + '.q-tag{background:#F3EFE6;color:#8a7a52;border-radius:4px;padding:1px 6px;font-size:11px;}'
      + '.q-body{font-size:17px;line-height:1.6;margin-left:34px;}'
      + '.q-space{height:2.2cm;margin:5px 0 0 34px;border:1px dashed #cbcbcb;border-radius:8px;}'
      + '.key-page{page-break-before:always;}'
      + '.key{width:100%;border-collapse:collapse;font-size:13px;}'
      + '.key th,.key td{border:1px solid #ccc;padding:6px 8px;text-align:left;vertical-align:top;}'
      + '.key th{background:#f4f4f4;font-size:12px;}'
      + '.k-no{width:32px;text-align:center;color:#888;}'
      + '.k-ans{color:#1f7a3d;font-weight:700;}'
      + '.k-wa{color:#C73E2E;}'
      + '</style></head><body>' + body + keyPage + '</body></html>';

    if (window.ChukyoPrint && ChukyoPrint.inject) html = ChukyoPrint.inject(html, {opacity:0.15});

    var w = window.open('', '_blank');
    if (!w) { alert('ポップアップがブロックされました。印刷を許可してください'); return; }
    w.document.write(html);
    w.document.close();
    // KaTeXのCSS(CDN)読み込み後に印刷。少し待ってからダイアログを出す
    w.focus();
    setTimeout(function () { try { w.print(); } catch (e) {} }, 500);
  });
})();

// ===== 誤答一覧の種類(モード)フィルタ：一覧表示と印刷対象の両方を絞る =====
(function () {
  var wrap = document.getElementById('wrong-mode-filter');
  var table = document.getElementById('wrongs-table');
  if (!wrap || !table) return;
  var btns = wrap.querySelectorAll('button[data-mode]');
  btns.forEach(function (b) {
    b.addEventListener('click', function () {
      var mode = b.dataset.mode || '';
      window.__wrongModeFilter = mode;   // 解き直しプリントもこの値を見る
      btns.forEach(function (o) { o.classList.toggle('active', o === b); });
      table.querySelectorAll('tr[data-mode]').forEach(function (tr) {
        tr.style.display = (!mode || tr.dataset.mode === mode) ? '' : 'none';
      });
    });
  });
})();

// ===== スマホ ランキング: 種類ドロップダウンで選んだ表だけ表示 =====
// PCではCSSのメディアクエリが効かないので rank-active を付けても全表示のまま（無害）。
(function () {
  var sel = document.getElementById('sp-rank-type');
  if (!sel) return;
  var cards = document.querySelectorAll('.card[data-rank]');
  function apply() {
    cards.forEach(function (c) {
      c.classList.toggle('rank-active', c.getAttribute('data-rank') === sel.value);
    });
  }
  sel.addEventListener('change', apply);
  apply();
})();

// ===== スマホ 生徒一覧: 学年 or 生徒コードで並び替えた1行リスト（学年/氏名/コード）を生成 =====
// 数字系（学習時間・解答数など）はランキングで見る前提なので一覧には出さない。
// 元テーブル(#students-table)の各セルの data-val(並び替え用) と表示テキストから作る。
(function () {
  var table = document.getElementById('students-table');
  var list = document.getElementById('sp-student-list');
  var sel = document.getElementById('sp-metric-sel');
  if (!table || !list || !sel || !table.tBodies[0]) return;
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function n(v) { var x = parseFloat(v); return isNaN(x) ? 0 : x; }
  var data = Array.prototype.slice.call(table.tBodies[0].rows).map(function (tr) {
    var c = tr.cells;
    var a = c[0].querySelector('a');
    return {
      name: c[0].textContent.trim(),
      href: a ? a.getAttribute('href') : null,
      grade: c[3].textContent.trim(),
      gradeKey: n(c[3].getAttribute('data-val')),   // 小1〜高3の並び順キー
      code: c[1].textContent.trim(),
      codeNum: n(c[1].getAttribute('data-val')),     // 6桁の生徒コード
      solved: c[5].textContent.trim()                // 解いた問題数（表示のみ）
    };
  });
  function render() {
    var byCode = sel.value === 'code';
    var sorted = data.slice().sort(function (a, b) {
      return byCode ? a.codeNum - b.codeNum
                    : (a.gradeKey - b.gradeKey) || (a.codeNum - b.codeNum);   // 学年昇順、同学年はコード順
    });
    list.innerHTML = sorted.map(function (s) {
      var name = s.href
        ? '<a class="sname" href="' + s.href + '">' + esc(s.name) + '</a>'
        : esc(s.name);
      return '<li>'
        + '<span class="r-code">' + esc(s.code) + '</span>'
        + '<span class="r-grade">' + esc(s.grade) + '</span>'
        + '<span class="r-name">' + name + '</span>'
        + '<span class="r-solved">' + esc(s.solved) + '問</span>'
        + '</li>';
    }).join('');
  }
  sel.addEventListener('change', render);
  render();
})();

// ===== 生徒詳細 足あと: 直近2週間（さらに見るで1か月）+ 日付タップで4カードをその日に切替 =====
(function () {
  var grid = document.getElementById('foot-grid');
  var dataEl = document.getElementById('foot-data');
  var moreBtn = document.getElementById('foot-more');
  var scopeEl = document.getElementById('foot-scope');
  if (!grid || !dataEl) return;
  var data;
  try { data = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
  var daily = data.daily || {};
  var today = data.today;
  if (!today) return;
  var periodLabel = data.period || '';

  // 4カードの初期HTML（＝期間のまとめ）。日付選択を解除したら戻す。
  var cards = {
    min: document.getElementById('stat-min'),
    solved: document.getElementById('stat-solved'),
    rate: document.getElementById('stat-rate'),
    redo: document.getElementById('stat-redo')
  };
  var defHTML = {};
  Object.keys(cards).forEach(function (k) { if (cards[k]) defHTML[k] = cards[k].innerHTML; });

  var WD = ['日', '月', '火', '水', '木', '金', '土'];
  function pad(x) { return x < 10 ? '0' + x : '' + x; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function dateList(n) {                       // 左上=今日、そこから過去へ（新しい→古い）
    var base = new Date(today + 'T00:00:00');  // ローカル時刻で解釈＝日付ずれを防ぐ
    var arr = [];
    for (var i = 0; i < n; i++) {
      var d = new Date(base.getTime());
      d.setDate(d.getDate() - i);
      arr.push(d);
    }
    return arr;
  }
  function level(s) { return s <= 0 ? 0 : (s < 5 ? 1 : (s < 15 ? 2 : (s < 40 ? 3 : 4))); }

  var expanded = false, selKey = null;

  function applyScope() {
    if (selKey) {
      var d = new Date(selKey + 'T00:00:00');
      var r = daily[selKey] || { min: 0, solved: 0, correct: 0, redo: 0 };
      var rate = r.solved > 0 ? Math.round(100 * r.correct / r.solved) : 0;
      if (cards.min) cards.min.innerHTML = r.min + '<small>分</small>';
      if (cards.solved) cards.solved.innerHTML = r.solved + '<small>問</small>';
      if (cards.rate) cards.rate.innerHTML = rate + '<small>%</small>';
      if (cards.redo) cards.redo.innerHTML = r.redo > 0 ? (r.redo + '<small>問</small>') : '—';
      if (scopeEl) { scopeEl.textContent = (d.getMonth() + 1) + '/' + d.getDate() + '（' + WD[d.getDay()] + '）の記録'; scopeEl.classList.add('on'); }
    } else {
      Object.keys(cards).forEach(function (k) { if (cards[k]) cards[k].innerHTML = defHTML[k]; });
      if (scopeEl) { scopeEl.textContent = periodLabel + 'のまとめ'; scopeEl.classList.remove('on'); }
    }
  }

  function render() {
    grid.innerHTML = '';
    dateList(expanded ? 35 : 14).forEach(function (d) {
      var key = ymd(d);
      var r = daily[key] || { min: 0, solved: 0 };
      var wd = d.getDay(), lv = level(r.solved || 0);
      var mn = r.min || 0, sv = r.solved || 0;
      var cell = document.createElement('button');
      cell.type = 'button';
      cell.className = 'foot-cell' + (key === today ? ' today' : '') + (key === selKey ? ' sel' : '');
      cell.setAttribute('data-key', key);
      cell.innerHTML = '<div class="fd' + (wd === 0 ? ' sun' : (wd === 6 ? ' sat' : '')) + '">'
        + (d.getMonth() + 1) + '/' + d.getDate() + '</div>'
        + '<div class="sq' + (lv ? ' l' + lv : '') + '"></div>'
        + '<div class="fm' + (mn > 0 ? '' : ' z') + '"><b>' + mn + '</b>分</div>'
        + '<div class="fs' + (sv > 0 ? '' : ' z') + '"><b>' + sv + '</b>問</div>';
      cell.addEventListener('click', function () {
        selKey = (selKey === key) ? null : key;
        applyScope();
        grid.querySelectorAll('.foot-cell').forEach(function (c) {
          c.classList.toggle('sel', c.getAttribute('data-key') === selKey);
        });
      });
      grid.appendChild(cell);
    });
  }

  if (moreBtn) {
    moreBtn.addEventListener('click', function () {
      expanded = !expanded;
      moreBtn.textContent = expanded ? 'とじる ▲' : 'さらに見る ▼';
      moreBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      render();
    });
  }
  render();
})();
</script>
</body>
</html>
