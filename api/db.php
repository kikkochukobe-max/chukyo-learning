<?php
declare(strict_types=1);

// サーバーのタイムゾーンに依存せず常に日本時間で動かす
// （HetemlのMySQLはUTCのため、放置するとNOW()が9時間ずれる）
date_default_timezone_set('Asia/Tokyo');

// 第一候補: learning/ と同じ階層（公開ルート直下）に置いた config.php。
// 直下用の .htaccess で直アクセスを拒否している。見つからなければ
// api/config.php にフォールバックする（そちらも .htaccess で保護済み）
function config_path(): string
{
    $sibling = dirname(__DIR__) . '/config.php';
    if (file_exists($sibling)) {
        return $sibling;
    }
    return __DIR__ . '/config.php';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $configPath = config_path();
    if (!file_exists($configPath)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'config_missing']);
        exit;
    }

    $config = require $configPath;
    $charset = $config['db_charset'] ?? 'utf8mb4';
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['db_host'], $config['db_name'], $charset);

    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        // MySQLのNOW()/CURDATE()等を日本時間にする
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+09:00'",
    ]);

    return $pdo;
}
