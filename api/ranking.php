<?php
declare(strict_types=1);

// 生徒ランキング集計（teacher.php / mypage.php 共用）
// スキーマ変更なしの集計のみで作る（設計判断6）。順位テーブルは持たない。
// 正答率ランキングには最低問題数の足切りを入れる（設計判断7）

const RANK_MIN_SOLVED = 10;   // 正答率ランキングに載る最低解答数

/**
 * 指定教室の生徒ごとに、期間内の解答数・正解数・獲得XPを集計して返す。
 * $classroomIds が null なら全教室混合。$fromStr/$toStr が null なら全期間。
 */
function ranking_rows(PDO $pdo, ?array $classroomIds, ?string $fromStr, ?string $toStr, bool $includeTest = false): array
{
    $wc = '';
    if ($classroomIds !== null) {
        $classroomIds = array_values(array_filter(array_map('intval', $classroomIds), fn($v) => $v > 0));
        if (count($classroomIds) === 0) {
            return [];
        }
        $wc = ' AND s.classroom_id IN (' . implode(',', $classroomIds) . ')';
    }

    // テスト生（名前に「テスト」を含む）はランキングから除外。テスト時のみ含める
    $wTest = $includeTest ? '' : " AND s.student_name NOT LIKE '%テスト%'";

    // 同名プレースホルダは再利用できない(エミュレーション無効)ため、サブクエリごとに別名にする
    $params = [];
    $wa1 = $wa2 = $wx = '';
    if ($fromStr !== null) {
        $params += ['fromA1' => $fromStr, 'toA1' => $toStr];
        $wa1 = ' AND al.answered_at >= :fromA1 AND al.answered_at < :toA1';
        $params += ['fromA2' => $fromStr, 'toA2' => $toStr];
        $wa2 = ' AND al.answered_at >= :fromA2 AND al.answered_at < :toA2';
        $params += ['fromX' => $fromStr, 'toX' => $toStr];
        $wx = ' AND xl.created_at >= :fromX AND xl.created_at < :toX';
    }

    $stmt = $pdo->prepare(
        "SELECT s.student_id, s.student_name, s.grade, s.classroom_id, c.classroom_name,
                (SELECT COUNT(*) FROM answer_logs al
                  WHERE al.student_id = s.student_id{$wa1}) AS solved,
                (SELECT COALESCE(SUM(al.is_correct),0) FROM answer_logs al
                  WHERE al.student_id = s.student_id{$wa2}) AS correct,
                (SELECT COALESCE(SUM(xl.amount),0) FROM xp_logs xl
                  WHERE xl.student_id = s.student_id{$wx}) AS xp
         FROM students s
         JOIN classrooms c ON c.classroom_id = s.classroom_id
         WHERE s.is_active = 1{$wc}{$wTest}"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * ranking_rows の結果を指標別に順位付けして返す。
 * $metric: 'solved'(解答数) | 'rate'(正答率・足切りあり) | 'xp'(獲得XP)
 * 各行に value(表示値) と rank(同値は同順位) を付ける。実績0の生徒は載せない。
 */
function ranking_ranked(array $rows, string $metric): array
{
    $list = [];
    foreach ($rows as $r) {
        $solved = (int)$r['solved'];
        if ($metric === 'rate') {
            if ($solved < RANK_MIN_SOLVED) continue;
            $r['value'] = round(100 * (int)$r['correct'] / $solved, 1);
        } else {
            $r['value'] = (int)$r[$metric];
            if ($r['value'] <= 0) continue;
        }
        $r['solved'] = $solved;
        $list[] = $r;
    }
    // 同値の並びは解答数が多い方を上に表示（順位自体は同値=同順位）
    usort($list, fn($a, $b) => ($b['value'] <=> $a['value']) ?: ($b['solved'] <=> $a['solved']));
    $rank = 0;
    $prev = null;
    foreach ($list as $i => &$r) {
        if ($prev === null || $r['value'] < $prev) {
            $rank = $i + 1;
            $prev = $r['value'];
        }
        $r['rank'] = $rank;
    }
    unset($r);
    return $list;
}

/**
 * ranking_events.php の台帳から今日開催中のイベントを返す（なければ null）。
 * from/to は 'Y-m-d' で両端の日を含む。
 */
function ranking_active_event(array $events): ?array
{
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    foreach ($events as $ev) {
        if ($ev['from'] <= $today && $today <= $ev['to']) {
            return $ev;
        }
    }
    return null;
}
