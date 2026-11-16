<?php
declare(strict_types=1);

// 過去問の進捗（講師が見る画面。読むだけ）。私立・公立（愛知県公立高校入試）の両方。
//   一覧（既定）: 担当教室の生徒全体。上のタブで「私立」「公立」を切りかえる（?kind=public）。
//     私立: 全体の進み具合・購入した過去問・記録の回数・平均得点率・最高周目・最後に記録した日
//     公立: 全体の進み具合・記録の回数・平均点（22点満点）と科目ごとの平均点・最高周目・最後に記録した日
//     列見出しを押すと並べ替え。
//   個人（?student_id=）: 私立は高校ごとの進捗表（年度×科目に周目と得点率）、公立は年度（A/B）×科目
//     ＋5科の合計の表。どちらも1回ずつの記録（得点・できたところ・できなかったところ）つき。
// 一覧に出す生徒: 在籍（is_active=1）で、中3 か、私立・公立のどちらかに何か入力がある生徒。
//   テスト生（氏名に「テスト」）は既定で出さない（?showtest=1 で出す。teacher.php と同じ方針）。
// 権限: super_admin=全教室 / それ以外=担当教室のみ（teacher.php と同じ）。
// 生徒の入力は /kakomon.php、定義は api/kakomon_common.php。年度は西暦だけで出す。
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/api/kakomon_common.php';

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

// 学年の並べ替え用（teacher.php の grade_sort_key と同じ）
function grade_sort_key(?string $grade): int
{
    if ($grade === null || $grade === '') return 0;
    $grade = strtr($grade, ['０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9']);
    if (preg_match('/(es|小)\s*(\d)/u', $grade, $m)) return 100 + (int)$m[2];
    if (preg_match('/(js|中)\s*(\d)/u', $grade, $m)) return 200 + (int)$m[2];
    if (preg_match('/(hs|高)\s*(\d)/u', $grade, $m)) return 300 + (int)$m[2];
    if (preg_match('/(\d+)/', $grade, $m)) return (int)$m[1];
    return 0;
}

const CIRCLED = [1 => '①', 2 => '②', 3 => '③', 4 => '④', 5 => '⑤', 6 => '⑥'];

$actor = current_actor();

