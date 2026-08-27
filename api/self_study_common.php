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
    return [
        'log_id'          => (int)$row['log_id'],
        'study_date'      => (string)$row['study_date'],
        'subject'         => (string)$row['subject'],
        'subject_label'   => SELF_STUDY_SUBJECTS[$row['subject']] ?? (string)$row['subject'],
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
