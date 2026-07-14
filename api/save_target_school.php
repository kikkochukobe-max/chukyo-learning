<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// 志望校マスターの追加 / 名前変更 / 有効・無効切替。
// action: 'add'（name, kind, sort_order?）/ 'rename'（target_school_id, name）/ 'set_active'（target_school_id, active）
// ※rename は同じレコードの名前だけ書き換える（IDは不変＝生徒のひもづけは保たれる。私立の校名変更向け）
// 権限: super_admin のみ（マスターは全教室共通の台帳なので統括が一元管理する。
//   教室管理者は登録/修正フォームで既存の学校を選ぶことはできるが、台帳自体は編集不可）
require_post();
$actor = require_login(['teacher']);
$pdo = db();

$stmt = $pdo->prepare('SELECT role FROM teachers WHERE teacher_id = :id');
$stmt->execute(['id' => $actor['id']]);
$requesterRole = $stmt->fetchColumn();

if ($requesterRole !== 'super_admin') {
    json_response(['ok' => false, 'error' => 'forbidden'], 403);
}

$input = json_input();
$action = (string)($input['action'] ?? 'add');

if ($action === 'add') {
    $name = trim((string)($input['name'] ?? ''));
    $kind = (string)($input['kind'] ?? '');
    $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;

    if ($name === '' || mb_strlen($name) > 80) {
        json_response(['ok' => false, 'error' => 'invalid_name'], 400);
    }
    if (!in_array($kind, ['private', 'public'], true)) {
        json_response(['ok' => false, 'error' => 'invalid_kind'], 400);
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO target_schools (name, kind, sort_order) VALUES (:name, :kind, :so)'
        );
        $stmt->execute(['name' => $name, 'kind' => $kind, 'so' => $sortOrder]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            // (name,kind) が既存。無効化されていたら有効に戻して返す（実質「復活」）
            $stmt = $pdo->prepare('SELECT target_school_id FROM target_schools WHERE name = :name AND kind = :kind');
            $stmt->execute(['name' => $name, 'kind' => $kind]);
            $id = (int)$stmt->fetchColumn();
            $pdo->prepare('UPDATE target_schools SET is_active = 1 WHERE target_school_id = :id')
                ->execute(['id' => $id]);
            json_response(['ok' => true, 'target_school_id' => $id, 'restored' => true]);
        }
        throw $e;
    }
    json_response([
        'ok' => true,
        'target_school_id' => (int)$pdo->lastInsertId(),
        'name' => $name,
        'kind' => $kind,
    ]);
}

if ($action === 'rename') {
    $id = isset($input['target_school_id']) ? (int)$input['target_school_id'] : 0;
    $name = trim((string)($input['name'] ?? ''));
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'invalid_id'], 400);
    }
    if ($name === '' || mb_strlen($name) > 80) {
        json_response(['ok' => false, 'error' => 'invalid_name'], 400);
    }
    try {
        $stmt = $pdo->prepare('UPDATE target_schools SET name = :name WHERE target_school_id = :id');
        $stmt->execute(['name' => $name, 'id' => $id]);
    } catch (PDOException $e) {
        // (name,kind) の一意制約に衝突 = 同じ種別に同名の学校が既にある
        if ($e->getCode() === '23000') {
            json_response(['ok' => false, 'error' => 'duplicate_name'], 409);
        }
        throw $e;
    }
    // 存在しないIDでも rowCount 0 になり得るので確認
    $chk = $pdo->prepare('SELECT COUNT(*) FROM target_schools WHERE target_school_id = :id');
    $chk->execute(['id' => $id]);
    if ((int)$chk->fetchColumn() === 0) {
        json_response(['ok' => false, 'error' => 'not_found'], 404);
    }
    json_response(['ok' => true, 'target_school_id' => $id, 'name' => $name]);
}

if ($action === 'set_active') {
    $id = isset($input['target_school_id']) ? (int)$input['target_school_id'] : 0;
    $active = (int)(bool)($input['active'] ?? false);
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'invalid_id'], 400);
    }
    $stmt = $pdo->prepare('UPDATE target_schools SET is_active = :a WHERE target_school_id = :id');
    $stmt->execute(['a' => $active, 'id' => $id]);
    if ($stmt->rowCount() === 0) {
        // 値が変わらなかった場合も rowCount 0 になり得るので存在確認
        $chk = $pdo->prepare('SELECT COUNT(*) FROM target_schools WHERE target_school_id = :id');
        $chk->execute(['id' => $id]);
        if ((int)$chk->fetchColumn() === 0) {
            json_response(['ok' => false, 'error' => 'not_found'], 404);
        }
    }
    json_response(['ok' => true, 'target_school_id' => $id, 'active' => (bool)$active]);
}

json_response(['ok' => false, 'error' => 'invalid_action'], 400);
