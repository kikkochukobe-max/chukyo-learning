<?php
declare(strict_types=1);

// タイムアタック（time_records）の集計。100マス計算などの「速さ」を
// マイページ・講師ページ・ランキングで見せるための共用関数群。
// 順位は best_ms（各生徒の期間内ベストタイム）の昇順（速いほど上位）。
//
// time_records テーブルがまだ無い環境（migrate 未適用）でも
// ページが落ちないよう、存在チェックを噛ませて空を返す。

// タイムを記録するツールの台帳（unit_key => 設定）。
// 新しく Divp.saveTime() を呼ぶツールを作ったらここに1行追加する
// （ここに無い unit_key は time_records に溜まっていても どの画面にも出ない）。
//   label        表示名
//   question_key ツールが saveTime に渡している question_key（省略時は全部まとめて集計）
//   ranking      true のときだけ「教室内の速さランキング」に載せる。
//                速さを競わせると急いでミスする方向に働くので、
//                本番形式の演習（愛知 大問1 など）は false にしてタイムだけ見せる
//   order        'time'=速い順に上位を並べる / 'recent'=新しい順に並べる
//   total        1プレイの問題数。指定すると miss_count から得点(total - miss)を出す
//   miss_label   miss_count 列の見出し（既定「ミス」）
//   precision    'ms'=1:03.4 表記（既定） / 'sec'=8分12秒 表記（秒単位でしか測らない単元）
function time_units(): array
{
    return [
        'math_es_hyakumasu' => [
            'label'   => '100マス たし算',
            'ranking' => true,
            'order'   => 'time',
        ],
        'math_js3_aichi_daimon1' => [
            'label'        => '愛知県 大問1 本番セット',
            'question_key' => 'set',
            'ranking'      => false,
            'order'        => 'recent',
            'total'        => 10,
            'miss_label'   => '得点',
            'precision'    => 'sec',
        ],
    ];
}

// 台帳の1行を既定値で埋めて返す（台帳に無い unit_key なら null）
function time_unit_conf(string $unitKey): ?array
{
    $conf = time_units()[$unitKey] ?? null;
    if ($conf === null) return null;
    return $conf + [
        'label'        => $unitKey,
        'question_key' => null,
        'ranking'      => false,
        'order'        => 'time',
        'total'        => null,
        'miss_label'   => 'ミス',
        'precision'    => 'ms',
    ];
}

// 速さランキングに出す単元だけ（unit_key => 表示名）。講師ページのタブ用。
function time_rank_units(): array
{
    $out = [];
    foreach (time_units() as $k => $v) {
        if (!empty($v['ranking'])) $out[$k] = $v['label'];
    }
    return $out;
}

// time_records テーブルが存在するか（1回だけ確認してキャッシュ）
function time_records_available(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$pdo->query("SHOW TABLES LIKE 'time_records'")->fetchColumn();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

// ミリ秒を m:ss.s 形式へ（ツール側 JS の fmt() と同じ表記）
function fmt_time_ms(int $ms): string
{
    if ($ms < 0) $ms = 0;
    $s = $ms / 1000;
    $m = intdiv((int)floor($s), 60);
    $rem = $s - $m * 60;                 // 0〜59.9…
    $remStr = number_format($rem, 1, '.', '');
    if ($rem < 10) $remStr = '0' . $remStr;   // 1桁台は先頭0詰め（例 03.2）
    return $m . ':' . $remStr;
}

// 台帳の precision に合わせてタイムを整形する。
// 'sec'（愛知 大問1 のように秒単位でしか測っていない単元）は「8分12秒」、
// 既定の 'ms'（100マス）は fmt_time_ms と同じ「1:03.4」。
// 小数第1位が常に .0 になる単元で「8:12.0」と出すのを避けるための分岐。
function fmt_time_unit(int $ms, string $unitKey): string
{
    $conf = time_unit_conf($unitKey);
    if ($conf !== null && ($conf['precision'] ?? 'ms') === 'sec') {
        if ($ms < 0) $ms = 0;
        $s = intdiv($ms, 1000);
        return intdiv($s, 60) . '分' . ($s % 60) . '秒';
    }
    return fmt_time_ms($ms);
}

// 台帳の question_key を WHERE 句に足す（台帳に question_key が無い単元は素通り）。
// 同じ単元で種目違いのタイムを別々に記録している場合に混ざらないようにするための絞り込み。
function time_qk_where(string $unitKey, array &$params, string $col = 'question_key'): string
{
    $conf = time_unit_conf($unitKey);
    if ($conf === null || $conf['question_key'] === null) return '';
    $params['qk'] = $conf['question_key'];
    return " AND {$col} = :qk";
}

// 指定生徒のタイム記録を返す。台帳の order が 'recent' なら新しい順、
// 既定（'time'）なら速い順。講師詳細・マイページ共用。
function time_records_top(PDO $pdo, int $studentId, string $unitKey, int $limit = 10): array
{
    if (!time_records_available($pdo)) return [];
    $limit = max(1, min(50, $limit));
    $conf = time_unit_conf($unitKey);
    $order = ($conf !== null && $conf['order'] === 'recent')
        ? 'created_at DESC, time_ms ASC'
        : 'time_ms ASC, created_at ASC';
    $params = ['sid' => $studentId, 'uk' => $unitKey];
    $wq = time_qk_where($unitKey, $params);
    $stmt = $pdo->prepare(
        "SELECT time_ms, miss_count, meta, created_at
         FROM time_records
         WHERE student_id = :sid AND unit_key = :uk{$wq}
         ORDER BY {$order}
         LIMIT {$limit}"
    );
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'time_ms'    => (int)$row['time_ms'],
            'miss_count' => (int)$row['miss_count'],
            'meta'       => $row['meta'] !== null ? json_decode($row['meta'], true) : null,
            'created_at' => $row['created_at'],
        ];
    }
    return $out;
}

