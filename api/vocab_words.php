<?php
declare(strict_types=1);

// 語彙クロスワード（unit_key = japanese_goi_crossword）の出題プールを返す。
//
//   GET vocab_words.php?level=es_low
//       → そのレベルの有効語からランダムに limit 件（既定60）
//   GET vocab_words.php?level=es_low&mode=weak&limit=80
//       → ログイン中の生徒がまだ定着していない語を優先して返す（未ログインなら通常プール）
//   GET vocab_words.php?level=es_low&ids=12,34,56
//       → その word_id を必ず先頭に入れる（?retry=1 の pending 語。
//          プールに入っていないと同じ語を出し直せないので、ランダム抽出とは別に足す）
//
// レスポンス: { ok:true, level:"es_low", mode:"pool"|"weak", words:[ … ] }
//   words[] = { id, w(=yomi/マスに入る読み), d(=漢字表記), c(=語釈/カギ本文),
//               cat, len, hints:[{step,type,body}, …] }
//
// ※ ログインは必須にしない。未ログイン・体験でも語彙は引ける（記録だけが残らない）。
//   mode=weak の個別最適化だけがログイン中の生徒に効く。

require_once __DIR__ . '/bootstrap.php';

const VOCAB_UNIT_KEY = 'japanese_goi_crossword';

// question_key（ツール側のレベル）→ vocab_words.level
const VOCAB_LEVEL_MAP = [
    'es_low'    => 1,
    'es_mid'    => 2,
    'es_high'   => 3,
    'js_easy'   => 4,
    'js_normal' => 5,
    'js_hard'   => 6,
];

$levelKey = (string)($_GET['level'] ?? '');
if (!isset(VOCAB_LEVEL_MAP[$levelKey])) {
    json_response(['ok' => false, 'error' => 'invalid_level'], 400);
}
$level = VOCAB_LEVEL_MAP[$levelKey];

$mode  = (string)($_GET['mode'] ?? 'pool');
$limit = (int)($_GET['limit'] ?? 60);
$limit = max(10, min(200, $limit));

// 必ず同梱する word_id（?retry=1 の pending 語）
$forceIds = [];
if (!empty($_GET['ids'])) {
    foreach (explode(',', (string)$_GET['ids']) as $raw) {
        $id = (int)trim($raw);
        if ($id > 0) {
            $forceIds[$id] = $id;
        }
    }
    $forceIds = array_slice(array_values($forceIds), 0, 60);
}

// ログイン中の生徒だけ mode=weak が効く。講師・保護者や未ログインは通常プール
$actor = current_actor();
$studentId = ($actor && $actor['type'] === 'student') ? (int)$actor['id'] : null;

$pdo = db();

$rows = [];

