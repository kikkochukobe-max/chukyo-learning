<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 志望校マスター一覧。講師なら誰でも読める（登録フォーム・ランキングの選択肢に使う）。
// ?all=1 で無効化した学校も含める（管理タブ用）。既定は有効なもののみ。
$actor = require_login(['teacher']);
$pdo = db();

$includeInactive = isset($_GET['all']);
$where = $includeInactive ? '' : ' WHERE is_active = 1';

$rows = $pdo->query(
    "SELECT target_school_id, name, kind, sort_order, is_active
     FROM target_schools{$where}
     ORDER BY kind, sort_order, name"
)->fetchAll();

foreach ($rows as &$r) {
    $r['target_school_id'] = (int)$r['target_school_id'];
    $r['sort_order'] = (int)$r['sort_order'];
    $r['is_active'] = (bool)$r['is_active'];
}
unset($r);

json_response(['ok' => true, 'schools' => $rows]);
