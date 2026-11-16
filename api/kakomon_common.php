<?php
declare(strict_types=1);

// 過去問の記録の共通定義。
//   私立: kakomon_schools / kakomon_logs / kakomon_pace（db/migrations/migrate_kakomon.sql）
//   公立: kakomon_public_logs / kakomon_public_pace（db/migrations/migrate_kakomon_public.sql）
// 生徒ページ kakomon.php・生徒API save_kakomon.php・講師ページ kakomon_teacher.php・mypage.php が読む。
// 科目名や進み具合の文言を1か所にまとめておかないと、画面ごとに表記がブレる。
// 年度はどの画面も西暦だけで出す（「2026年度」。和暦は併記しない）。

// 科目。並びは国数英理社（私立は3科＝国数英の学校が多いので、理社は後ろ）
const KAKOMON_SUBJECTS = [
    'japanese' => '国語',
    'math'     => '数学',
    'english'  => '英語',
    'science'  => '理科',
    'social'   => '社会',
];

// 志望校マスター target_schools.region の地区（db/migrations/migrate_target_school_region.sql）。
// 並びは「高校を追加」のプルダウンで見出しを出す順。ここに無い値・NULL は最後の「その他」にまとめる
const SCHOOL_REGIONS = [
    'nagoya' => '名古屋地区',
    'owari'  => '尾張地区',
    'mikawa' => '三河地区',
];

// 何周目まで記録できるか（1〜4周目）
const KAKOMON_LAPS = 4;

// 全体の進み具合（生徒が自分で選ぶ）
const KAKOMON_PACES = [
    'fast'   => '早い',
    'normal' => '普通',
    'slow'   => '遅い',
];

const KAKOMON_PACE_DESCS = [
    'fast'   => '予定より進んでいる',
    'normal' => '予定どおり',
    'slow'   => '予定より遅れている',
];

// 生徒の画面で選べる年度の数（最新の年度から何年度ぶんさかのぼるか）
const KAKOMON_YEAR_SPAN = 10;

// できたところ／できなかったところ の文字数上限（列は VARCHAR(500)）
const KAKOMON_TEXT_MAX = 500;

// 1人が登録できる高校の数の上限。生徒が受けられる私立は最大3校（公立を入れて5校）なので3
const KAKOMON_MAX_SCHOOLS = 3;

// 満点の上限（列は SMALLINT UNSIGNED。誤入力の 10000 点などを弾く）
const KAKOMON_SCORE_MAX = 1000;

// ---- 公立（愛知県公立高校入試。学力検査は県内共通なので高校は持たない） ----
// 各教科の満点
const KAKOMON_PUBLIC_MAX = 22;

// 2022年度まではAグループ・Bグループの2回、2023年度から1回。
// この年度以前は A/B を選ばせ、より後は ''（1回）として持つ
const KAKOMON_PUBLIC_AB_LAST_YEAR = 2022;

// 受ける公立高校の登録の上限（受けられるのは私立3校＋公立2校）
const KAKOMON_PUBLIC_MAX_SCHOOLS = 2;

const KAKOMON_PUBLIC_GROUPS = [
    'A' => 'Aグループ',
    'B' => 'Bグループ',
];

// テーブルがあるか。migrate_kakomon.sql を流す前に PHP だけ上がっても
// 画面が落ちないようにするための判定（無ければ「準備中」を出す）。
function kakomon_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = table_has_column($pdo, 'kakomon_schools', 'kschool_id')
            && table_has_column($pdo, 'kakomon_logs', 'log_id')
            && table_has_column($pdo, 'kakomon_pace', 'pace');
    }
    return $ready;
}

// 公立のテーブルがあるか（migrate_kakomon_public.sql）。無ければ公立タブだけ「準備中」
function kakomon_public_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = table_has_column($pdo, 'kakomon_public_logs', 'exam_group')
            && table_has_column($pdo, 'kakomon_public_schools', 'pschool_id')
            && table_has_column($pdo, 'kakomon_public_pace', 'pace');
    }
    return $ready;
}

