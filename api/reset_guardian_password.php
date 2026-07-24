<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 【廃止】保護者は自前のパスワードを持たず「お子さまの生徒PIN」でログインする方式に変更したため、
// 保護者パスワードの初期化という操作は無くなった。
// 保護者がログインできない＝お子さまのPINが不明な場合は、生徒PIN側で対応する。
// 旧クライアントからの呼び出しに備え、明示的に廃止レスポンスを返す。

require_post();
require_login(['teacher']);

json_response(['ok' => false, 'error' => 'gone', 'message' => '保護者はお子さまのPINでログインします。パスワード初期化は廃止されました。'], 410);
