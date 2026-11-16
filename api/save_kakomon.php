<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/kakomon_common.php';

// 過去問の記録（私立・公立）。生徒本人だけが呼べる（講師は kakomon_teacher.php で読むだけ）。
//
// action（私立）:
//   pace          {pace: fast|normal|slow}                 全体の進み具合
//   add_school    {target_school_id} または {name}          購入した過去問（高校）を足す（3校まで）
//   remove_school {school_id}                               高校を外す（記録が1件でもあれば外せない）
//   save_log      {log_id?, school_id, year, subject, lap,
//                  score?, max_score?, rate?, good?, bad?}  1回ぶんの記録（log_id があれば修正）
//   delete_log    {log_id}                                  1回ぶんの記録を消す
// action（公立。愛知県公立高校入試＝高校は持たない）:
//   public_pace     {pace}                                   公立の全体の進み具合
//   public_add_school {target_school_id} または {name}       受ける公立高校を足す（2校まで）
//   public_remove_school {school_id}                         受ける公立高校を外す（記録とはひもづけないので、いつでも外せる）
//   public_save_log {log_id?, year, group, subject, lap,
//                    score, good?, bad?}                     group は 2022年度まで 'A'/'B'、以降は ''。score は 0〜22
//   public_delete_log {log_id}
//
// どの action も成功すると {ok:true, state:<kakomon_state>} を返す＝画面はそれで描き直す。
// XPは付けない（自己申告のため。自習の記録と同じ考え方）。

require_post();
$actor = require_login(['student']);
$studentId = (int)$actor['id'];
$pdo = db();

$input = json_input();
$action = (string)($input['action'] ?? '');

// 返り値の state は私立のテーブルから組むので、私立は常に要る。公立の action は公立のテーブルも要る
if (!kakomon_ready($pdo) || (strpos($action, 'public_') === 0 && !kakomon_public_ready($pdo))) {
    json_response(['ok' => false, 'error' => 'schema_not_ready'], 503);
}

function kk_done(PDO $pdo, int $studentId, array $extra = []): void
{
    json_response(['ok' => true, 'state' => kakomon_state($pdo, $studentId)] + $extra);
}

function kk_fail(string $error, int $status = 400): void
{
    json_response(['ok' => false, 'error' => $error], $status);
}

// 0以上の整数として読めるか（"72" / 72 / "72.0" は可。"" や "abc" や -1 は null）
function kk_uint($v): ?int
{
    if (is_int($v)) {
        return $v >= 0 ? $v : null;
    }
    if (is_float($v)) {
        // 1e19 のような巨大な値は int にすると桁あふれするので先に弾く
        return ($v >= 0 && $v <= 99999 && floor($v) === $v) ? (int)$v : null;
    }
    $s = trim((string)$v);
    return preg_match('/^\d{1,5}(\.0+)?$/', $s) ? (int)$s : null;
}

// この生徒の高校か（よその生徒の school_id を送られても書かない）
function kk_own_school(PDO $pdo, int $studentId, int $schoolId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM kakomon_schools WHERE kschool_id = :id AND student_id = :sid');
    $stmt->execute(['id' => $schoolId, 'sid' => $studentId]);
    return (int)$stmt->fetchColumn() > 0;
}

// ============================================================
// 全体の進み具合
// ============================================================
if ($action === 'pace') {
    $pace = (string)($input['pace'] ?? '');
    if (!isset(KAKOMON_PACES[$pace])) {
        kk_fail('invalid_pace');
    }
    // 同じものを押し直しても updated_at は今にする（「この日の時点で普通」の確認になる）
    $stmt = $pdo->prepare(
        'INSERT INTO kakomon_pace (student_id, pace, updated_at) VALUES (:sid, :p, NOW())
         ON DUPLICATE KEY UPDATE pace = :p2, updated_at = NOW()'
    );
    $stmt->execute(['sid' => $studentId, 'p' => $pace, 'p2' => $pace]);
    kk_done($pdo, $studentId);
}

