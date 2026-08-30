<?php
declare(strict_types=1);

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return $_POST;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $_POST;
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ===== 自動ログイン（divp_remember クッキー） =====
// クッキー値は "selector:validator"。DBには validator の sha256 のみ保存する
const REMEMBER_COOKIE = 'divp_remember';
const REMEMBER_DAYS = 180;

function remember_cookie_params(int $lifetime): array
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return [
        'expires'  => $lifetime > 0 ? time() + $lifetime : time() - 3600,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function issue_remember_token(PDO $pdo, string $actorType, int $actorId, ?string $deviceId): void
{
    $selector = bin2hex(random_bytes(12));   // 24文字
    $validator = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare(
        'INSERT INTO auth_tokens (actor_type, actor_id, selector, validator_hash, device_id, expires_at)
         VALUES (:actor_type, :actor_id, :selector, :validator_hash, :device_id, DATE_ADD(NOW(), INTERVAL :days DAY))'
    );
    $stmt->execute([
        'actor_type'     => $actorType,
        'actor_id'       => $actorId,
        'selector'       => $selector,
        'validator_hash' => hash('sha256', $validator),
        'device_id'      => $deviceId,
        'days'           => REMEMBER_DAYS,
    ]);

    // ついでに期限切れトークンを掃除
    $pdo->query('DELETE FROM auth_tokens WHERE expires_at < NOW()');

    setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, remember_cookie_params(60 * 60 * 24 * REMEMBER_DAYS));
}

function clear_remember_token(PDO $pdo): void
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($cookie !== '' && strpos($cookie, ':') !== false) {
        [$selector] = explode(':', $cookie, 2);
        $stmt = $pdo->prepare('DELETE FROM auth_tokens WHERE selector = :selector');
        $stmt->execute(['selector' => $selector]);
    }
    setcookie(REMEMBER_COOKIE, '', remember_cookie_params(0));
}

// セッションが無い時に divp_remember クッキーからログイン状態を復元する
function try_remember_login(): ?array
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($cookie === '' || strpos($cookie, ':') === false) {
        return null;
    }
    [$selector, $validator] = explode(':', $cookie, 2);
    if (!preg_match('/^[0-9a-f]{24}$/', $selector)) {
        return null;
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM auth_tokens WHERE selector = :selector AND expires_at > NOW()');
    $stmt->execute(['selector' => $selector]);
    $token = $stmt->fetch();

    if (!$token || !hash_equals($token['validator_hash'], hash('sha256', $validator))) {
        setcookie(REMEMBER_COOKIE, '', remember_cookie_params(0));
        return null;
    }

    // 生徒本人がまだ有効か確認して氏名も取り直す（現状トークンは生徒のみ発行）
    if ($token['actor_type'] !== 'student') {
        return null;
    }
    // 自動ログインはPIN入力を通らないので、ここでも退会予約の期日切れを反映してから確認する
    // （これが無いと、cronが止まっている間は自動ログインだけがすり抜ける）
    sweep_due_deactivations($pdo);
    $stmt = $pdo->prepare('SELECT student_id, student_name FROM students WHERE student_id = :id AND is_active = 1');
    $stmt->execute(['id' => (int)$token['actor_id']]);
    $student = $stmt->fetch();
    if (!$student) {
        clear_remember_token($pdo);
        return null;
    }

    $stmt = $pdo->prepare('UPDATE auth_tokens SET last_used_at = NOW() WHERE token_id = :id');
    $stmt->execute(['id' => $token['token_id']]);

    session_regenerate_id(true);
    $_SESSION['actor_type'] = 'student';
    $_SESSION['actor_id'] = (int)$student['student_id'];
    $_SESSION['actor_name'] = $student['student_name'];

    return [
        'type' => 'student',
        'id'   => (int)$student['student_id'],
        'name' => $student['student_name'],
    ];
}

// 現在のログイン状態を返す（セッション優先、無ければ自動ログインを試す）
function current_actor(): ?array
{
    start_secure_session();
    $type = $_SESSION['actor_type'] ?? null;
    $id = $_SESSION['actor_id'] ?? null;
    if ($type && $id) {
        return [
            'type' => $type,
            'id'   => (int)$id,
            'name' => $_SESSION['actor_name'] ?? null,
        ];
    }
    return try_remember_login();
}

// actor_type/actor_id/actor_name をセッションに持つ生徒・講師・保護者共用の認証チェック
function require_login(array $allowedTypes): array
{
    $actor = current_actor();
    if (!$actor || !in_array($actor['type'], $allowedTypes, true)) {
        json_response(['ok' => false, 'error' => 'unauthenticated'], 401);
    }
    return $actor;
}