// 公立の点数（22点満点）→ 得点率(%)
function kakomon_public_rate(int $score): int
{
    return (int)round(100 * $score / KAKOMON_PUBLIC_MAX);
}

// 公立の「回」の名前。「2022年度 Aグループ」／「2024年度」
function kakomon_public_exam_label(int $year, string $group): string
{
    return $year . '年度' . (isset(KAKOMON_PUBLIC_GROUPS[$group]) ? ' ' . KAKOMON_PUBLIC_GROUPS[$group] : '');
}

// いちばん新しい年度。入試は1〜2月なので、4月になったらその年の年度を出す
// （11月なら今年、1月なら去年＝今年の入試はまだ終わっていない）。
function kakomon_year_max(): int
{
    $now = new DateTimeImmutable('now');
    $y = (int)$now->format('Y');
    return (int)$now->format('n') >= 4 ? $y : $y - 1;
}

// 生徒の画面で選べる年度（新しい順）
function kakomon_years(): array
{
    $max = kakomon_year_max();
    $out = [];
    for ($y = $max; $y > $max - KAKOMON_YEAR_SPAN; $y--) {
        $out[] = $y;
    }
    return $out;
}

// 保存を受け付ける年度の範囲（画面の選択肢より広くとる＝古い記録をなおしても弾かない）
function kakomon_year_ok(int $y): bool
{
    $max = kakomon_year_max();
    return $y <= $max && $y >= $max - 30;
}

// 年度の表示。西暦だけ「2026年度」（和暦は併記しない＝どの画面も同じ表記にそろえる）
function kakomon_year_label(int $y): string
{
    return $y . '年度';
}

// 学年が中3か（es4/js3 形式が正だが 中3 / 中３ も拾う。report.php の stage_of と同じ方針）
function kakomon_is_js3(?string $grade): bool
{
    if ($grade === null) {
        return false;
    }
    $g = strtr(trim($grade), ['０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
        '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9']);
    return (bool)preg_match('/^(js|中)\D*3$/ui', $g);
}

// 得点率の段階（画面の色分け）。60%未満は「がんばりどころ」の橙（赤で責めない）
function kakomon_rate_class(?float $rate): string
{
    if ($rate === null) {
        return 'na';
    }
    if ($rate >= 80) {
        return 'good';
    }
    return $rate >= 60 ? 'mid' : 'low';
}

// 記録した日の表示。今年なら「11/16」、年をまたいだら「2025/12/3」
function kakomon_date_label(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '';
    }
    $d = date_create_immutable($dt);
    if (!$d) {
        return '';
    }
    return $d->format('Y') === date('Y') ? $d->format('n/j') : $d->format('Y/n/j');
}

// 講師がこの生徒を見てよいか（super_admin=全教室 / それ以外=担当教室のみ）
function kakomon_teacher_can_view(PDO $pdo, int $teacherId, int $studentId): bool
{
    $stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :id');
    $stmt->execute(['id' => $teacherId]);
    if ((string)$stmt->fetchColumn() === 'super_admin') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE student_id = :sid');
        $stmt->execute(['sid' => $studentId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM students s
             WHERE s.student_id = :sid
               AND s.classroom_id IN (SELECT classroom_id FROM teacher_classrooms WHERE teacher_id = :tid)'
        );
        $stmt->execute(['sid' => $studentId, 'tid' => $teacherId]);
    }
    return (int)$stmt->fetchColumn() > 0;
}

// 高校を選ぶための学校の一覧（志望校マスターの有効な学校だけ。$kind = 'private' / 'public'）。
// region 列が無い環境（migrate_target_school_region.sql 未適用）では地区なし＝区切らずに並べる
function kakomon_master_schools(PDO $pdo, string $kind): array
{
    $hasRegion = table_has_column($pdo, 'target_schools', 'region');
    $stmt = $pdo->prepare(
        'SELECT target_school_id, name' . ($hasRegion ? ', region' : '') . ' FROM target_schools
         WHERE kind = :kind AND is_active = 1
         ORDER BY sort_order, name'
    );
    $stmt->execute(['kind' => $kind === 'public' ? 'public' : 'private']);
    $rows = $stmt->fetchAll();
    return array_map(fn($r) => [
        'id'     => (int)$r['target_school_id'],
        'name'   => (string)$r['name'],
        'region' => isset($r['region'], SCHOOL_REGIONS[$r['region']]) ? (string)$r['region'] : null,
    ], $rows);
}