// ============================================================
// 購入した過去問（高校）を足す
// ============================================================
// 私立（kakomon_schools）・公立（kakomon_public_schools）で共用。表の名前はここの固定値だけ
function kk_add_school(PDO $pdo, int $studentId, array $input, bool $public): void
{
    $kind = $public ? 'public' : 'private';
    $table = $public ? 'kakomon_public_schools' : 'kakomon_schools';
    $max = $public ? KAKOMON_PUBLIC_MAX_SCHOOLS : KAKOMON_MAX_SCHOOLS;

    $targetId = isset($input['target_school_id']) ? (int)$input['target_school_id'] : 0;
    if ($targetId > 0) {
        $stmt = $pdo->prepare(
            'SELECT name FROM target_schools
             WHERE target_school_id = :id AND kind = :kind AND is_active = 1'
        );
        $stmt->execute(['id' => $targetId, 'kind' => $kind]);
        $name = (string)$stmt->fetchColumn();
        if ($name === '') {
            kk_fail('invalid_school');
        }
    } else {
        // リストに無い学校の手入力。前後と連続する空白を詰める
        $name = trim((string)preg_replace('/[\s　]+/u', ' ', (string)($input['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 80) {
            kk_fail('invalid_school_name');
        }
        // 手入力でもマスターの学校と同じ名前なら、マスターの学校として登録する
        // （講師画面で同じ学校が別々に並ばないように）
        $stmt = $pdo->prepare(
            'SELECT target_school_id, name FROM target_schools
             WHERE name = :name AND kind = :kind AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['name' => $name, 'kind' => $kind]);
        $hit = $stmt->fetch();
        if ($hit) {
            $targetId = (int)$hit['target_school_id'];
            $name = (string)$hit['name'];
        }
    }

    // 同じ高校の二重登録を防ぐ（マスターの学校は ID、手入力は名前で見る）
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM {$table} x
         LEFT JOIN target_schools ts ON ts.target_school_id = x.target_school_id
         WHERE x.student_id = :sid
           AND (x.target_school_id = :tid OR COALESCE(ts.name, x.school_name) = :name)"
    );
    $stmt->execute(['sid' => $studentId, 'tid' => $targetId, 'name' => $name]);
    if ((int)$stmt->fetchColumn() > 0) {
        kk_fail('duplicate_school', 409);
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE student_id = :sid");
    $stmt->execute(['sid' => $studentId]);
    if ((int)$stmt->fetchColumn() >= $max) {
        kk_fail($public ? 'too_many_public_schools' : 'too_many_schools', 409);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO {$table} (student_id, target_school_id, school_name) VALUES (:sid, :tid, :name)"
    );
    $stmt->execute(['sid' => $studentId, 'tid' => $targetId > 0 ? $targetId : null, 'name' => $name]);
    kk_done($pdo, $studentId, ['school_id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'add_school') {
    kk_add_school($pdo, $studentId, $input, false);
}

// ============================================================
// 高校を外す（記録が残っている高校は外さない＝うっかり記録ごと消すのを防ぐ）
// ============================================================
if ($action === 'remove_school') {
    $schoolId = isset($input['school_id']) ? (int)$input['school_id'] : 0;
    if ($schoolId <= 0 || !kk_own_school($pdo, $studentId, $schoolId)) {
        kk_fail('not_found', 404);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM kakomon_logs WHERE kschool_id = :id');
    $stmt->execute(['id' => $schoolId]);
    if ((int)$stmt->fetchColumn() > 0) {
        kk_fail('school_has_logs', 409);
    }
    $stmt = $pdo->prepare('DELETE FROM kakomon_schools WHERE kschool_id = :id AND student_id = :sid');
    $stmt->execute(['id' => $schoolId, 'sid' => $studentId]);
    kk_done($pdo, $studentId);
}

// ============================================================
// 1回ぶんの記録（新規・修正）
// ============================================================
if ($action === 'save_log') {
    $logId = isset($input['log_id']) ? (int)$input['log_id'] : 0;

    $schoolId = isset($input['school_id']) ? (int)$input['school_id'] : 0;
    if ($schoolId <= 0 || !kk_own_school($pdo, $studentId, $schoolId)) {
        kk_fail('invalid_school');
    }

    $year = kk_uint($input['year'] ?? null);
    if ($year === null || !kakomon_year_ok($year)) {
        kk_fail('invalid_year');
    }

    $subject = (string)($input['subject'] ?? '');
    if (!isset(KAKOMON_SUBJECTS[$subject])) {
        kk_fail('invalid_subject');
    }

    $lap = kk_uint($input['lap'] ?? null);
    if ($lap === null || $lap < 1 || $lap > KAKOMON_LAPS) {
        kk_fail('invalid_lap');
    }

    // ---- 得点: 点数（得点と満点）か、得点率だけか ----
    $hasScore = isset($input['score']) && trim((string)$input['score']) !== '';
    $score = null;
    $maxScore = null;
    if ($hasScore) {
        $score = kk_uint($input['score']);
        $maxScore = kk_uint($input['max_score'] ?? null);
        if ($maxScore === null || $maxScore < 1 || $maxScore > KAKOMON_SCORE_MAX) {
            kk_fail('invalid_max_score');
        }
        if ($score === null || $score > $maxScore) {
            kk_fail('invalid_score');
        }
        $rate = (int)round(100 * $score / $maxScore);
    } else {
        // 得点率は小数で来ても四捨五入して整数で持つ
        $raw = trim((string)($input['rate'] ?? ''));
        if (!preg_match('/^\d{1,3}(\.\d+)?$/', $raw)) {
            kk_fail('invalid_rate');
        }
        $rate = (int)round((float)$raw);
        if ($rate < 0 || $rate > 100) {
            kk_fail('invalid_rate');
        }
    }

    // ---- できたところ／できなかったところ ----
    $good = trim((string)($input['good'] ?? ''));
    $bad = trim((string)($input['bad'] ?? ''));
    if (mb_strlen($good) > KAKOMON_TEXT_MAX || mb_strlen($bad) > KAKOMON_TEXT_MAX) {
        kk_fail('text_too_long');
    }
    $good = $good !== '' ? $good : null;
    $bad = $bad !== '' ? $bad : null;

    $params = [
        'ks' => $schoolId, 'y' => $year, 'sub' => $subject, 'lap' => $lap,
        'sc' => $score, 'mx' => $maxScore, 'rate' => $rate, 'good' => $good, 'bad' => $bad,
        'sid' => $studentId,
    ];

    try {
        if ($logId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE kakomon_logs
                 SET kschool_id = :ks, exam_year = :y, subject = :sub, lap = :lap,
                     score = :sc, max_score = :mx, rate = :rate, good_text = :good, bad_text = :bad
                 WHERE log_id = :id AND student_id = :sid'
            );
            $stmt->execute($params + ['id' => $logId]);
            // 値が同じだと rowCount が0になるので、行があるかは別に確かめる
            if ($stmt->rowCount() === 0) {
                $chk = $pdo->prepare('SELECT COUNT(*) FROM kakomon_logs WHERE log_id = :id AND student_id = :sid');
                $chk->execute(['id' => $logId, 'sid' => $studentId]);
                if ((int)$chk->fetchColumn() === 0) {
                    kk_fail('not_found', 404);
                }
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO kakomon_logs
                   (student_id, kschool_id, exam_year, subject, lap, score, max_score, rate, good_text, bad_text, device_id)
                 VALUES (:sid, :ks, :y, :sub, :lap, :sc, :mx, :rate, :good, :bad, :dev)'
            );
            $stmt->execute($params + ['dev' => device_id($pdo)]);
            $logId = (int)$pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        // 同じ高校・年度・科目・周目がもうある（UNIQUE uq_kl_attempt）
        if ($e->getCode() === '23000') {
            kk_fail('duplicate_log', 409);
        }
        throw $e;
    }

    kk_done($pdo, $studentId, ['log_id' => $logId]);
}

// ============================================================
// 1回ぶんの記録を消す
// ============================================================
if ($action === 'delete_log') {
    $logId = isset($input['log_id']) ? (int)$input['log_id'] : 0;
    $stmt = $pdo->prepare('DELETE FROM kakomon_logs WHERE log_id = :id AND student_id = :sid');
    $stmt->execute(['id' => $logId, 'sid' => $studentId]);
    if ($stmt->rowCount() === 0) {
        kk_fail('not_found', 404);
    }
    kk_done($pdo, $studentId);
}

// ============================================================
// 公立: 全体の進み具合
// ============================================================
if ($action === 'public_pace') {
    $pace = (string)($input['pace'] ?? '');
    if (!isset(KAKOMON_PACES[$pace])) {
        kk_fail('invalid_pace');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO kakomon_public_pace (student_id, pace, updated_at) VALUES (:sid, :p, NOW())
         ON DUPLICATE KEY UPDATE pace = :p2, updated_at = NOW()'
    );
    $stmt->execute(['sid' => $studentId, 'p' => $pace, 'p2' => $pace]);
    kk_done($pdo, $studentId);
}

// ============================================================
// 公立: 受ける高校（2校まで）を足す／外す。記録とはひもづけないので、いつでも外せる
// ============================================================
if ($action === 'public_add_school') {
    kk_add_school($pdo, $studentId, $input, true);
}

if ($action === 'public_remove_school') {
    $schoolId = isset($input['school_id']) ? (int)$input['school_id'] : 0;
    $stmt = $pdo->prepare('DELETE FROM kakomon_public_schools WHERE pschool_id = :id AND student_id = :sid');
    $stmt->execute(['id' => $schoolId, 'sid' => $studentId]);
    if ($stmt->rowCount() === 0) {
        kk_fail('not_found', 404);
    }
    kk_done($pdo, $studentId);
}

// ============================================================
// 公立: 1回ぶんの記録（新規・修正）
// ============================================================
if ($action === 'public_save_log') {
    $logId = isset($input['log_id']) ? (int)$input['log_id'] : 0;

    $year = kk_uint($input['year'] ?? null);
    if ($year === null || !kakomon_year_ok($year)) {
        kk_fail('invalid_year');
    }

    // A/Bグループ: 2022年度までは必須、2023年度からは持たない（''）
    $group = (string)($input['group'] ?? '');
    if ($year <= KAKOMON_PUBLIC_AB_LAST_YEAR) {
        if (!isset(KAKOMON_PUBLIC_GROUPS[$group])) {
            kk_fail('invalid_group');
        }
    } else {
        $group = '';
    }

    $subject = (string)($input['subject'] ?? '');
    if (!isset(KAKOMON_SUBJECTS[$subject])) {
        kk_fail('invalid_subject');
    }

    $lap = kk_uint($input['lap'] ?? null);
    if ($lap === null || $lap < 1 || $lap > KAKOMON_LAPS) {
        kk_fail('invalid_lap');
    }

    $score = kk_uint($input['score'] ?? null);
    if ($score === null || $score > KAKOMON_PUBLIC_MAX) {
        kk_fail('invalid_public_score');
    }

    $good = trim((string)($input['good'] ?? ''));
    $bad = trim((string)($input['bad'] ?? ''));
    if (mb_strlen($good) > KAKOMON_TEXT_MAX || mb_strlen($bad) > KAKOMON_TEXT_MAX) {
        kk_fail('text_too_long');
    }
    $good = $good !== '' ? $good : null;
    $bad = $bad !== '' ? $bad : null;

    $params = [
        'y' => $year, 'g' => $group, 'sub' => $subject, 'lap' => $lap,
        'sc' => $score, 'good' => $good, 'bad' => $bad, 'sid' => $studentId,
    ];

    try {
        if ($logId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE kakomon_public_logs
                 SET exam_year = :y, exam_group = :g, subject = :sub, lap = :lap,
                     score = :sc, good_text = :good, bad_text = :bad
                 WHERE log_id = :id AND student_id = :sid'
            );
            $stmt->execute($params + ['id' => $logId]);
            if ($stmt->rowCount() === 0) {
                $chk = $pdo->prepare('SELECT COUNT(*) FROM kakomon_public_logs WHERE log_id = :id AND student_id = :sid');
                $chk->execute(['id' => $logId, 'sid' => $studentId]);
                if ((int)$chk->fetchColumn() === 0) {
                    kk_fail('not_found', 404);
                }
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO kakomon_public_logs
                   (student_id, exam_year, exam_group, subject, lap, score, good_text, bad_text, device_id)
                 VALUES (:sid, :y, :g, :sub, :lap, :sc, :good, :bad, :dev)'
            );
            $stmt->execute($params + ['dev' => device_id($pdo)]);
            $logId = (int)$pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        // 同じ年度・グループ・科目・周目がもうある（UNIQUE uq_kpl_attempt）
        if ($e->getCode() === '23000') {
            kk_fail('duplicate_log', 409);
        }
        throw $e;
    }

    kk_done($pdo, $studentId, ['log_id' => $logId]);
}

// ============================================================
// 公立: 1回ぶんの記録を消す
// ============================================================
if ($action === 'public_delete_log') {
    $logId = isset($input['log_id']) ? (int)$input['log_id'] : 0;
    $stmt = $pdo->prepare('DELETE FROM kakomon_public_logs WHERE log_id = :id AND student_id = :sid');
    $stmt->execute(['id' => $logId, 'sid' => $studentId]);
    if ($stmt->rowCount() === 0) {
        kk_fail('not_found', 404);
    }
    kk_done($pdo, $studentId);
}

kk_fail('invalid_action');
