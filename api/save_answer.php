<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const DAILY_XP_CAP = 300;
const DEFAULT_BASE_XP = 1;   // question_catalog 未登録の (unit_key, question_key) に与える既定XP
const XP_DECAY_RATE = 0.7;   // 同一問題を当日くり返し正解した時の1回ごとの減衰率(spec §3)

require_post();
$actor = require_login(['student']);
$studentId = $actor['id'];

$input = json_input();
$unitKey = (string)($input['unit_key'] ?? '');
$questionKey = (string)($input['question_key'] ?? '');

if (!preg_match('/^[a-z0-9_]{1,64}$/i', $unitKey) || !preg_match('/^[a-zA-Z0-9_]{1,128}$/', $questionKey)) {
    json_response(['ok' => false, 'error' => 'invalid_request'], 400);
}
if (!array_key_exists('is_correct', $input)) {
    json_response(['ok' => false, 'error' => 'invalid_request'], 400);
}

$isCorrect = (bool)$input['is_correct'];
$questionParams = $input['question_params'] ?? null;
if ($questionParams !== null && !is_array($questionParams)) {
    json_response(['ok' => false, 'error' => 'invalid_request'], 400);
}
$questionText = isset($input['question_text']) ? substr((string)$input['question_text'], 0, 255) : null;
$correctAnswer = isset($input['correct_answer']) ? substr((string)$input['correct_answer'], 0, 100) : null;
$studentAnswer = isset($input['student_answer']) ? substr((string)$input['student_answer'], 0, 100) : null;
$retryOf = isset($input['retry_of']) ? (int)$input['retry_of'] : null;
$timeTakenSec = isset($input['time_taken_sec']) ? (int)$input['time_taken_sec'] : null;
$hash = params_hash($questionParams);

/* 問題の図（SVG/表のHTML）。図が無いと紙で解き直せない問題のためにそのまま保存する。
   解き直しプリントにしか使わないので、誤答のときだけ保存する（正解分は容量の無駄）。

   ⚠ これは講師ページの解き直しプリントに生HTMLとして描画される。クライアントから
     来る文字列なので、そのまま信じると講師画面に対する保存型XSSになる。
     「掃除する」のではなく「想定どおりでなければ丸ごと捨てる」方針にしている
     （ツールが機械生成する図は形が決まっているので、検証で落ちる＝異常）。
     新しい図でタグ・属性を増やしたら、このホワイトリストにも足すこと。
     足し忘れると図が保存されないだけで、記録自体は普通に残る。 */
const FIG_MAX_BYTES = 20000;
// タグ名は小文字で比較するので linearGradient は 'lineargradient' で持つ
const FIG_TAGS = ['svg','g','line','polyline','polygon','rect','circle','ellipse','path','text','tspan',
                  'defs','lineargradient','radialgradient','stop',
                  'table','thead','tbody','tr','th','td','caption','div','span','br',
                  // 表の中の強調（理科のセキツイ動物分類表は空欄を <b>？</b> で示す）・化学式の添字
                  'b','strong','i','em','u','small','sub','sup'];
const FIG_ATTRS = ['x','y','x1','y1','x2','y2','cx','cy','r','rx','ry','d','points','width','height',
                   'viewbox','xmlns','preserveaspectratio','fill','stroke','stroke-width','stroke-dasharray',
                   'stroke-linecap','stroke-linejoin','font-size','font-family','font-weight','text-anchor',
                   'dominant-baseline','transform','opacity','fill-opacity','class','style','colspan','rowspan',
                   // グラデーション（理科のりん片の図など）
                   'id','offset','stop-color','stop-opacity','gradientunits','gradienttransform','spreadmethod',
                   // 読み上げ用。図の関数はほぼ全部 svg に付けているので、無いと図が丸ごと落ちる
                   'role','aria-label'];