// 1人ぶんの記録をまるごと画面用の形にする。
// 生徒ページの初期表示・保存APIの返り値・講師の個人ページが同じ形で読む。
function kakomon_state(PDO $pdo, int $studentId): array
{
    // ---- 全体の進み具合 ----
    $stmt = $pdo->prepare('SELECT pace, updated_at FROM kakomon_pace WHERE student_id = :sid');
    $stmt->execute(['sid' => $studentId]);
    $p = $stmt->fetch();
    $pace = ($p && isset(KAKOMON_PACES[$p['pace']])) ? (string)$p['pace'] : null;

    // ---- 購入した過去問（高校） ----
    // 名前はマスターが優先（改名に追従）。マスターから消えた・手入力の学校は school_name
    $stmt = $pdo->prepare(
        'SELECT ks.kschool_id, ks.target_school_id, COALESCE(ts.name, ks.school_name) AS name
         FROM kakomon_schools ks
         LEFT JOIN target_schools ts ON ts.target_school_id = ks.target_school_id
         WHERE ks.student_id = :sid
         ORDER BY ks.kschool_id'
    );
    $stmt->execute(['sid' => $studentId]);
    $schools = [];
    foreach ($stmt->fetchAll() as $r) {
        $schools[] = [
            'id'               => (int)$r['kschool_id'],
            'target_school_id' => $r['target_school_id'] !== null ? (int)$r['target_school_id'] : null,
            'name'             => (string)$r['name'],
            'log_count'        => 0,
        ];
    }

    // ---- 1回ぶんの記録（新しく書いた順） ----
    $stmt = $pdo->prepare(
        'SELECT log_id, kschool_id, exam_year, subject, lap, score, max_score, rate,
                good_text, bad_text, created_at, updated_at
         FROM kakomon_logs
         WHERE student_id = :sid
         ORDER BY created_at DESC, log_id DESC'
    );
    $stmt->execute(['sid' => $studentId]);
    $logs = [];
    $countBySchool = [];
    foreach ($stmt->fetchAll() as $r) {
        $sid = (int)$r['kschool_id'];
        $countBySchool[$sid] = ($countBySchool[$sid] ?? 0) + 1;
        $logs[] = [
            'id'         => (int)$r['log_id'],
            'school_id'  => $sid,
            'year'       => (int)$r['exam_year'],
            'subject'    => (string)$r['subject'],
            'lap'        => (int)$r['lap'],
            'score'      => $r['score'] !== null ? (int)$r['score'] : null,
            'max_score'  => $r['max_score'] !== null ? (int)$r['max_score'] : null,
            'rate'       => (int)$r['rate'],
            'good'       => $r['good_text'],
            'bad'        => $r['bad_text'],
            'created_at' => (string)$r['created_at'],
            'date_label' => kakomon_date_label((string)$r['created_at']),
        ];
    }
    foreach ($schools as &$s) {
        $s['log_count'] = $countBySchool[$s['id']] ?? 0;
    }
    unset($s);

    return [
        'pace'               => $pace,
        'pace_label'         => $pace !== null ? KAKOMON_PACES[$pace] : null,
        'pace_updated_label' => $pace !== null ? kakomon_date_label((string)$p['updated_at']) : null,
        'schools'            => $schools,
        'logs'               => $logs,
        // 公立（テーブルが無ければ null＝公立タブは「準備中」）
        'pub'                => kakomon_public_ready($pdo) ? kakomon_public_state($pdo, $studentId) : null,
    ];
}

