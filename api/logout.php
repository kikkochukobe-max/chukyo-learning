<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_post();
start_secure_session();

// 自動ログイントークンも失効させる（消さないと次のアクセスで復元されてしまう）
clear_remember_token(db());

$_SESSION = [];
session_destroy();

json_response(['ok' => true]);