function client_ip(): ?string
{
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

function client_user_agent(): ?string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    return $ua !== null ? substr($ua, 0, 255) : null;
}

// 講師の暫定パスワードを生成する（登録時・PW初期化時に共用）。
// 読み間違えにくい文字だけで8文字（0/O、1/l/I などを除外）。
// 本パスワードは初回ログイン時に本人が8〜15桁の英数で設定し直す。
function generate_temp_password(): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    $temp = '';
    for ($i = 0; $i < 8; $i++) {
        $temp .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $temp;
}

// 本人が設定する本パスワードの検証: 8〜15桁の半角英数のみ
function is_valid_teacher_password(string $pw): bool
{
    return (bool)preg_match('/\A[A-Za-z0-9]{8,15}\z/', $pw);
}

function uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// divp_device クッキーで端末を識別。無ければ発行し devices に登録する
function device_id(PDO $pdo): string
{
    $existing = $_COOKIE['divp_device'] ?? '';
    $valid = (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $existing);
    $id = $valid ? $existing : uuid_v4();

    if (!$valid) {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('divp_device', $id, [
            'expires'  => time() + 60 * 60 * 24 * 365,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO devices (device_id) VALUES (:id)
         ON DUPLICATE KEY UPDATE last_seen_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute(['id' => $id]);

    return $id;
}

// 配列を再帰的にキーソートしてから JSON 化し、同一内容なら常に同一ハッシュにする
function canonicalize($value)
{
    if (is_array($value)) {
        $isList = array_keys($value) === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value);
        }
        foreach ($value as $k => $v) {
            $value[$k] = canonicalize($v);
        }
    }
    return $value;
}

function params_hash($params): string
{
    $normalized = canonicalize($params ?? []);
    return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// ===== 退会の予約（students.deactivate_on） =====
// deactivate_on は「この日までは使える」最終利用日。**翌日から** 無効にする。
// 期日を過ぎた予約をまとめて is_active=0 にする。
// 呼ぶ場所は3つ: api/run_deactivation.php(cron) / api/auth.php(ログイン) /
//   admin.php・teacher.php(講師が画面を開いたとき)。cron が止まっても、
//   その生徒がログインしようとした瞬間に締まる。
// 予約日そのものは消さない＝一覧に「8/31で退会」と履歴を出せる。
// 「有効に戻す」(set_active.php) 側で NULL に戻すので、戻した生徒がここで再び無効になることはない。
function sweep_due_deactivations(PDO $pdo): int
{
    try {
        $stmt = $pdo->query(
            'UPDATE students SET is_active = 0
              WHERE is_active = 1 AND deactivate_on IS NOT NULL AND deactivate_on < CURDATE()'
        );
        return $stmt->rowCount();
    } catch (PDOException $e) {
        // deactivate_on 列がまだ無い環境（PHPだけ先に上げてALTER未実行）でも
        // ログインや講師ページを巻き添えで止めない。
        error_log('[deactivate] sweep skipped: ' . $e->getMessage());
        return 0;
    }
}

// 列の有無を1リクエスト1回だけ調べる（ALTER 未実行の本番でも画面が真っ白にならないように）。
// ⚠ 判定は「その列を1行も取らずに読んでみる」＝実際の使い方そのもので行う。
//   最初 SHOW COLUMNS ... LIKE :col で書いたが、db.php が EMULATE_PREPARES=false
//   （ネイティブのプリペアド）なので SHOW にプレースホルダを渡すと環境によっては
//   例外になり、「列が無い」と誤判定する（本番で踏んだ）。
// ⚠ $table / $column はコード内の固定値だけを渡すこと（識別子は連結するので、
//   外から来た値は絶対に入れない）。念のため英数字とアンダースコアだけに限定する。
function table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }
    try {
        $pdo->query("SELECT `{$column}` FROM `{$table}` LIMIT 0");
        $cache[$key] = true;
        schema_probe_error('');
    } catch (PDOException $e) {
        $cache[$key] = false;
        schema_probe_error($e->getMessage());
        error_log('[schema] ' . $key . ': ' . $e->getMessage());
    }
    return $cache[$key];
}

// 直前の table_has_column が false になった理由（画面に出して原因を切り分けるため）。
// 「列が無い」のか「別の理由で確認できなかった」のかを黙って同じ扱いにしない。
function schema_probe_error(?string $set = null): string
{
    static $last = '';
    if ($set !== null) {
        $last = $set;
    }
    return $last;
}