// 公立ぶん（kakomon_state の 'pub'）。進み具合と1回ずつの記録（新しく書いた順）
function kakomon_public_state(PDO $pdo, int $studentId): array
{
    $stmt = $pdo->prepare('SELECT pace, updated_at FROM kakomon_public_pace WHERE student_id = :sid');
    $stmt->execute(['sid' => $studentId]);
    $p = $stmt->fetch();
    $pace = ($p && isset(KAKOMON_PACES[$p['pace']])) ? (string)$p['pace'] : null;

    // 受ける公立高校（記録とはひもづけない。名前はマスター優先）
    $stmt = $pdo->prepare(
        'SELECT ps.pschool_id, ps.target_school_id, COALESCE(ts.name, ps.school_name) AS name
         FROM kakomon_public_schools ps
         LEFT JOIN target_schools ts ON ts.target_school_id = ps.target_school_id
         WHERE ps.student_id = :sid
         ORDER BY ps.pschool_id'
    );
    $stmt->execute(['sid' => $studentId]);
    $schools = [];
    foreach ($stmt->fetchAll() as $r) {
        $schools[] = [
            'id'               => (int)$r['pschool_id'],
            'target_school_id' => $r['target_school_id'] !== null ? (int)$r['target_school_id'] : null,
            'name'             => (string)$r['name'],
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT log_id, exam_year, exam_group, subject, lap, score, good_text, bad_text, created_at
         FROM kakomon_public_logs
         WHERE student_id = :sid
         ORDER BY created_at DESC, log_id DESC'
    );
    $stmt->execute(['sid' => $studentId]);
    $logs = [];
    foreach ($stmt->fetchAll() as $r) {
        $score = (int)$r['score'];
        $logs[] = [
            'id'         => (int)$r['log_id'],
            'year'       => (int)$r['exam_year'],
            'group'      => (string)$r['exam_group'],
            'subject'    => (string)$r['subject'],
            'lap'        => (int)$r['lap'],
            'score'      => $score,
            'rate'       => kakomon_public_rate($score),
            'good'       => $r['good_text'],
            'bad'        => $r['bad_text'],
            'created_at' => (string)$r['created_at'],
            'date_label' => kakomon_date_label((string)$r['created_at']),
        ];
    }

    return [
        'pace'               => $pace,
        'pace_label'         => $pace !== null ? KAKOMON_PACES[$pace] : null,
        'pace_updated_label' => $pace !== null ? kakomon_date_label((string)$p['updated_at']) : null,
        'schools'            => $schools,
        'logs'               => $logs,
    ];
}

// 画面（JS）に渡す固定の定義。生徒ページが読む
function kakomon_meta(PDO $pdo): array
{
    $subjects = [];
    foreach (KAKOMON_SUBJECTS as $k => $v) {
        $subjects[] = ['key' => $k, 'label' => $v];
    }
    $paces = [];
    foreach (KAKOMON_PACES as $k => $v) {
        $paces[] = ['key' => $k, 'label' => $v, 'desc' => KAKOMON_PACE_DESCS[$k]];
    }
    $years = [];
    foreach (kakomon_years() as $y) {
        $years[] = ['y' => $y, 'label' => kakomon_year_label($y)];
    }
    $regions = [];
    foreach (SCHOOL_REGIONS as $k => $v) {
        $regions[] = ['key' => $k, 'label' => $v];
    }
    $groups = [];
    foreach (KAKOMON_PUBLIC_GROUPS as $k => $v) {
        $groups[] = ['key' => $k, 'label' => $v];
    }
    return [
        // 公立: 各教科の満点・A/Bグループと、A/Bがあった最後の年度
        'pub'            => ['max' => KAKOMON_PUBLIC_MAX, 'groups' => $groups, 'abLastYear' => KAKOMON_PUBLIC_AB_LAST_YEAR,
                             'maxSchools' => KAKOMON_PUBLIC_MAX_SCHOOLS],
        'publicSchools'  => kakomon_master_schools($pdo, 'public'),
        'regions'        => $regions,
        'subjects'       => $subjects,
        'laps'           => KAKOMON_LAPS,
        'paces'          => $paces,
        'years'          => $years,
        'privateSchools' => kakomon_master_schools($pdo, 'private'),
        'textMax'        => KAKOMON_TEXT_MAX,
        'maxSchools'     => KAKOMON_MAX_SCHOOLS,
        'scoreMax'       => KAKOMON_SCORE_MAX,
    ];
}