function figure_is_safe(string $s): bool {
    if ($s === '' || strlen($s) > FIG_MAX_BYTES) return false;
    if (preg_match('/<!--|<!\[|<\?/', $s)) return false;   // コメント・CDATA・処理命令
    // 検査はタグの中だけで行う。テキスト側には「∠A=90°」「AD:DB=2:3」のように
    // = や : が普通に出るので、文字列全体に正規表現をかけると本物の図を誤って弾く。
    if (!preg_match_all('/<[^>]*>/', $s, $tags)) return false;   // タグが1つも無いのは想定外
    foreach ($tags[0] as $tag) {
        if (!preg_match('#^<\s*/?\s*([a-zA-Z][a-zA-Z0-9-]*)#', $tag, $m)) return false;
        if (!in_array(strtolower($m[1]), FIG_TAGS, true)) return false;
        $inner = substr($tag, strlen($m[0]));          // タグ名の後ろ
        $inner = preg_replace('#/?\s*>$#', '', $inner); // 閉じ括弧（自己終了含む）を落とす
        $inner = trim((string)$inner);
        // 残りは name="value" の並びだけを許す（引用なし・シングルクォートは通さない）
        while ($inner !== '') {
            if (!preg_match('/^([a-zA-Z_:][a-zA-Z0-9_.:-]*)\s*=\s*"([^"]*)"\s*/', $inner, $a)) return false;
            if (!in_array(strtolower($a[1]), FIG_ATTRS, true)) return false;   // on* はここで落ちる
            // fill="url(#rpA)" のような「同じ図の中の defs への参照」だけは通す。
            // 外部を読みに行く url(...) は通さないので、値ぜんぶが #識別子 の形かを見る。
            $localRef = (bool)preg_match('/^url\(#[A-Za-z][A-Za-z0-9_.:-]*\)$/', $a[2]);
            if (!$localRef && preg_match('/javascript:|vbscript:|data:|expression\s*\(|url\s*\(|&#/i', $a[2])) return false;
            $inner = substr($inner, strlen($a[0]));
        }
    }
    // タグ抽出をすり抜けた生の "<" がテキスト側に残っていたら想定外
    if (strpos((string)preg_replace('/<[^>]*>/', '', $s), '<') !== false) return false;
    // 注: テキスト側の &#nn; は許している。実体参照はブラウザが文字に戻すだけで
    //     マークアップとして読み直されないため無害（危険なのは属性値側で、そちらは上で弾く）。
    return true;
}
$questionFigure = null;
if (!$isCorrect && isset($input['question_figure'])) {
    $fig = trim((string)$input['question_figure']);
    if (figure_is_safe($fig)) $questionFigure = $fig;
}

/* 選択肢。「正しく述べたものを選ぶ」「4つの図から選ぶ」型は問題文と図だけでは
   紙で解き直せないので、その種類のツールだけが送ってくる。
   形は [{t:'tex'|'svg', v:'…'}, …]。tex は講師ページ側で renderMathToHTML を通す
   （question_text と同じ扱い＝最終的に _mescape でエスケープされる）ので、
   ここでは長さと「生の < が無いこと」だけ見る。svg は図と同じ検証にかける。 */
const CHOICES_MAX = 8;          // ア〜カ想定。これを超えるのは想定外
const CHOICE_TEX_MAX = 300;
$questionChoices = null;
if (!$isCorrect && isset($input['question_choices']) && is_array($input['question_choices'])) {
    $src = $input['question_choices'];
    $out = [];
    $okAll = count($src) >= 2 && count($src) <= CHOICES_MAX;
    foreach ($src as $c) {
        if (!$okAll) break;
        if (!is_array($c) || !isset($c['t'], $c['v'])) { $okAll = false; break; }
        $t = (string)$c['t'];
        $v = (string)$c['v'];
        if ($t === 'tex') {
            if (strlen($v) > CHOICE_TEX_MAX || strpos($v, '<') !== false) { $okAll = false; break; }
        } elseif ($t === 'svg') {
            if (!figure_is_safe($v)) { $okAll = false; break; }
        } else { $okAll = false; break; }
        $out[] = ['t' => $t, 'v' => $v];
    }
    // 1つでも怪しければ選択肢ごと捨てる（虫食いで刷るより無い方がまし）
    if ($okAll && $out) $questionChoices = json_encode($out, JSON_UNESCAPED_UNICODE);
}

