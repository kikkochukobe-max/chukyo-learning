<?php
declare(strict_types=1);

// 語彙クロスワード（unit_key = japanese_goi_crossword）の作問API。
// 画面は /vocab_admin.php（teacher.php と同じ立ち位置の講師ページ）。
//
// すべて POST（JSONボディ）で { action:"…", … }。
//   action=list       { level?, category?, q?, limit?, offset? } → 語一覧（ヒント件数つき）
//   action=get        { word_id }                                → 語1件＋ヒント配列
//   action=create     { yomi, hyoki?, gloss, level, category?, hints?[] }
//   action=update     { word_id, yomi?, hyoki?, gloss?, level?, category?, is_active? }
//   action=delete     { word_id }                                → 語を削除（ヒントもFK CASCADE）
//   action=save_hints { word_id, hints:[{step,type,body}, …] }   → ヒントを総入れ替え
//
// 権限: 閲覧(list/get)は全講師。作成・更新・削除は super_admin / classroom_admin
//       （admin.php と同じ切り方。teacher ロールは閲覧のみ）。
//
// ⚠ 頭文字ヒント(hint_type=firstchar / step=9)はDBに持つが手入力させない。
//   読みから機械的に作れるので、保存のたびにこちらで作り直す
//   （seed の INSERT … SELECT と同じ文言。先生が消しても復活する）。

require_once __DIR__ . '/bootstrap.php';

require_post();
$actor = require_login(['teacher']);
$pdo = db();

$stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $actor['id']]);
$role = (string)$stmt->fetchColumn();
$canEdit = in_array($role, ['super_admin', 'classroom_admin'], true);

$in = json_input();
$action = (string)($in['action'] ?? '');

const VOCAB_LEVELS = [1, 2, 3, 4, 5, 6];
const VOCAB_CATEGORIES = ['daily', 'science', 'society', 'math', 'kokugo', 'hyoron', 'idiom', 'yojijukugo', ''];
// firstchar はこちらで自動生成するので、先生が選べる種類には入れない
const VOCAB_HINT_TYPES = ['example', 'synonym', 'antonym', 'free'];

function vocab_fail(string $msg, int $status = 400): void
{
    json_response(['ok' => false, 'error' => $msg], $status);
}

// マスに入る文字なので厳格に。カタカナ（小書き・濁点つき）と長音記号だけ許す
function vocab_is_katakana(string $s): bool
{
    return $s !== '' && preg_match('/\A[\x{30A1}-\x{30F6}\x{30FC}]+\z/u', $s) === 1;
}

function vocab_len(string $s): int
{
    return mb_strlen($s, 'UTF-8');
}

// 読みの重複で弾かれたときの案内文。同音異義語（保証／保障／補償）を通せるかどうかは
// UNIQUE キーが旧 (level, yomi) のままか新 (level, yomi, hyoki) かで変わるので、
// 実際のキーを見てから文言を決める（db/migrations/migrate_vocab_homonym.sql が
// 未適用の本番がありうる。「表記を変えれば通ります」と案内して通らないのが一番困る）。
function vocab_dup_message(PDO $pdo, ?string $hyoki): string
{
    $legacy = false;
    try {
        $legacy = (bool)$pdo->query("SHOW INDEX FROM vocab_words WHERE Key_name = 'uniq_level_yomi'")->fetch();
    } catch (Throwable $e) {
        // キーを見られなくても案内自体は返す
    }
    if ($legacy) {
        return 'このレベルに同じ読みの語がすでにあります'
             . '（同音異義語を登録するには db/migrations/migrate_vocab_homonym.sql を実行してください）';
    }
    if ($hyoki === '') {
        return 'このレベルに同じ読みの語がすでにあります。同音異義語なら漢字表記を入れて区別してください';
    }
    return 'このレベルに同じ読み・同じ漢字表記の語がすでにあります';
}

// 頭文字ヒントを読みから作り直す（step=9 固定＝いちばん最後に出る）
function vocab_sync_firstchar(PDO $pdo, int $wordId): void
{
    $pdo->prepare("DELETE FROM vocab_hints WHERE word_id = ? AND hint_type = 'firstchar'")
        ->execute([$wordId]);
    $stmt = $pdo->prepare('SELECT yomi FROM vocab_words WHERE word_id = ?');
    $stmt->execute([$wordId]);
    $yomi = (string)$stmt->fetchColumn();
    if ($yomi === '') {
        return;
    }
    $pdo->prepare("INSERT INTO vocab_hints (word_id, step, hint_type, body) VALUES (?, 9, 'firstchar', ?)")
        ->execute([$wordId, '頭文字は「' . mb_substr($yomi, 0, 1, 'UTF-8') . '」']);
}