// ---- 1) 指定された word_id を先に取る（レベル違い・無効語は返さない） ----
if ($forceIds) {
    $ph = implode(',', array_fill(0, count($forceIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT word_id, yomi, hyoki, gloss, category, length
         FROM vocab_words
         WHERE word_id IN ($ph) AND level = ? AND is_active = 1
           AND length BETWEEN 2 AND 6"
    );
    $stmt->execute(array_merge($forceIds, [$level]));
    $rows = $stmt->fetchAll();
}

// ---- 2) 残りをプールから埋める ----
$remaining = $limit - count($rows);
if ($remaining > 0) {
    $exclude = array_column($rows, 'word_id');
    $excludeSql = '';
    $args = ['level' => $level];
    if ($exclude) {
        $ph = [];
        foreach ($exclude as $i => $id) {
            $ph[] = ':ex' . $i;
            $args['ex' . $i] = (int)$id;
        }
        $excludeSql = ' AND w.word_id NOT IN (' . implode(',', $ph) . ')';
    }

    // 通常プール（weak が使えない環境のフォールバックにも使う）
    $poolSql =
        "SELECT w.word_id, w.yomi, w.hyoki, w.gloss, w.category, w.length
         FROM vocab_words w
         WHERE w.level = :level AND w.is_active = 1
           AND w.length BETWEEN 2 AND 6" . $excludeSql . "
         ORDER BY RAND()
         LIMIT :lim";

    if ($mode === 'weak' && $studentId !== null) {
        // 個別出題: この生徒がこのレベルで「まだ正解できていない／よく間違える」語を優先。
        // answer_logs.question_params は {"word_id":123} の形で入っている（ツール側と一致）。
        // 未正解 → 誤答が多い → 触れたことがない、の順。
        // ※ '\$.word_id' の \$ は「PHPの変数展開ではなくJSONパス」の意（二重引用符の中）
        $sql =
            "SELECT w.word_id, w.yomi, w.hyoki, w.gloss, w.category, w.length
             FROM vocab_words w
             LEFT JOIN (
               SELECT JSON_UNQUOTE(JSON_EXTRACT(question_params, '\$.word_id')) AS wid,
                      SUM(is_correct = 1) AS n_correct,
                      SUM(is_correct = 0) AS n_wrong,
                      MAX(created_at)     AS last_answered
               FROM answer_logs
               WHERE unit_key = :unit_key AND student_id = :sid
               GROUP BY wid
             ) a ON a.wid = CAST(w.word_id AS CHAR)
             WHERE w.level = :level AND w.is_active = 1
               AND w.length BETWEEN 2 AND 6" . $excludeSql . "
             ORDER BY (COALESCE(a.n_correct,0) = 0) DESC,
                      COALESCE(a.n_wrong,0) DESC,
                      (a.last_answered IS NULL) DESC,
                      RAND()
             LIMIT :lim";
        $args['unit_key'] = VOCAB_UNIT_KEY;
        $args['sid'] = $studentId;
        $usedMode = 'weak';
    } else {
        $sql = $poolSql;
        $usedMode = 'pool';
    }

    $run = function (string $sql, array $args) use ($pdo, $remaining): array {
        $stmt = $pdo->prepare($sql);
        foreach ($args as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $remaining, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    };

    try {
        $rows = array_merge($rows, $run($sql, $args));
    } catch (Throwable $e) {
        // weak は JSON関数に依存する＝環境差で落ちうる。その時は普通のプールで出題を続ける
        // （個別最適化が効かないだけで、クロスワード自体は成立する）
        if ($usedMode !== 'weak') {
            throw $e;
        }
        error_log('[vocab_words] weak query failed, falling back to pool: ' . $e->getMessage());
        unset($args['unit_key'], $args['sid']);
        $usedMode = 'pool';
        $rows = array_merge($rows, $run($poolSql, $args));
    }
} else {
    $usedMode = ($mode === 'weak' && $studentId !== null) ? 'weak' : 'pool';
}

if (!$rows) {
    json_response(['ok' => true, 'level' => $levelKey, 'mode' => $usedMode, 'words' => []]);
}

// ---- 3) ヒントをまとめて取得（N+1回避） ----
$ids = array_map(static fn($r) => (int)$r['word_id'], $rows);
$ph = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare(
    "SELECT word_id, step, hint_type, body
     FROM vocab_hints
     WHERE word_id IN ($ph)
     ORDER BY word_id, step"
);
$stmt->execute($ids);
$hintsByWord = [];
foreach ($stmt->fetchAll() as $h) {
    $hintsByWord[(int)$h['word_id']][] = [
        'step' => (int)$h['step'],
        'type' => $h['hint_type'],
        'body' => $h['body'],
    ];
}

// ---- 4) ツール側が期待する形（w/d/c/cat/len/hints）に整形 ----
$words = [];
foreach ($rows as $r) {
    $wid = (int)$r['word_id'];
    $words[] = [
        'id'    => $wid,
        'w'     => $r['yomi'],
        'd'     => (string)($r['hyoki'] ?? ''),
        'c'     => $r['gloss'],
        'cat'   => (string)($r['category'] ?? ''),
        'len'   => (int)($r['length'] ?: mb_strlen($r['yomi'], 'UTF-8')),
        'hints' => $hintsByWord[$wid] ?? [],
    ];
}

json_response(['ok' => true, 'level' => $levelKey, 'mode' => $usedMode, 'words' => $words]);