/* 解き直し用の「問題そのもの」(question_replay)。
   question_params から同じ問題を作り直せないツール（生成関数が20種類以上ある
   愛知県公立入試 大問1 など）のために、画面に出した問題を丸ごと保存しておき、
   ?retry=1 では「復元」ではなく「再表示」で同一問題を出す。
   保存先は retry_queue.replay_json（誤答1問=1行なので、同じ問題を何回
   まちがえても1行で済む。answer_logs に入れると1解答ごとに図が複製される）。

   ⚠ tableHtml と choices の svg はツール側で innerHTML に入るので、
     図と同じ「想定どおりでなければ丸ごと捨てる」方針で検証する。
     tex/text は KaTeX か textContent で描くので、生の < が無いことと長さだけ見る。 */
// 上限はすべて「バイト数」。日本語は1文字3バイトなので、問題文3000バイト≒1000字。
// 図(SVG)入りの選択肢が4つある種類（箱ひげ図・関数のグラフ）でも収まるように取る。
// ⚠ 1つでも上限を超えると replay を丸ごと捨てる＝その問題だけ解き直せなくなる
//   （記録自体は普通に残るので気づきにくい。ケチらず余裕を持たせる）
const REPLAY_MAX_BYTES  = 80000;
const REPLAY_TEXT_MAX   = 3000;
const REPLAY_CHOICE_MAX = 1000;
const REPLAY_PARTS_MAX  = 40;
const REPLAY_EXPL_MAX   = 12;
const REPLAY_KEYS = ['key', 'typeId', 'multi', 'correct', 'parts', 'choices', 'expl', 'tableHtml'];
// KaTeX(ktx) か textContent(setVarText) で描かれる文字列。innerHTML には入らない。
// 数学の文字列には生の < > が普通に出るので（不等式の立式の選択肢 "3a+2b<1200"、
// 解説の "x<y のものは"）、< を丸ごと禁止すると その種類だけ静かに解き直せなくなる。
// タグとして解釈されうる形だけを弾く: 閉じタグ・コメント・処理命令、および
// 「< の直後が英字」かつ「同じ文字列に > がある」もの（＝タグが閉じられる形）。
// 万一これで本物の数式を弾いても、落ちるのは replay だけで記録は普通に残る。
function replay_text_ok($v, int $max = REPLAY_TEXT_MAX): bool {
    if (!is_string($v) || strlen($v) > $max) return false;
    if (preg_match('#<\s*[/!?]#', $v)) return false;
    if (preg_match('#<\s*[a-zA-Z]#', $v) && strpos($v, '>') !== false) return false;
    return true;
}
function replay_is_safe($r): bool {
    if (!is_array($r) || $r === []) return false;
    foreach (array_keys($r) as $k) {
        if (!in_array($k, REPLAY_KEYS, true)) return false;   // 想定外のキー = 異常
    }
    // key / typeId（question_key と同じ字種）
    foreach (['key', 'typeId'] as $k) {
        if (!isset($r[$k]) || !is_string($r[$k]) || !preg_match('/^[a-zA-Z0-9_]{1,128}$/', $r[$k])) return false;
    }
    // 問題文
    if (!isset($r['parts']) || !is_array($r['parts']) || $r['parts'] === []
        || count($r['parts']) > REPLAY_PARTS_MAX) return false;
    foreach ($r['parts'] as $p) {
        if (!is_array($p) || !isset($p['t']) || !array_key_exists('v', $p)) return false;
        // 'txt' はツール側 txtPart() の値。'text' も将来のツール用に許す（どちらも textContent 描画）
        if (!in_array($p['t'], ['tex', 'txt', 'text'], true)) return false;
        if (!replay_text_ok($p['v'])) return false;
    }
    // 選択肢（文字列=tex、または ['svg'=>'<svg…>']）
    if (!isset($r['choices']) || !is_array($r['choices'])
        || count($r['choices']) < 2 || count($r['choices']) > CHOICES_MAX) return false;
    foreach ($r['choices'] as $c) {
        if (is_string($c)) {
            if (!replay_text_ok($c, REPLAY_CHOICE_MAX)) return false;
        } elseif (is_array($c) && array_keys($c) === ['svg']) {
            if (!is_string($c['svg']) || !figure_is_safe($c['svg'])) return false;
        } else {
            return false;
        }
    }
    // 正解の番号（選択肢の範囲内）と、マークする個数
    $multi = isset($r['multi']) ? $r['multi'] : 1;
    if ($multi !== 1 && $multi !== 2) return false;
    if (!isset($r['correct']) || !is_array($r['correct']) || count($r['correct']) !== $multi) return false;
    foreach ($r['correct'] as $i) {
        if (!is_int($i) || $i < 0 || $i >= count($r['choices'])) return false;
    }
    // 解説（任意）
    if (isset($r['expl'])) {
        if (!is_array($r['expl']) || count($r['expl']) > REPLAY_EXPL_MAX) return false;
        foreach ($r['expl'] as $line) {
            if (!replay_text_ok($line)) return false;
        }
    }
    // 図（任意）
    if (isset($r['tableHtml']) && $r['tableHtml'] !== null && $r['tableHtml'] !== '') {
        if (!is_string($r['tableHtml']) || !figure_is_safe($r['tableHtml'])) return false;
    }
    return true;
}
$questionReplay = null;
if (!$isCorrect && isset($input['question_replay']) && is_array($input['question_replay'])) {
    if (replay_is_safe($input['question_replay'])) {
        $json = json_encode($input['question_replay'], JSON_UNESCAPED_UNICODE);
        if (is_string($json) && strlen($json) <= REPLAY_MAX_BYTES) $questionReplay = $json;
    }
}

