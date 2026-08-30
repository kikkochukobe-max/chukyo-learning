<?php
declare(strict_types=1);

// 自習記録（self_study_logs）の共通定義。
// 生徒側 API・講師側 API・mypage.php・teacher.php の4か所が読む。
// ラベルを1か所にまとめておかないと、教科名や手ごたえの表記が画面ごとにブレる。

// 教科キー。unit_key の先頭要素（teacher.php の SUBJECT_LABELS）と揃えたうえで、
// 自習では出てくるが学習ツールには無い教科（社会・その他）も選べるようにしてある。
const SELF_STUDY_SUBJECTS = [
    'math'     => '数学・算数',
    'english'  => '英語',
    'science'  => '理科',
    'japanese' => '国語',
    'social'   => '社会',
    'other'    => 'その他',
];

// 勉強の種類。生徒は「覚える勉強」の欄と「忘れない勉強」の欄に分けて入力する。
// （塾で使っている言葉をそのまま画面に出す。言い換えない）
const SELF_STUDY_TYPES = [
    'memorize' => '覚える勉強',
    'retain'   => '忘れない勉強',
];

// 各欄の下に小さく出す説明。何を書けばよいかの判断材料になる
const SELF_STUDY_TYPE_DESCS = [
    'memorize' => '最近習ったこと・思い出したばかりの復習',
    'retain'   => '前に正解した問題の再確認',
];

// 「忘れない勉強」だけが持つ短期／長期。'memorize' の行では常に NULL
const SELF_STUDY_RETAIN_SPANS = [
    'short' => '短期',
    'long'  => '長期',
];

const SELF_STUDY_RETAIN_SPAN_DESCS = [
    'short' => '1週間以内に正解した問題',
    'long'  => '1か月以内に正解した問題',
];

// 1回の送信でまとめて保存できる件数の上限（6教科×2種類＝12でも足りる）
const SELF_STUDY_MAX_ITEMS = 20;

// 手ごたえ 1〜5。生徒が押すボタンの文言そのもの（ひらがな主体）
const SELF_STUDY_FEELINGS = [
    1 => 'むずかしかった',
    2 => 'もう少し',
    3 => 'まあまあ',
    4 => 'できた',
    5 => 'かんぺき',
];

const SELF_STUDY_FEELING_FACES = [1 => '😵', 2 => '😥', 3 => '🙂', 4 => '😄', 5 => '💮'];

// 生徒が入力できる日付の範囲（今日から遡って何日前まで書けるか）。
// 未来日は不可。まとめ書きを許しつつ、いつまでも過去を書き足せないようにする。
const SELF_STUDY_BACKDATE_DAYS = 31;

// study_type / retain_span 列があるか。
// db/migrations/migrate_self_study_type.sql を流す前に PHP だけ上がっても
// 画面が落ちないようにするための判定（列が無ければ「区別なし」として動く）。
function self_study_has_type(PDO $pdo): bool
{
    static $has = null;
    if ($has === null) {
        $has = table_has_column($pdo, 'self_study_logs', 'study_type');
    }
    return $has;
}

// self_study_row() に渡すための SELECT 列。
// list_self_study.php / check_self_study.php / teacher.php の3か所が同じ形で読むので
// ここ1か所にまとめる（列を足したときの入れ忘れを防ぐ）。
// 表の別名は sslog（SSL は MySQL の予約語なので使わない）、講師は t 固定。
function self_study_select_columns(PDO $pdo): string
{
    $cols = 'sslog.log_id, sslog.study_date, sslog.subject, sslog.material, sslog.range_text,
             sslog.minutes, sslog.feeling, sslog.memo, sslog.checked_at, sslog.teacher_comment,
             t.teacher_name';
    if (self_study_has_type($pdo)) {
        $cols .= ', sslog.study_type, sslog.retain_span';
    }
    return $cols;
}

// バッジに出す文言。「忘れない勉強・長期」のように短期／長期まで含める。
// 区別を持たない古い記録（両方 NULL）は null を返す＝バッジを出さない。
function self_study_type_label(?string $type, ?string $span): ?string
{
    if ($type === null || !isset(SELF_STUDY_TYPES[$type])) {
        return null;
    }
    $label = SELF_STUDY_TYPES[$type];
    if ($type === 'retain' && $span !== null && isset(SELF_STUDY_RETAIN_SPANS[$span])) {
        $label .= '・' . SELF_STUDY_RETAIN_SPANS[$span];
    }
    return $label;
}

// 講師がこの生徒を見てよいか（super_admin=全教室 / それ以外=担当教室のみ）。
// 権限が無ければその場で403を返して終了する。
function self_study_require_teacher_access(PDO $pdo, int $teacherId, int $studentId): void
{
    $stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :id');
    $stmt->execute(['id' => $teacherId]);
    $role = (string)$stmt->fetchColumn();

    if ($role === 'super_admin') {
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

    if ((int)$stmt->fetchColumn() === 0) {
        json_response(['ok' => false, 'error' => 'forbidden'], 403);
    }
}

// 1行を画面用の配列に整える（生徒側・講師側で同じ形にする）
function self_study_row(array $row): array
{
    $feeling = $row['feeling'] !== null ? (int)$row['feeling'] : null;
    // study_type / retain_span はマイグレーション前の環境だとキー自体が無い
    $type = $row['study_type'] ?? null;
    $span = $row['retain_span'] ?? null;
    return [
        'log_id'          => (int)$row['log_id'],
        'study_date'      => (string)$row['study_date'],
        'subject'         => (string)$row['subject'],
        'subject_label'   => SELF_STUDY_SUBJECTS[$row['subject']] ?? (string)$row['subject'],
        'study_type'      => $type,
        'retain_span'     => $span,
        'study_type_label' => self_study_type_label($type, $span),
        'material'        => (string)$row['material'],
        'range_text'      => $row['range_text'],
        'minutes'         => $row['minutes'] !== null ? (int)$row['minutes'] : null,
        'feeling'         => $feeling,
        'feeling_label'   => $feeling !== null ? (SELF_STUDY_FEELINGS[$feeling] ?? '') : null,
        'memo'            => $row['memo'],
        'checked_at'      => $row['checked_at'],
        'teacher_comment' => $row['teacher_comment'],
        'teacher_name'    => $row['teacher_name'] ?? null,
    ];
}
