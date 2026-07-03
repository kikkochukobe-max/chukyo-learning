<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

set_exception_handler(function (Throwable $e): void {
    error_log('[api] ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'internal_error'], 500);
});
