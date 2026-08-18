<?php
declare(strict_types=1);

/* デプロイ確認用の使い捨て診断ファイル。
   「本番のファイルが、いま手元(git)のものと同じ中身か」を1回のアクセスで判定する。
   マーカー文字列の有無だけでは開発途中の中間バージョンを見抜けないので、
   改行コードを LF に正規化した md5 で突き合わせる（FTPのASCIIモード対策）。
   DBにも config.php にも触らないので、置くだけで動く。

   使い方: https://chukyokobetsu.com/api/deploy_check.php?k=divpcheck
   ⚠ 確認が終わったらサーバーから削除すること。 */

header('Content-Type: text/plain; charset=UTF-8');

if (($_GET['k'] ?? '') !== 'divpcheck') {
    http_response_code(404);
    echo "not found\n";
    exit;
}

$root = dirname(__DIR__);

// [表示名, 相対パス, 期待するmd5の先頭10桁(LF正規化), 期待するバイト数(LF正規化)]
$targets = [
    ['divp-core.js',      'assets/divp-core.js',                       '657ff602c4', 22008],
    ['save_answer.php',   'api/save_answer.php',                       '800b6a61f8', 23652],
    ['list_retries.php',  'api/list_retries.php',                      'b837b9ad6e', 2440],
    ['units.php',         'api/units.php',                             '4c4aff7073', 7218],
    ['reset_records.php', 'api/reset_student_records.php',              '288cfaafdf', 3889],
    ['retry.php',         'retry.php',                                 '41b4bdfbb5', 21862],
    ['mypage.php',        'mypage.php',                                'f8cffccdcb', 40869],
    ['teacher.php',       'teacher.php',                               '9bd827231c', 135292],
    ['admin.php',         'admin.php',                                 '1e44e68a34', 97209],
    ['aichi_daimon1.html', 'learning/math/math_js3_aichi_daimon1.html', '0fde8796a8', 160938],
    // 共通アセットのキャッシュ制御。これが無いとブラウザが古いJSを使い続ける
    ['assets/.htaccess',  'assets/.htaccess',                          '6200f86e44', 767],
];

echo "php " . PHP_VERSION . " / now " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 72) . "\n";

foreach ($targets as [$label, $rel, $wantHash, $wantSize]) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        printf("%-20s : ファイルが無い (%s)\n", $label, $rel);
        continue;
    }
    $body = str_replace("\r\n", "\n", (string)file_get_contents($path));
    $hash = substr(md5($body), 0, 10);
    $size = strlen($body);
    printf(
        "%-20s : %s  更新 %s  %s\n",
        $label,
        $hash === $wantHash ? '一致  ' : '不一致',
        date('m/d H:i', (int)filemtime($path)),
        $hash === $wantHash
            ? ''
            : sprintf('server %s %dB / git %s %dB (差 %+d)', $hash, $size, $wantHash, $wantSize, $size - $wantSize)
    );
}
