<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/self_study_common.php';

// 自習記録の一覧。
//  生徒: 自分の記録（student_id は無視される）
//  講師: ?student_id=123 で指定した生徒の記録（担当教室のみ。super_admin は全教室）
//  保護者: ?student_id=123 でひもづく子の記録（読むだけ。メモ・コメントも見せる）
// 共通パラメータ: ?limit=（既定30・最大200） ?from=YYYY-MM-DD ?unchecked=1

$actor = require_login(['student', 'teacher', 'guardian']);
$pdo = db();

if ($actor['type'] === 'student') {
    $studentId = (int)$actor['id'];
} else {
    $studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
    if ($studentId <= 0) {
        json_response(['ok' => false, 'error' => 'invalid_request'], 400);
    }
    if ($actor['type'] === 'teacher') {
        self_study_require_teacher_access($pdo, (int)$actor['id'], $studentId);
    } else {
        // 保護者は自分にひもづく子だけ
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM guardian_students WHERE guardian_id = :gid AND student_id = :sid'
        );
        $stmt->execute(['gid' => (int)$actor['id'], 'sid' => $studentId]);
        if ((int)$stmt->fetchColumn() === 0) {
            json_response(['ok' => false, 'error' => 'forbidden'], 403);
        }
    }
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
$limit = max(1, min(200, $limit));

$where = '';
$params = ['sid' => $studentId];

if (!empty($_GET['from'])) {
    $from = (string)$_GET['from'];
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
    if (!$d || $d->format('Y-m-d') !== $from) {
        json_response(['ok' => false, 'error' => 'invalid_date'], 400);
    }
    $where .= ' AND sslog.study_date >= :from';
    $params['from'] = $from;
}
if (!empty($_GET['unchecked'])) {
    $where .= ' AND sslog.checked_at IS NULL';
}

$cols = self_study_select_columns($pdo);

$stmt = $pdo->prepare(
    "SELECT {$cols}
     FROM self_study_logs sslog
     LEFT JOIN teachers t ON t.teacher_id = sslog.teacher_id
     WHERE sslog.student_id = :sid{$where}
     ORDER BY sslog.study_date DESC, sslog.log_id DESC
     LIMIT {$limit}"
);
$stmt->execute($params);

$items = array_map('self_study_row', $stmt->fetchAll());

// 教材名の入力候補（この生徒が過去に書いた教材名。よく使う順）
$stmt = $pdo->prepare(
    'SELECT material, COUNT(*) AS c FROM self_study_logs
     WHERE student_id = :sid GROUP BY material ORDER BY c DESC, MAX(log_id) DESC LIMIT 20'
);
$stmt->execute(['sid' => $studentId]);
$materials = array_column($stmt->fetchAll(), 'material');

json_response(['ok' => true, 'items' => $items, 'materials' => $materials]);