// ---- 未ログイン時: 講師ログインフォーム（report.php と同じ） ----
if (!$actor || $actor['type'] !== 'teacher') {
    ?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>過去問の進捗 | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root{--paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;--ai:#2C5F8A;--white:#fff;
    --radius:14px;--shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06)}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6}
  .box{max-width:360px;margin:80px auto;background:var(--white);border-radius:var(--radius);
    box-shadow:var(--shadow);border-top:4px solid var(--ai);padding:28px}
  h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:18px;color:var(--ai)}
  p.sub{font-size:12px;color:var(--ink-soft);margin-top:2px}
  label{display:block;font-size:12px;font-weight:700;margin-top:14px}
  input{width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:16px;margin-top:4px}
  button{margin-top:18px;width:100%;background:var(--ai);color:#fff;border:none;border-radius:8px;
    padding:11px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Zen Maru Gothic',sans-serif}
  .err{color:#c0392b;font-size:12px;margin-top:8px;min-height:16px}
</style>
</head>
<body>
<div class="box">
  <h1>過去問の進捗</h1>
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

$teacherId = (int)$actor['id'];
$pdo = db();

$stmt = $pdo->prepare('SELECT role, teacher_name, must_change_password FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $teacherId]);
$me = $stmt->fetch();
if (!$me) { header('Location: /teacher.php'); exit; }
$role = (string)$me['role'];

// 初期パスワードのままなら、変更するまで先に進ませない（teacher.php / report.php と同じ）
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
$allowedIds = array_map(fn($c) => (int)$c['classroom_id'], $classrooms);

$ready = kakomon_ready($pdo);
$pubReady = kakomon_public_ready($pdo);
$showTest = isset($_GET['showtest']);
$kind = ((string)($_GET['kind'] ?? '')) === 'public' ? 'public' : 'private';
$cid = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;
if (!in_array($cid, $allowedIds, true)) {
    $cid = 0;
}
$detailId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

// いまの一覧の条件（私立/公立・教室・テスト生）。リンクはこれを保ったまま一部だけ変える
$listBase = array_filter([
    'kind'     => $kind === 'public' ? 'public' : null,
    'cid'      => $cid > 0 ? $cid : null,
    'showtest' => $showTest ? '1' : null,
], fn($v) => $v !== null);

function list_url(array $base, array $change = []): string
{
    $q = array_filter(array_merge($base, $change), fn($v) => $v !== null);
    return '/kakomon_teacher.php' . ($q ? '?' . http_build_query($q) : '');
}

// 得点率の表示（色分けつき）
function rate_html(?float $rate): string
{
    if ($rate === null) return '<span class="rate na">—</span>';
    return '<span class="rate ' . kakomon_rate_class($rate) . '">' . (int)round($rate) . '%</span>';
}

// 公立の点数（22点満点）の表示。平均は小数1けた
function pscore_html(?float $score): string
{
    if ($score === null) return '<span class="rate na">—</span>';
    $txt = abs($score - round($score)) < 0.05 ? (string)(int)round($score) : number_format($score, 1);
    return '<span class="rate ' . kakomon_rate_class(100 * $score / KAKOMON_PUBLIC_MAX) . '">' . $txt . '</span>';
}

function pace_html(?string $pace, ?string $at): string
{
    if ($pace === null || !isset(KAKOMON_PACES[$pace])) {
        return '<span class="pace none">未回答</span>';
    }
    return '<span class="pace ' . h($pace) . '">' . h(KAKOMON_PACES[$pace]) . '</span>'
        . ($at ? '<small class="pace-at">' . h(kakomon_date_label($at)) . '</small>' : '');
}

// ============================================================
// 個人
// ============================================================
$detail = null;
$dState = null;
if ($ready && $detailId > 0) {
    if (!kakomon_teacher_can_view($pdo, $teacherId, $detailId)) {
        http_response_code(403);
        $detailId = -1;   // 見られない生徒（下で「表示できません」を出す）
    } else {
        $stmt = $pdo->prepare(
            'SELECT s.student_id, s.student_name, s.login_id, s.grade, c.classroom_name
             FROM students s JOIN classrooms c ON c.classroom_id = s.classroom_id
             WHERE s.student_id = :id'
        );
        $stmt->execute(['id' => $detailId]);
        $detail = $stmt->fetch() ?: null;
        if ($detail) {
            $dState = kakomon_state($pdo, $detailId);
            $stmt = $pdo->prepare('SELECT updated_at FROM kakomon_pace WHERE student_id = :id');
            $stmt->execute(['id' => $detailId]);
            $detail['pace_at'] = $stmt->fetchColumn() ?: null;
            $detail['pub_pace_at'] = null;
            if ($pubReady) {
                $stmt = $pdo->prepare('SELECT updated_at FROM kakomon_public_pace WHERE student_id = :id');
                $stmt->execute(['id' => $detailId]);
                $detail['pub_pace_at'] = $stmt->fetchColumn() ?: null;
            }
        }
    }
}

// ============================================================
// 一覧（私立・公立の両方を集計し、表示はタブで切りかえる）
// ============================================================
$rows = [];
$paceCount = ['fast' => 0, 'normal' => 0, 'slow' => 0, 'none' => 0];
$withLogs = 0;
if ($ready && $detailId === 0 && $allowedIds) {
    $ids = $cid > 0 ? [$cid] : $allowedIds;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $testCond = $showTest ? '' : " AND s.student_name NOT LIKE '%テスト%'";
    $pubPaceCol = $pubReady ? 'kpp.pace AS pub_pace, kpp.updated_at AS pub_pace_at' : 'NULL AS pub_pace, NULL AS pub_pace_at';
    $pubPaceJoin = $pubReady ? 'LEFT JOIN kakomon_public_pace kpp ON kpp.student_id = s.student_id' : '';

    $stmt = $pdo->prepare(
        "SELECT s.student_id, s.student_name, s.grade, s.classroom_id, c.classroom_name,
                kp.pace, kp.updated_at AS pace_at, {$pubPaceCol}
         FROM students s
         JOIN classrooms c ON c.classroom_id = s.classroom_id
         LEFT JOIN kakomon_pace kp ON kp.student_id = s.student_id
         {$pubPaceJoin}
         WHERE s.is_active = 1 AND s.classroom_id IN ($in){$testCond}"
    );
    $stmt->execute($ids);
    $students = $stmt->fetchAll();

    // 私立: 記録の集計（生徒ごと）
    $stmt = $pdo->prepare(
        "SELECT kl.student_id, COUNT(*) AS n, AVG(kl.rate) AS avg_rate, MAX(kl.created_at) AS last_at,
                MAX(kl.lap) AS max_lap
         FROM kakomon_logs kl JOIN students s ON s.student_id = kl.student_id
         WHERE s.classroom_id IN ($in)
         GROUP BY kl.student_id"
    );
    $stmt->execute($ids);
    $agg = [];
    foreach ($stmt->fetchAll() as $r) {
        $agg[(int)$r['student_id']] = $r;
    }

    // 私立: 購入した過去問（高校）。名前はマスター優先
    $stmt = $pdo->prepare(
        "SELECT ks.kschool_id, ks.student_id, COALESCE(ts.name, ks.school_name) AS name,
                (SELECT COUNT(*) FROM kakomon_logs kl WHERE kl.kschool_id = ks.kschool_id) AS n
         FROM kakomon_schools ks
         JOIN students s ON s.student_id = ks.student_id
         LEFT JOIN target_schools ts ON ts.target_school_id = ks.target_school_id
         WHERE s.classroom_id IN ($in)
         ORDER BY ks.kschool_id"
    );
    $stmt->execute($ids);
    $schoolsBy = [];
    foreach ($stmt->fetchAll() as $r) {
        $schoolsBy[(int)$r['student_id']][] = ['name' => (string)$r['name'], 'n' => (int)$r['n']];
    }

    // 公立: 記録の集計（生徒ごと。科目ごとの平均点も）。科目キーはコード内の定数だけ
    $pagg = [];
    if ($pubReady) {
        $subjCols = '';
        foreach (array_keys(KAKOMON_SUBJECTS) as $k) {
            $subjCols .= ", AVG(CASE WHEN kpl.subject = '{$k}' THEN kpl.score END) AS a_{$k}";
        }
        $stmt = $pdo->prepare(
            "SELECT kpl.student_id, COUNT(*) AS n, AVG(kpl.score) AS avg_score, MAX(kpl.created_at) AS last_at,
                    MAX(kpl.lap) AS max_lap{$subjCols}
             FROM kakomon_public_logs kpl JOIN students s ON s.student_id = kpl.student_id
             WHERE s.classroom_id IN ($in)
             GROUP BY kpl.student_id"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $r) {
            $pagg[(int)$r['student_id']] = $r;
        }
    }

    // 公立: 受ける高校。名前はマスター優先
    $pubSchoolsBy = [];
    if ($pubReady) {
        $stmt = $pdo->prepare(
            "SELECT ps.student_id, COALESCE(ts.name, ps.school_name) AS name
             FROM kakomon_public_schools ps
             JOIN students s ON s.student_id = ps.student_id
             LEFT JOIN target_schools ts ON ts.target_school_id = ps.target_school_id
             WHERE s.classroom_id IN ($in)
             ORDER BY ps.pschool_id"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $r) {
            $pubSchoolsBy[(int)$r['student_id']][] = (string)$r['name'];
        }
    }

    foreach ($students as $s) {
        $sid = (int)$s['student_id'];
        $a = $agg[$sid] ?? null;
        $pa = $pagg[$sid] ?? null;
        $sch = $schoolsBy[$sid] ?? [];
        $pace = isset(KAKOMON_PACES[(string)$s['pace']]) ? (string)$s['pace'] : null;
        $pubPace = isset(KAKOMON_PACES[(string)$s['pub_pace']]) ? (string)$s['pub_pace'] : null;
        $pubSch = $pubSchoolsBy[$sid] ?? [];
        // 中3 か、私立・公立のどちらかに何か1つでも入力がある生徒だけ出す
        if (!kakomon_is_js3($s['grade']) && !$a && !$sch && $pace === null && !$pa && $pubPace === null && !$pubSch) {
            continue;
        }
        $subjAvg = [];
        foreach (array_keys(KAKOMON_SUBJECTS) as $k) {
            $subjAvg[$k] = ($pa && $pa['a_' . $k] !== null) ? (float)$pa['a_' . $k] : null;
        }
        $row = [
            'student_id'     => $sid,
            'student_name'   => (string)$s['student_name'],
            'grade'          => $s['grade'],
            'classroom_id'   => (int)$s['classroom_id'],
            'classroom_name' => (string)$s['classroom_name'],
            // 私立
            'pace'           => $pace,
            'pace_at'        => $pace !== null ? (string)$s['pace_at'] : null,
            'schools'        => $sch,
            'n'              => $a ? (int)$a['n'] : 0,
            'avg_rate'       => $a ? (float)$a['avg_rate'] : null,
            'last_at'        => $a ? (string)$a['last_at'] : null,
            'max_lap'        => $a ? (int)$a['max_lap'] : 0,
            // 公立
            'pub_pace'       => $pubPace,
            'pub_pace_at'    => $pubPace !== null ? (string)$s['pub_pace_at'] : null,
            'pub_schools'    => $pubSch,
            'pub_n'          => $pa ? (int)$pa['n'] : 0,
            'pub_avg'        => $pa ? (float)$pa['avg_score'] : null,
            'pub_subj'       => $subjAvg,
            'pub_last_at'    => $pa ? (string)$pa['last_at'] : null,
            'pub_max_lap'    => $pa ? (int)$pa['max_lap'] : 0,
        ];
        $rows[] = $row;
        if ($kind === 'public') {
            $paceCount[$pubPace ?? 'none']++;
            if ($pa) $withLogs++;
        } else {
            $paceCount[$pace ?? 'none']++;
            if ($a) $withLogs++;
        }
    }
    // 既定の並び: 教室 → 学年 → 氏名（列見出しで並べ替えられる）
    usort($rows, function (array $x, array $y): int {
        return [$x['classroom_id'], grade_sort_key($x['grade']), $x['student_name']]
            <=> [$y['classroom_id'], grade_sort_key($y['grade']), $y['student_name']];
    });
}
$multiClass = $cid === 0 && count($classrooms) > 1;

// 私立: 個人ページの進捗表（年度×科目。生徒ページの JS と同じ組み立て）
function board_html(array $logs): string
{
    if (!$logs) {
        return '<p class="empty">まだ記録がありません</p>';
    }
    $years = [];
    $subs = [];
    foreach ($logs as $l) {
        $years[$l['year']] = true;
        $subs[$l['subject']] = true;
    }
    krsort($years);
    $cols = array_values(array_filter(array_keys(KAKOMON_SUBJECTS), fn($k) => isset($subs[$k])));

    $html = '<div class="gridwrap"><table class="grid"><thead><tr><th>年度</th>';
    foreach ($cols as $k) {
        $html .= '<th>' . h(KAKOMON_SUBJECTS[$k]) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach (array_keys($years) as $y) {
        $html .= '<tr><th>' . (int)$y . '</th>';
        foreach ($cols as $k) {
            $cell = array_values(array_filter($logs, fn($l) => $l['year'] === $y && $l['subject'] === $k));
            usort($cell, fn($a, $b) => $a['lap'] <=> $b['lap']);
            $html .= '<td>';
            foreach ($cell as $l) {
                $html .= '<span class="lp r-' . kakomon_rate_class((float)$l['rate']) . '">'
                    . (CIRCLED[$l['lap']] ?? (string)$l['lap']) . (int)$l['rate'] . '%</span>';
            }
            $html .= '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}

// 公立: 個人ページの進捗表（年度・グループ×5科目＋周目ごとの5科合計。生徒ページの JS と同じ組み立て）
function pub_board_html(array $logs): string
{
    if (!$logs) {
        return '<p class="empty">まだ記録がありません</p>';
    }
    $gOrder = ['' => 0, 'A' => 1, 'B' => 2];
    $rowKeys = [];
    foreach ($logs as $l) {
        $rowKeys[$l['year'] . '|' . $l['group']] = ['year' => $l['year'], 'group' => $l['group']];
    }
    $rowKeys = array_values($rowKeys);
    usort($rowKeys, fn($a, $b) => [$b['year'], $gOrder[$a['group']] ?? 9] <=> [$a['year'], $gOrder[$b['group']] ?? 9]);
    $nSubj = count(KAKOMON_SUBJECTS);

    $html = '<div class="gridwrap"><table class="grid"><thead><tr><th>年度</th>';
    foreach (KAKOMON_SUBJECTS as $label) {
        $html .= '<th>' . h($label) . '</th>';
    }
    $html .= '<th class="total">合計<small>／' . (KAKOMON_PUBLIC_MAX * $nSubj) . '</small></th></tr></thead><tbody>';
    foreach ($rowKeys as $rk) {
        $inRow = array_values(array_filter($logs, fn($l) => $l['year'] === $rk['year'] && $l['group'] === $rk['group']));
        $html .= '<tr><th>' . (int)$rk['year']
            . ($rk['group'] !== '' ? '<small>' . h(KAKOMON_PUBLIC_GROUPS[$rk['group']] ?? $rk['group']) . '</small>' : '') . '</th>';
        $laps = [];
        foreach (array_keys(KAKOMON_SUBJECTS) as $k) {
            $cell = array_values(array_filter($inRow, fn($l) => $l['subject'] === $k));
            usort($cell, fn($a, $b) => $a['lap'] <=> $b['lap']);
            $html .= '<td>';
            foreach ($cell as $l) {
                $html .= '<span class="lp r-' . kakomon_rate_class((float)$l['rate']) . '">'
                    . (CIRCLED[$l['lap']] ?? (string)$l['lap']) . (int)$l['score'] . '</span>';
                $laps[$l['lap']]['sum'] = ($laps[$l['lap']]['sum'] ?? 0) + (int)$l['score'];
                $laps[$l['lap']]['n'] = ($laps[$l['lap']]['n'] ?? 0) + 1;
            }
            $html .= '</td>';
        }
        // 周目ごとの5科合計。そろっていない周は何科ぶんかを添える
        ksort($laps);
        $html .= '<td class="total">';
        foreach ($laps as $lap => $t) {
            $html .= '<span class="lp r-' . kakomon_rate_class(100 * $t['sum'] / (KAKOMON_PUBLIC_MAX * $t['n'])) . '">'
                . (CIRCLED[$lap] ?? (string)$lap) . $t['sum']
                . ($t['n'] < $nSubj ? '<small>（' . $t['n'] . '科）</small>' : '') . '</span>';
        }
        $html .= '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}

$title = $detail ? $detail['student_name'] . ' の過去問' : '過去問の進捗';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?> | 中京個別指導学院</title>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root{--paper:#FBFAF6;--grid:#ECE9E0;--ink:#33312B;--ink-soft:#8B877C;--ai:#2C5F8A;--ai-soft:#EDF3F8;
    --shu:#C73E2E;--kin:#C9A227;--dai:#D89A45;--midori:#3E7A5E;--midori-soft:#E9F2EC;--white:#fff;--radius:14px;
    --shadow:0 1px 3px rgba(51,49,43,.08),0 6px 16px rgba(51,49,43,.06)}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Zen Kaku Gothic New',sans-serif;color:var(--ink);background-color:var(--paper);
    background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
    background-size:24px 24px;line-height:1.6;padding:24px 16px 64px}
  .wrap{max-width:1000px;margin:0 auto}

  header.rep{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;
    border-bottom:3px solid var(--ai);padding-bottom:10px;margin-bottom:18px}
  h1{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:22px;color:var(--ai)}
  h1 small{font-size:12px;color:var(--ink-soft);font-weight:700;margin-left:6px}
  .meta{font-size:12px;color:var(--ink-soft);text-align:right;line-height:1.5}
  .nav{font-size:12px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:6px 14px;align-items:center}
  .nav a{color:var(--ai);text-decoration:none;font-weight:700}
  .nav a:hover{text-decoration:underline}
  .btnprint{background:var(--white);border:1px solid #cbd5e1;border-radius:8px;padding:6px 14px;
    font-size:12px;font-weight:700;color:var(--ai);cursor:pointer;font-family:'Zen Maru Gothic',sans-serif}

  /* 私立／公立 */
  .kinds{display:flex;gap:6px;margin-bottom:10px}
  .kinds a{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:14px;padding:6px 22px;border-radius:999px;
    text-decoration:none;color:var(--ai);background:var(--white);border:1.5px solid var(--ai)}
  .kinds a.on{background:var(--ai);color:#fff}
  .tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
  .tabs a{font-size:12px;font-weight:700;padding:5px 12px;border-radius:999px;text-decoration:none;
    color:var(--ai);background:var(--white);border:1px solid #cbd5e1}
  .tabs a.on{background:var(--ai);color:#fff;border-color:var(--ai)}
  .tabs .sp{flex:1}

  section{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
    padding:16px 20px 20px;margin-bottom:18px;border-top:4px solid var(--ai)}
  section.school{border-top-color:var(--kin)}
  section.pub{border-top-color:var(--midori)}
  h2{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:16px;color:var(--ai);
    display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
  section.school h2,section.pub h2{color:var(--ink)}
  h2 .tag{font-size:11px;font-weight:900;padding:1px 10px;border-radius:999px;color:#fff;background:var(--kin)}
  section.pub h2 .tag{background:var(--midori)}
  h2 .sum{font-size:12px;color:var(--ink-soft);font-weight:700;font-family:'Zen Kaku Gothic New',sans-serif}
  p.note{font-size:11px;color:var(--ink-soft);margin-top:6px}
  .empty{font-size:13px;color:var(--ink-soft);padding:10px 0}

  /* 集計の数字 */
  .tiles{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
  .tile{flex:1 1 110px;border:1px solid var(--grid);border-radius:10px;padding:8px 12px;background:var(--paper)}
  .tile .n{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:24px;line-height:1.2;font-feature-settings:'tnum'}
  .tile .n small{font-size:12px;color:var(--ink-soft);margin-left:2px}
  .tile .l{font-size:11px;color:var(--ink-soft)}
  .tile.fast .n{color:var(--midori)}
  .tile.normal .n{color:var(--ai)}
  .tile.slow .n{color:#A9661B}

  /* 一覧の表 */
  .tblwrap{overflow-x:auto;margin-top:12px}
  table.list{width:100%;border-collapse:collapse;font-size:13px}
  table.list th,table.list td{padding:7px 8px;border-bottom:1px solid var(--grid);text-align:left;vertical-align:top}
  table.list th{font-size:11px;color:var(--ink-soft);font-weight:700;border-bottom:2px solid var(--grid);
    white-space:nowrap;cursor:pointer;user-select:none}
  table.list th.num,table.list td.num{text-align:right;white-space:nowrap;font-feature-settings:'tnum'}
  table.list th.sort-asc::after{content:' ▲';font-size:9px}
  table.list th.sort-desc::after{content:' ▼';font-size:9px}
  table.list tbody tr:hover{background:#F7FAFC}
  table.list td.name a{color:var(--ai);font-weight:700;text-decoration:none}
  table.list td.name a:hover{text-decoration:underline}
  table.list th.subj{border-left:1px solid var(--grid)}
  td.nowrap{white-space:nowrap}
  .sch{display:inline-block;font-size:11px;padding:1px 8px;border-radius:999px;background:var(--paper);
    border:1px solid var(--grid);margin:1px 3px 1px 0;white-space:nowrap}
  .sch b{color:var(--ink-soft);font-weight:700;margin-left:3px}
  .dim{color:var(--ink-soft)}

  .pace{display:inline-block;font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:12px;
    padding:1px 10px;border-radius:999px;white-space:nowrap}
  .pace.fast{background:var(--midori-soft);color:var(--midori)}
  .pace.normal{background:var(--ai-soft);color:var(--ai)}
  .pace.slow{background:#FBEBD9;color:#A9661B}
  .pace.none{background:var(--paper);color:#C0BBB0;border:1px dashed var(--grid);font-weight:700}
  .pace-at{display:block;font-size:10px;color:var(--ink-soft);margin-top:1px}
  .rate{font-weight:700}
  .rate.good{color:var(--ai)}
  .rate.mid{color:#8A6D12}
  .rate.low{color:#A9661B}
  .rate.na{color:var(--ink-soft);font-weight:400}

  /* 個人: 進捗表 */
  .gridwrap{overflow-x:auto;margin-top:10px}
  table.grid{border-collapse:collapse;font-size:12px;min-width:320px}
  table.grid th,table.grid td{border:1px solid var(--grid);padding:4px 8px;text-align:center;vertical-align:top}
  table.grid thead th{background:var(--paper);font-family:'Zen Maru Gothic',sans-serif;font-weight:700;color:var(--ink-soft)}
  table.grid tbody th{background:var(--paper);white-space:nowrap;font-family:'Zen Maru Gothic',sans-serif;line-height:1.2}
  table.grid tbody th small{display:block;font-size:9px;color:var(--ink-soft);font-weight:500}
  table.grid th.total,table.grid td.total{background:#FFFCF0;border-left:2px solid #E8DDB5}
  .lp{display:block;font-weight:700;font-feature-settings:'tnum';white-space:nowrap;border-radius:4px;padding:0 4px;margin:1px 0}
  .lp small{font-size:9px;font-weight:500;margin-left:1px}
  table.grid thead th.total small,table.grid td.total .lp small{display:block;font-size:9px;font-weight:500;
    line-height:1.1;margin:0}
  .r-good{color:var(--ai);background:var(--ai-soft)}
  .r-mid{color:#8A6D12;background:#FFF8E1}
  .r-low{color:#A9661B;background:#FBEBD9}

  /* 個人: 1回ずつの記録 */
  table.logs{width:100%;border-collapse:collapse;font-size:12px;margin-top:12px}
  table.logs th,table.logs td{padding:6px 8px;border-bottom:1px solid var(--grid);text-align:left;vertical-align:top}
  table.logs th{font-size:11px;color:var(--ink-soft);border-bottom:2px solid var(--grid);white-space:nowrap}
  table.logs td.txt{white-space:pre-wrap;word-break:break-word;min-width:160px}
  table.logs td.good{color:var(--midori)}
  table.logs td.bad{color:#8A4E10}
  .profile{display:flex;gap:8px 18px;flex-wrap:wrap;align-items:center;margin-top:8px;font-size:13px}
  .profile .k{font-size:11px;color:var(--ink-soft);margin-right:6px}
  .profile .kind{font-family:'Zen Maru Gothic',sans-serif;font-weight:900;font-size:12px;min-width:30px}

  /* スマホ: 記録の表は縦に積む */
  @media (max-width:640px){
    body{padding:16px 10px 48px}
    table.logs thead{display:none}
    table.logs tr{display:block;border-bottom:1px dashed var(--grid);padding:6px 0}
    table.logs td{display:block;border:none;padding:2px 0}
    table.logs td[data-label]::before{content:attr(data-label) ' ';font-size:10px;color:var(--ink-soft);font-weight:700}
    /* 年度・科目・周目は1行に並べる */
    table.logs td.head{display:inline-block;font-weight:700;margin-right:6px}
  }
  @media print{
    body{background:#fff;padding:0;zoom:.85}
    .nav,.kinds,.tabs,.btnprint,.divp-header{display:none!important}
    section{box-shadow:none;border:1px solid var(--grid);break-inside:avoid}
    table.list th{cursor:default}
  }
</style>
</head>
<body>
<div class="wrap">

<?php if (!$ready): ?>
  <div class="nav"><a href="/teacher.php">← 講師ページ</a></div>
  <section>
    <h2>準備ができていません</h2>
    <p class="note" style="font-size:13px;">データベースに過去問の記録用のテーブルがまだありません。
      phpMyAdmin で <b>db/migrations/migrate_kakomon.sql</b> を1回実行してください。</p>
  </section>

<?php elseif ($detailId !== 0): ?>
  <!-- ============ 個人 ============ -->
  <div class="nav">
    <a href="<?= h(list_url($listBase)) ?>">← 一覧へ</a>
<?php if ($detail): ?>
    <a href="/teacher.php?student_id=<?= (int)$detail['student_id'] ?>">この生徒の学習記録 →</a>
    <button class="btnprint" type="button" onclick="window.print()">印刷</button>
<?php endif; ?>
  </div>
<?php if (!$detail): ?>
  <section><p class="empty">この生徒は表示できません（担当教室の生徒ではないか、登録がありません）。</p></section>
<?php else:
    $dLogs = $dState['logs'];
    $dAvg = $dLogs ? array_sum(array_column($dLogs, 'rate')) / count($dLogs) : null;
    $dPub = $dState['pub'];
    $dPubLogs = $dPub ? $dPub['logs'] : [];
    $dPubAvg = $dPubLogs ? array_sum(array_column($dPubLogs, 'score')) / count($dPubLogs) : null;
    $order = array_flip(array_keys(KAKOMON_SUBJECTS));
    $gOrder = ['' => 0, 'A' => 1, 'B' => 2];
?>
  <header class="rep">
    <div>
      <h1><?= h($detail['student_name']) ?><small><?= h($detail['login_id']) ?> ・ <?= h($detail['classroom_name']) ?>教室<?= $detail['grade'] ? '・' . h(grade_label($detail['grade'])) : '' ?></small></h1>
    </div>
    <div class="meta">過去問の進捗</div>
  </header>

  <section>
    <h2>全体</h2>
    <div class="profile">
      <span class="kind" style="color:#8A6D12">私立</span>
      <div><span class="k">進み具合（本人）</span><?= pace_html($dState['pace'], $detail['pace_at']) ?></div>
      <div><span class="k">購入した過去問</span><b><?= count($dState['schools']) ?></b>校</div>
      <div><span class="k">記録</span><b><?= count($dLogs) ?></b>回</div>
      <div><span class="k">平均得点率</span><?= rate_html($dAvg) ?></div>
    </div>
<?php if ($dPub !== null): ?>
    <div class="profile">
      <span class="kind" style="color:var(--midori)">公立</span>
      <div><span class="k">進み具合（本人）</span><?= pace_html($dPub['pace'], $detail['pub_pace_at']) ?></div>
      <div><span class="k">受ける公立高校</span><?php if ($dPub['schools']): foreach ($dPub['schools'] as $ps): ?><span class="sch"><?= h($ps['name']) ?></span><?php endforeach; else: ?><span class="dim">未登録</span><?php endif; ?></div>
      <div><span class="k">記録</span><b><?= count($dPubLogs) ?></b>回</div>
      <div><span class="k">平均点</span><?= pscore_html($dPubAvg) ?><span class="dim">／<?= KAKOMON_PUBLIC_MAX ?></span></div>
    </div>
<?php endif; ?>
  </section>

<?php if (!$dState['schools']): ?>
  <section class="school">
    <h2><span class="tag">私立</span>購入した過去問</h2>
    <p class="empty">まだ購入した過去問（高校）が登録されていません。</p>
  </section>
<?php endif; ?>
<?php foreach ($dState['schools'] as $sc):
    $sLogs = array_values(array_filter($dLogs, fn($l) => $l['school_id'] === $sc['id']));
    $sAvg = $sLogs ? array_sum(array_column($sLogs, 'rate')) / count($sLogs) : null;
    // 1回ずつの記録は 年度の新しい順 → 科目 → 周目
    usort($sLogs, fn($a, $b) => [$b['year'], $order[$a['subject']] ?? 99, $a['lap']] <=> [$a['year'], $order[$b['subject']] ?? 99, $b['lap']]);
?>
  <section class="school">
    <h2><span class="tag">私立</span><?= h($sc['name']) ?><span class="sum">記録 <?= count($sLogs) ?>回<?= $sAvg !== null ? '・平均 ' . (int)round($sAvg) . '%' : '' ?></span></h2>
    <?= board_html($sLogs) ?>
<?php if ($sLogs): ?>
    <table class="logs">
      <thead><tr><th>年度</th><th>科目</th><th>周目</th><th>得点</th><th>できたところ</th><th>できなかったところ</th><th>記録した日</th></tr></thead>
      <tbody>
<?php foreach ($sLogs as $l): ?>
        <tr>
          <td class="head nowrap"><?= (int)$l['year'] ?>年度</td>
          <td class="head nowrap"><?= h(KAKOMON_SUBJECTS[$l['subject']] ?? $l['subject']) ?></td>
          <td class="head nowrap"><?= (int)$l['lap'] ?>周目</td>
          <td class="nowrap" data-label="得点"><?= rate_html((float)$l['rate']) ?><?= $l['score'] !== null ? ' <span class="dim">' . (int)$l['score'] . '/' . (int)$l['max_score'] . '点</span>' : '' ?></td>
          <td class="txt good" data-label="できた"><?= $l['good'] !== null ? h($l['good']) : '<span class="dim">—</span>' ?></td>
          <td class="txt bad" data-label="できなかった"><?= $l['bad'] !== null ? h($l['bad']) : '<span class="dim">—</span>' ?></td>
          <td class="nowrap dim" data-label="記録"><?= h($l['date_label']) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>
  </section>
<?php endforeach; ?>

<?php if ($dPub !== null):
    usort($dPubLogs, fn($a, $b) => [$b['year'], $gOrder[$a['group']] ?? 9, $order[$a['subject']] ?? 99, $a['lap']]
        <=> [$a['year'], $gOrder[$b['group']] ?? 9, $order[$b['subject']] ?? 99, $b['lap']]);
?>
  <section class="pub">
    <h2><span class="tag">公立</span>愛知県公立高校入試<span class="sum">各教科<?= KAKOMON_PUBLIC_MAX ?>点満点・記録 <?= count($dPubLogs) ?>回</span></h2>
    <?= pub_board_html($dPubLogs) ?>
<?php if ($dPubLogs): ?>
    <table class="logs">
      <thead><tr><th>年度</th><th>科目</th><th>周目</th><th>得点</th><th>できたところ</th><th>できなかったところ</th><th>記録した日</th></tr></thead>
      <tbody>
<?php foreach ($dPubLogs as $l): ?>
        <tr>
          <td class="head nowrap"><?= h(kakomon_public_exam_label((int)$l['year'], (string)$l['group'])) ?></td>
          <td class="head nowrap"><?= h(KAKOMON_SUBJECTS[$l['subject']] ?? $l['subject']) ?></td>
          <td class="head nowrap"><?= (int)$l['lap'] ?>周目</td>
          <td class="nowrap" data-label="得点"><?= pscore_html((float)$l['score']) ?><span class="dim">／<?= KAKOMON_PUBLIC_MAX ?>点（<?= (int)$l['rate'] ?>%）</span></td>
          <td class="txt good" data-label="できた"><?= $l['good'] !== null ? h($l['good']) : '<span class="dim">—</span>' ?></td>
          <td class="txt bad" data-label="できなかった"><?= $l['bad'] !== null ? h($l['bad']) : '<span class="dim">—</span>' ?></td>
          <td class="nowrap dim" data-label="記録"><?= h($l['date_label']) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>
  </section>
<?php endif; ?>
<?php endif; ?>

<?php else: ?>
  <!-- ============ 一覧 ============ -->
  <div class="nav">
    <a href="/teacher.php">← 講師ページ</a>
    <button class="btnprint" type="button" onclick="window.print()">印刷</button>
  </div>

  <header class="rep">
    <div><h1>過去問の進捗<small><?= $kind === 'public' ? '公立（愛知県公立高校入試）' : '私立' ?></small></h1></div>
    <div class="meta"><?= h($me['teacher_name']) ?> 先生<br><?= h((new DateTimeImmutable('now'))->format('Y/n/j H:i')) ?> 時点</div>
  </header>

  <nav class="kinds">
    <a class="<?= $kind === 'private' ? 'on' : '' ?>" href="<?= h(list_url($listBase, ['kind' => null])) ?>">私立</a>
    <a class="<?= $kind === 'public' ? 'on' : '' ?>" href="<?= h(list_url($listBase, ['kind' => 'public'])) ?>">公立</a>
  </nav>
  <nav class="tabs">
<?php if (count($classrooms) > 1): ?>
    <a class="<?= $cid === 0 ? 'on' : '' ?>" href="<?= h(list_url($listBase, ['cid' => null])) ?>">すべての教室</a>
<?php endif; ?>
<?php foreach ($classrooms as $c): ?>
    <a class="<?= $cid === (int)$c['classroom_id'] || count($classrooms) === 1 ? 'on' : '' ?>" href="<?= h(list_url($listBase, ['cid' => (int)$c['classroom_id']])) ?>"><?= h($c['classroom_name']) ?></a>
<?php endforeach; ?>
    <span class="sp"></span>
    <a class="<?= $showTest ? 'on' : '' ?>" href="<?= h(list_url($listBase, ['showtest' => $showTest ? null : '1'])) ?>"><?= $showTest ? 'テスト生を隠す' : 'テスト生を表示' ?></a>
  </nav>

<?php if ($kind === 'public' && !$pubReady): ?>
  <section>
    <h2>公立の準備ができていません</h2>
    <p class="note" style="font-size:13px;">公立の記録用のテーブルがまだありません。
      phpMyAdmin で <b>db/migrations/migrate_kakomon_public.sql</b> を1回実行してください。</p>
  </section>
<?php else: ?>
  <section>
    <h2>生徒全体の進捗（<?= $kind === 'public' ? '公立' : '私立' ?>）</h2>
    <div class="tiles">
      <div class="tile"><div class="n"><?= count($rows) ?><small>人</small></div><div class="l">対象の生徒</div></div>
      <div class="tile"><div class="n"><?= $withLogs ?><small>人</small></div><div class="l">記録をつけている</div></div>
<?php foreach (KAKOMON_PACES as $k => $label): ?>
      <div class="tile <?= h($k) ?>"><div class="n"><?= $paceCount[$k] ?><small>人</small></div><div class="l">進み具合「<?= h($label) ?>」</div></div>
<?php endforeach; ?>
      <div class="tile"><div class="n" style="color:var(--ink-soft)"><?= $paceCount['none'] ?><small>人</small></div><div class="l">進み具合 未回答</div></div>
    </div>

<?php if (!$rows): ?>
    <p class="empty">対象の生徒がいません（中3の生徒か、過去問の記録をつけた生徒がここに出ます）。</p>
<?php else: ?>
    <div class="tblwrap">
    <table class="list" id="list">
      <thead><tr>
        <th data-type="text">生徒</th>
<?php if ($multiClass): ?>
        <th data-type="num">教室</th>
<?php endif; ?>
        <th data-type="num">学年</th>
        <th data-type="num">進み具合</th>
<?php if ($kind === 'public'): ?>
        <th data-type="num">受ける公立高校</th>
        <th class="num" data-type="num">記録</th>
        <th class="num" data-type="num">平均点</th>
<?php foreach (KAKOMON_SUBJECTS as $label): ?>
        <th class="num subj" data-type="num"><?= h(mb_substr($label, 0, 1)) ?></th>
<?php endforeach; ?>
<?php else: ?>
        <th data-type="num">購入した過去問（記録した回数）</th>
        <th class="num" data-type="num">記録</th>
        <th class="num" data-type="num">平均得点率</th>
<?php endif; ?>
        <th class="num" data-type="num">最高周目</th>
        <th class="num" data-type="num">最後に記録</th>
      </tr></thead>
      <tbody>
<?php foreach ($rows as $r):
    $isPub = $kind === 'public';
    $rPace = $isPub ? $r['pub_pace'] : $r['pace'];
    $rPaceAt = $isPub ? $r['pub_pace_at'] : $r['pace_at'];
    $rN = $isPub ? $r['pub_n'] : $r['n'];
    $rMaxLap = $isPub ? $r['pub_max_lap'] : $r['max_lap'];
    $rLast = $isPub ? $r['pub_last_at'] : $r['last_at'];
    // 並べ替え用の値: 進み具合は 遅い→普通→早い→未回答 の順に小さい
    $paceKey = ['slow' => 1, 'normal' => 2, 'fast' => 3][$rPace ?? ''] ?? 4;
?>
        <tr>
          <td class="name" data-v="<?= h($r['student_name']) ?>"><a href="<?= h(list_url($listBase, ['student_id' => $r['student_id']])) ?>"><?= h($r['student_name']) ?></a></td>
<?php if ($multiClass): ?>
          <td class="nowrap" data-v="<?= $r['classroom_id'] ?>"><?= h($r['classroom_name']) ?></td>
<?php endif; ?>
          <td class="nowrap" data-v="<?= grade_sort_key($r['grade']) ?>"><?= h(grade_label($r['grade'])) ?: '<span class="dim">—</span>' ?></td>
          <td class="nowrap" data-v="<?= $paceKey ?>"><?= pace_html($rPace, $rPaceAt) ?></td>
<?php if ($isPub): ?>
          <td data-v="<?= count($r['pub_schools']) ?>"><?php if ($r['pub_schools']): foreach ($r['pub_schools'] as $pn): ?><span class="sch"><?= h($pn) ?></span><?php endforeach; else: ?><span class="dim">未登録</span><?php endif; ?></td>
          <td class="num" data-v="<?= $rN ?>"><?= $rN > 0 ? $rN . '回' : '<span class="dim">0</span>' ?></td>
          <td class="num" data-v="<?= $r['pub_avg'] !== null ? round($r['pub_avg'], 2) : -1 ?>"><?= pscore_html($r['pub_avg']) ?></td>
<?php foreach (array_keys(KAKOMON_SUBJECTS) as $k): ?>
          <td class="num" data-v="<?= $r['pub_subj'][$k] !== null ? round($r['pub_subj'][$k], 2) : -1 ?>"><?= pscore_html($r['pub_subj'][$k]) ?></td>
<?php endforeach; ?>
<?php else: ?>
          <td data-v="<?= count($r['schools']) ?>"><?php if ($r['schools']): foreach ($r['schools'] as $sc): ?><span class="sch"><?= h($sc['name']) ?><b><?= $sc['n'] ?></b></span><?php endforeach; else: ?><span class="dim">未登録</span><?php endif; ?></td>
          <td class="num" data-v="<?= $rN ?>"><?= $rN > 0 ? $rN . '回' : '<span class="dim">0</span>' ?></td>
          <td class="num" data-v="<?= $r['avg_rate'] !== null ? round($r['avg_rate'], 1) : -1 ?>"><?= rate_html($r['avg_rate']) ?></td>
<?php endif; ?>
          <td class="num" data-v="<?= $rMaxLap ?>"><?= $rMaxLap > 0 ? $rMaxLap . '周目' : '<span class="dim">—</span>' ?></td>
          <td class="num" data-v="<?= $rLast !== null ? h(str_replace([' ', '-', ':'], '', $rLast)) : 0 ?>"><?= $rLast !== null ? h(kakomon_date_label($rLast)) : '<span class="dim">—</span>' ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php if ($kind === 'public'): ?>
    <p class="note">進み具合は生徒が自分で選んだもの（日付はそれを選んだ日）。平均点は各教科<?= KAKOMON_PUBLIC_MAX ?>点満点で、全年度・全周目の記録の平均（国〜社は科目ごと）。列の見出しを押すと並べ替えられます。</p>
<?php else: ?>
    <p class="note">進み具合は生徒が自分で選んだもの（日付はそれを選んだ日）。平均得点率は全科目・全周目の記録の平均。列の見出しを押すと並べ替えられます。</p>
<?php endif; ?>
<?php endif; ?>
  </section>
<?php endif; ?>

<script>
// 列見出しで並べ替え（同じ列をもう一度押すと逆順）
(function () {
  var table = document.getElementById('list');
  if (!table) return;
  var heads = table.tHead.rows[0].cells;
  [].forEach.call(heads, function (th, col) {
    th.addEventListener('click', function () {
      var desc = th.classList.contains('sort-asc');
      [].forEach.call(heads, function (o) { o.classList.remove('sort-asc', 'sort-desc'); });
      th.classList.add(desc ? 'sort-desc' : 'sort-asc');
      var num = th.getAttribute('data-type') === 'num';
      var body = table.tBodies[0];
      var rows = [].slice.call(body.rows);
      rows.sort(function (a, b) {
        var x = a.cells[col].getAttribute('data-v'), y = b.cells[col].getAttribute('data-v');
        var c = num ? (parseFloat(x) - parseFloat(y)) : x.localeCompare(y, 'ja');
        return desc ? -c : c;
      });
      rows.forEach(function (r) { body.appendChild(r); });
    });
  });
})();
</script>
<?php endif; ?>

</div>
</body>
</html>