// 意味ヒントを全消し→入れ直し。step は渡された順に 1,2,3… で振り直す
function vocab_save_hints(PDO $pdo, int $wordId, array $hints): void
{
    $pdo->prepare("DELETE FROM vocab_hints WHERE word_id = ? AND hint_type <> 'firstchar'")
        ->execute([$wordId]);
    $ins = $pdo->prepare('INSERT INTO vocab_hints (word_id, step, hint_type, body) VALUES (?, ?, ?, ?)');
    $step = 0;
    foreach ($hints as $h) {
        if (!is_array($h)) {
            continue;
        }
        $body = trim((string)($h['body'] ?? ''));
        if ($body === '') {
            continue;
        }
        $type = (string)($h['type'] ?? $h['hint_type'] ?? 'free');
        if (!in_array($type, VOCAB_HINT_TYPES, true)) {
            $type = 'free';
        }
        $step++;
        if ($step > 8) {   // step=9 は頭文字ヒント専用
            break;
        }
        $ins->execute([$wordId, $step, $type, mb_substr($body, 0, 255, 'UTF-8')]);
    }
    vocab_sync_firstchar($pdo, $wordId);
}

switch ($action) {

    // ---------------- 一覧 ----------------
    case 'list': {
        $where = ['1=1'];
        $args = [];
        if (isset($in['level']) && in_array((int)$in['level'], VOCAB_LEVELS, true)) {
            $where[] = 'w.level = ?';
            $args[] = (int)$in['level'];
        }
        if (!empty($in['category'])) {
            $where[] = 'w.category = ?';
            $args[] = (string)$in['category'];
        }
        if (!empty($in['q'])) {
            // yomi は濁点を区別するため utf8mb4_bin（uniq_level_yomi_hyoki のため）。
            // ただし検索窓は「にじ」「ニシ」と打っても見つかってほしいので、
            // 比較のときだけ utf8mb4_unicode_ci に寄せる（かな・濁点の揺れを吸収する）。
            // ※ UNIQUE 制約は列の照合順序で効くので、ここを緩めても重複判定は厳密なまま
            $where[] = '(w.yomi COLLATE utf8mb4_unicode_ci LIKE ? OR w.hyoki LIKE ? OR w.gloss LIKE ?)';
            $kw = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$in['q']) . '%';
            $args[] = $kw;
            $args[] = $kw;
            $args[] = $kw;
        }
        $limit = min(500, max(1, (int)($in['limit'] ?? 100)));
        $offset = max(0, (int)($in['offset'] ?? 0));
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT w.word_id, w.yomi, w.hyoki, w.gloss, w.level, w.category, w.length, w.is_active,
                    (SELECT COUNT(*) FROM vocab_hints h
                      WHERE h.word_id = w.word_id AND h.hint_type <> 'firstchar') AS hint_count
             FROM vocab_words w
             WHERE $whereSql
             ORDER BY w.level, w.word_id
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($args);
        $words = $stmt->fetchAll();

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM vocab_words w WHERE $whereSql");
        $cnt->execute($args);

        json_response(['ok' => true, 'can_edit' => $canEdit, 'total' => (int)$cnt->fetchColumn(), 'words' => $words]);
    }

    // ---------------- 1件取得（ヒント付き） ----------------
    case 'get': {
        $id = (int)($in['word_id'] ?? 0);
        if ($id <= 0) {
            vocab_fail('word_id required');
        }
        $stmt = $pdo->prepare('SELECT * FROM vocab_words WHERE word_id = ?');
        $stmt->execute([$id]);
        $word = $stmt->fetch();
        if (!$word) {
            vocab_fail('見つかりません', 404);
        }
        $stmt = $pdo->prepare(
            "SELECT hint_id, step, hint_type, body FROM vocab_hints
             WHERE word_id = ? AND hint_type <> 'firstchar' ORDER BY step"
        );
        $stmt->execute([$id]);
        $word['hints'] = $stmt->fetchAll();
        json_response(['ok' => true, 'can_edit' => $canEdit, 'word' => $word]);
    }

    // ---------------- 新規作成 ----------------
    case 'create': {
        if (!$canEdit) {
            vocab_fail('この操作の権限がありません', 403);
        }
        $yomi = trim((string)($in['yomi'] ?? ''));
        $hyoki = trim((string)($in['hyoki'] ?? ''));
        $gloss = trim((string)($in['gloss'] ?? ''));
        $level = (int)($in['level'] ?? 0);
        $cat = (string)($in['category'] ?? '');

        if (!vocab_is_katakana($yomi)) {
            vocab_fail('読みはカタカナで入力してください');
        }
        if (vocab_len($yomi) < 2 || vocab_len($yomi) > 6) {
            vocab_fail('読みは2〜6文字（クロスワードの盤面に載る長さ）');
        }
        if ($gloss === '') {
            vocab_fail('語釈は必須です');
        }
        if (!in_array($level, VOCAB_LEVELS, true)) {
            vocab_fail('レベルが不正です');
        }
        if (!in_array($cat, VOCAB_CATEGORIES, true)) {
            $cat = '';
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO vocab_words (yomi, hyoki, gloss, level, category, length, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            // 同一レベル内の重複は UNIQUE キー uniq_level_yomi_hyoki（読み＋漢字表記）で弾かれる。
            // 表記が違えば同音異義語として登録できる＝ホショウ（保証／保障／補償）が並べられる
            $ins->execute([
                $yomi, mb_substr($hyoki, 0, 16, 'UTF-8'), mb_substr($gloss, 0, 255, 'UTF-8'),
                $level, $cat, vocab_len($yomi), (int)$actor['id'],
            ]);
            $wid = (int)$pdo->lastInsertId();
            vocab_save_hints($pdo, $wid, is_array($in['hints'] ?? null) ? $in['hints'] : []);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            if ($e instanceof PDOException && $e->getCode() === '23000') {
                vocab_fail(vocab_dup_message($pdo, mb_substr($hyoki, 0, 16, 'UTF-8')), 409);
            }
            throw $e;
        }
        json_response(['ok' => true, 'word_id' => $wid]);
    }

    // ---------------- 更新 ----------------
    case 'update': {
        if (!$canEdit) {
            vocab_fail('この操作の権限がありません', 403);
        }
        $id = (int)($in['word_id'] ?? 0);
        if ($id <= 0) {
            vocab_fail('word_id required');
        }

        $set = [];
        $args = [];
        $yomiChanged = false;
        if (isset($in['yomi'])) {
            $yomi = trim((string)$in['yomi']);
            if (!vocab_is_katakana($yomi) || vocab_len($yomi) < 2 || vocab_len($yomi) > 6) {
                vocab_fail('読みはカタカナ2〜6文字で入力してください');
            }
            $set[] = 'yomi = ?';
            $args[] = $yomi;
            $set[] = 'length = ?';
            $args[] = vocab_len($yomi);
            $yomiChanged = true;
        }
        if (isset($in['hyoki'])) {
            $set[] = 'hyoki = ?';
            $args[] = mb_substr(trim((string)$in['hyoki']), 0, 16, 'UTF-8');
        }
        if (isset($in['gloss'])) {
            $g = trim((string)$in['gloss']);
            if ($g === '') {
                vocab_fail('語釈は空にできません');
            }
            $set[] = 'gloss = ?';
            $args[] = mb_substr($g, 0, 255, 'UTF-8');
        }
        if (isset($in['level']) && in_array((int)$in['level'], VOCAB_LEVELS, true)) {
            $set[] = 'level = ?';
            $args[] = (int)$in['level'];
        }
        if (isset($in['category'])) {
            $c = (string)$in['category'];
            if (!in_array($c, VOCAB_CATEGORIES, true)) {
                $c = '';
            }
            $set[] = 'category = ?';
            $args[] = $c;
        }
        if (isset($in['is_active'])) {
            $set[] = 'is_active = ?';
            $args[] = $in['is_active'] ? 1 : 0;
        }
        if (!$set) {
            vocab_fail('更新する項目がありません');
        }

        $args[] = $id;
        try {
            $stmt = $pdo->prepare('UPDATE vocab_words SET ' . implode(', ', $set) . ' WHERE word_id = ?');
            $stmt->execute($args);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                vocab_fail(vocab_dup_message($pdo, isset($in['hyoki']) ? trim((string)$in['hyoki']) : null), 409);
            }
            throw $e;
        }
        // 読みが変われば頭文字ヒントも作り直す（放っておくと前の頭文字が出てしまう）
        if ($yomiChanged) {
            vocab_sync_firstchar($pdo, $id);
        }
        json_response(['ok' => true]);
    }

    // ---------------- 削除 ----------------
    case 'delete': {
        if (!$canEdit) {
            vocab_fail('この操作の権限がありません', 403);
        }
        $id = (int)($in['word_id'] ?? 0);
        if ($id <= 0) {
            vocab_fail('word_id required');
        }
        // vocab_hints は FK の ON DELETE CASCADE で一緒に消える。
        // answer_logs / retry_queue には word_id を JSON で持っているだけなので影響なし
        // （消した語は解き直しに出なくなるだけ）
        $stmt = $pdo->prepare('DELETE FROM vocab_words WHERE word_id = ?');
        $stmt->execute([$id]);
        json_response(['ok' => true, 'deleted' => $stmt->rowCount()]);
    }

    // ---------------- ヒント総入れ替え ----------------
    case 'save_hints': {
        if (!$canEdit) {
            vocab_fail('この操作の権限がありません', 403);
        }
        $id = (int)($in['word_id'] ?? 0);
        if ($id <= 0) {
            vocab_fail('word_id required');
        }
        $stmt = $pdo->prepare('SELECT 1 FROM vocab_words WHERE word_id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) {
            vocab_fail('語が見つかりません', 404);
        }
        $pdo->beginTransaction();
        try {
            vocab_save_hints($pdo, $id, is_array($in['hints'] ?? null) ? $in['hints'] : []);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        json_response(['ok' => true]);
    }

    default:
        vocab_fail('unknown_action');
}