// retry_queue.replay_json が存在するか（migrate_retry_replay.sql 未適用でも動くように）。
// 未適用の環境で列名を書いたSQLを投げると誤答のたびに500になるので、先に確認する。
function retry_replay_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$pdo->query("SHOW COLUMNS FROM retry_queue LIKE 'replay_json'")->fetchColumn();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

$pdo = db();

// クライアントの申告する session_id は必ず本人所有かを確認してから使う（他人のセッションへの書き込み防止）
// ※ended_at は「最後に活動した時刻」の意味で使う（NULLチェックはしない）
$sessionId = null;
if (!empty($input['session_id'])) {
    $stmt = $pdo->prepare('SELECT session_id FROM study_sessions WHERE session_id = :id AND student_id = :sid');
    $stmt->execute(['id' => (int)$input['session_id'], 'sid' => $studentId]);
    if ($stmt->fetchColumn()) {
        $sessionId = (int)$input['session_id'];
    }
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO answer_logs
            (session_id, student_id, unit_key, question_key, question_params, params_hash,
             question_text, question_figure, question_choices, correct_answer, student_answer,
             is_correct, retry_of, time_taken_sec)
         VALUES
            (:session_id, :student_id, :unit_key, :question_key, :question_params, :params_hash,
             :question_text, :question_figure, :question_choices, :correct_answer, :student_answer,
             :is_correct, :retry_of, :time_taken_sec)'
    );
    $stmt->execute([
        'session_id'      => $sessionId,
        'student_id'      => $studentId,
        'unit_key'        => $unitKey,
        'question_key'    => $questionKey,
        'question_params' => $questionParams !== null ? json_encode($questionParams, JSON_UNESCAPED_UNICODE) : null,
        'params_hash'     => $hash,
        'question_text'   => $questionText,
        'question_figure' => $questionFigure,
        'question_choices' => $questionChoices,
        'correct_answer'  => $correctAnswer,
        'student_answer'  => $studentAnswer,
        'is_correct'      => $isCorrect ? 1 : 0,
        'retry_of'        => $retryOf,
        'time_taken_sec'  => $timeTakenSec,
    ]);
    $answerId = (int)$pdo->lastInsertId();

    if ($sessionId !== null) {
        // 学習時間は活動ベースの積算: 前回活動からの経過(上限5分)を加算していく
        $stmt = $pdo->prepare(
            'UPDATE study_sessions
             SET total_questions = total_questions + 1,
                 correct_count = correct_count + :inc,
                 duration_sec = COALESCE(duration_sec, 0)
                   + LEAST(TIMESTAMPDIFF(SECOND, COALESCE(ended_at, started_at), NOW()), 300),
                 ended_at = NOW()
             WHERE session_id = :id'
        );
        $stmt->execute(['inc' => $isCorrect ? 1 : 0, 'id' => $sessionId]);
    }

    // retry_queue: 誤答はキュー投入/更新、正答は連続正解数を進めて2連続でmastered
    $retryStatus = 'none';
    if (!$isCorrect) {
        // replay_json は列がある環境でだけ触る（migrate_retry_replay.sql 未適用でも壊れない）。
        // 既存行の replay_json は COALESCE で「新しい値があれば差し替え、無ければ現状維持」。
        // これで、列を足す前にたまった pending 行も、同じ問題をもう一度まちがえた
        // 時点で自然に埋まる。
        $withReplay = retry_replay_available($pdo);
        $cols   = $withReplay ? ', replay_json' : '';
        $vals   = $withReplay ? ', :replay_json' : '';
        $update = $withReplay ? ', replay_json = COALESCE(VALUES(replay_json), replay_json)' : '';
        $stmt = $pdo->prepare(
            "INSERT INTO retry_queue (student_id, unit_key, question_key, question_params{$cols}, params_hash, wrong_count, correct_streak, status, last_answered_at)
             VALUES (:student_id, :unit_key, :question_key, :question_params{$vals}, :params_hash, 1, 0, \"pending\", NOW())
             ON DUPLICATE KEY UPDATE
                wrong_count = wrong_count + 1,
                correct_streak = 0,
                status = \"pending\",
                question_params = VALUES(question_params){$update},
                last_answered_at = NOW()"
        );
        $args = [
            'student_id'      => $studentId,
            'unit_key'        => $unitKey,
            'question_key'    => $questionKey,
            'question_params' => $questionParams !== null ? json_encode($questionParams, JSON_UNESCAPED_UNICODE) : null,
            'params_hash'     => $hash,
        ];
        if ($withReplay) $args['replay_json'] = $questionReplay;
        $stmt->execute($args);
        $retryStatus = 'pending';
    } else {
        $stmt = $pdo->prepare(
            'SELECT retry_id, correct_streak, status FROM retry_queue
             WHERE student_id = :student_id AND unit_key = :unit_key AND question_key = :question_key AND params_hash = :params_hash
             FOR UPDATE'
        );
        $stmt->execute([
            'student_id'   => $studentId,
            'unit_key'     => $unitKey,
            'question_key' => $questionKey,
            'params_hash'  => $hash,
        ]);
        $queueRow = $stmt->fetch();
        if ($queueRow) {
            if ($queueRow['status'] === 'pending') {
                $newStreak = (int)$queueRow['correct_streak'] + 1;
                $newStatus = $newStreak >= 2 ? 'mastered' : 'pending';
                $stmt = $pdo->prepare(
                    'UPDATE retry_queue SET correct_streak = :streak, status = :status, last_answered_at = NOW() WHERE retry_id = :id'
                );
                $stmt->execute(['streak' => $newStreak, 'status' => $newStatus, 'id' => $queueRow['retry_id']]);
                $retryStatus = $newStatus;
            } else {
                $retryStatus = 'mastered';
            }
        }
    }

    // XPは正解のみ。単価は日次バッチ(update_xp.php)が全生徒の正答率から動的に決める
    // current_xp を使い、NULL(バッチ未実行)なら base_xp にフォールバックする(spec §3)。
    // カタログ未登録でも既定1XPを付与する（全モードで必ずXPが入るように）。
    // 単価・変動の仕組みは生徒に一切見せない(シークレット運用。spec §4)。
    $xpAwarded = 0;
    if ($isCorrect) {
        $stmt = $pdo->prepare('SELECT base_xp, current_xp FROM question_catalog WHERE unit_key = :unit_key AND question_key = :question_key');
        $stmt->execute(['unit_key' => $unitKey, 'question_key' => $questionKey]);
        $catalog = $stmt->fetch();

        if ($catalog === false) {
            error_log("[save_answer] question_catalog未登録(既定XPで付与): unit_key={$unitKey} question_key={$questionKey}");
            $unitXp = DEFAULT_BASE_XP;
        } else {
            // current_xp が NULL なら base_xp を単価にする
            $unitXp = $catalog['current_xp'] !== null ? (int)$catalog['current_xp'] : (int)$catalog['base_xp'];
        }

        if ($unitXp > 0) {
            // イベント期間中は倍率を単価に上乗せする（既存のXPイベント機能を維持）
            $stmt = $pdo->prepare(
                'SELECT event_id, multiplier FROM xp_events
                 WHERE NOW() BETWEEN starts_at AND ends_at
                   AND (unit_key_prefix IS NULL OR :unit_key LIKE CONCAT(unit_key_prefix, "%"))
                 ORDER BY multiplier DESC LIMIT 1'
            );
            $stmt->execute(['unit_key' => $unitKey]);
            $event = $stmt->fetch();
            $multiplier = $event ? (float)$event['multiplier'] : 1.0;
            $eventId = $event ? (int)$event['event_id'] : null;

            // 日次減衰: 今日この生徒がこの問題で正解してXPを得た回数 n を xp_logs から数える。
            // DATE()関数ではなく範囲比較(created_at >= CURDATE())でインデックスを効かせる(spec §3)。
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM xp_logs
                 WHERE student_id = :student_id AND unit_key = :unit_key AND question_key = :question_key
                   AND created_at >= CURDATE()'
            );
            $stmt->execute([
                'student_id'   => $studentId,
                'unit_key'     => $unitKey,
                'question_key' => $questionKey,
            ]);
            $n = (int)$stmt->fetchColumn();

            // xp = max(1, round(単価 × 倍率 × 0.7^n))。下限1(解けば必ず何かもらえる)
            $computed = max(1, (int)round($unitXp * $multiplier * pow(XP_DECAY_RATE, $n)));

            // 1日の合計上限。cap に達していたら 0 になり付与しない
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(amount), 0) FROM xp_logs WHERE student_id = :student_id AND created_at >= CURDATE()'
            );
            $stmt->execute(['student_id' => $studentId]);
            $todayTotal = (int)$stmt->fetchColumn();
            $remaining = max(0, DAILY_XP_CAP - $todayTotal);

            $xpAwarded = min($computed, $remaining);

            if ($xpAwarded > 0) {
                $stmt = $pdo->prepare(
                    'INSERT INTO xp_logs (student_id, amount, reason, unit_key, question_key, event_id, answer_id)
                     VALUES (:student_id, :amount, "correct", :unit_key, :question_key, :event_id, :answer_id)'
                );
                $stmt->execute([
                    'student_id'   => $studentId,
                    'amount'       => $xpAwarded,
                    'unit_key'     => $unitKey,
                    'question_key' => $questionKey,
                    'event_id'     => $eventId,
                    'answer_id'    => $answerId,
                ]);
            }
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

json_response([
    'ok'           => true,
    'answer_id'    => $answerId,
    'params_hash'  => $hash,
    'retry_status' => $retryStatus,
    'xp_awarded'   => $xpAwarded,
]);