// 指定生徒の集計（ベストタイム・プレイ回数・最終プレイ日時）。期間フィルタ対応。
function time_records_summary(PDO $pdo, int $studentId, string $unitKey, ?string $fromStr, ?string $toStr): array
{
    $empty = ['best' => null, 'plays' => 0, 'last_at' => null];
    if (!time_records_available($pdo)) return $empty;
    $params = ['sid' => $studentId, 'uk' => $unitKey];
    $wq = time_qk_where($unitKey, $params);
    $w = '';
    if ($fromStr !== null) {
        $params['from'] = $fromStr;
        $params['to']   = $toStr;
        $w = ' AND created_at >= :from AND created_at < :to';
    }
    $stmt = $pdo->prepare(
        "SELECT MIN(time_ms) AS best, COUNT(*) AS plays, MAX(created_at) AS last_at
         FROM time_records WHERE student_id = :sid AND unit_key = :uk{$wq}{$w}"
    );
    $stmt->execute($params);
    $r = $stmt->fetch();
    return [
        'best'    => ($r && $r['best'] !== null) ? (int)$r['best'] : null,
        'plays'   => $r ? (int)$r['plays'] : 0,
        'last_at' => $r ? $r['last_at'] : null,
    ];
}

// 教室内（または混合）の生徒ごとのベストタイムを集計し、速い順に順位付けして返す。
// 実績のない生徒は載せない。$classroomIds が null なら全教室混合。
// 各行に best_ms / plays / rank（同タイム=同順位）を付ける。
function time_ranking_rows(PDO $pdo, ?array $classroomIds, string $unitKey, ?string $fromStr, ?string $toStr, bool $includeTest = false): array
{
    if (!time_records_available($pdo)) return [];

    $wc = '';
    if ($classroomIds !== null) {
        $classroomIds = array_values(array_filter(array_map('intval', $classroomIds), fn($v) => $v > 0));
        if (count($classroomIds) === 0) return [];
        $wc = ' AND s.classroom_id IN (' . implode(',', $classroomIds) . ')';
    }
    // テスト生（名前に「テスト」を含む）は既定で除外
    $wTest = $includeTest ? '' : " AND s.student_name NOT LIKE '%テスト%'";

    $params = ['uk' => $unitKey];
    $wt = '';
    if ($fromStr !== null) {
        $params['from'] = $fromStr;
        $params['to']   = $toStr;
        $wt = ' AND tr.created_at >= :from AND tr.created_at < :to';
    }
    $wt .= time_qk_where($unitKey, $params, 'tr.question_key');

    $stmt = $pdo->prepare(
        "SELECT s.student_id, s.student_name, s.grade, s.classroom_id, c.classroom_name,
                MIN(tr.time_ms) AS best_ms, COUNT(*) AS plays, MAX(tr.created_at) AS last_at
         FROM students s
         JOIN classrooms c ON c.classroom_id = s.classroom_id
         JOIN time_records tr ON tr.student_id = s.student_id AND tr.unit_key = :uk{$wt}
         WHERE s.is_active = 1{$wc}{$wTest}
         GROUP BY s.student_id, s.student_name, s.grade, s.classroom_id, c.classroom_name
         ORDER BY best_ms ASC, plays DESC, last_at ASC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // 順位付け（同タイム=同順位）
    $rank = 0;
    $prev = null;
    foreach ($rows as $i => &$r) {
        $r['best_ms'] = (int)$r['best_ms'];
        $r['plays']   = (int)$r['plays'];
        if ($prev === null || $r['best_ms'] > $prev) {
            $rank = $i + 1;
            $prev = $r['best_ms'];
        }
        $r['rank'] = $rank;
    }
    unset($r);
    return $rows;
}
