<?php
require_once __DIR__ . '/config.php';

$db = getDB();
$method = getMethod();

switch ($method) {
    case 'GET':
        $id = getParam('id');
        if ($id) {
            // 单个冰箱 + 物品数量
            $stmt = $db->prepare('SELECT f.*, COUNT(i.id) AS item_count FROM fridges f LEFT JOIN items i ON f.id = i.fridge_id WHERE f.id = ? GROUP BY f.id');
            $stmt->execute([$id]);
            $fridge = $stmt->fetch();
            if (!$fridge) jsonResponse(['error' => '冰箱不存在'], 404);
            jsonResponse($fridge);
        }
        // 冰箱列表（含物品数量）
        $stmt = $db->query('SELECT f.*, COUNT(i.id) AS item_count FROM fridges f LEFT JOIN items i ON f.id = i.fridge_id GROUP BY f.id ORDER BY f.created_at DESC');
        jsonResponse($stmt->fetchAll());
        break;

    case 'POST':
        $data = getJsonInput();
        if (empty($data['name'])) jsonResponse(['error' => '冰箱名称不能为空'], 400);
        $stmt = $db->prepare('INSERT INTO fridges (name, description, location) VALUES (?, ?, ?)');
        $stmt->execute([$data['name'], $data['description'] ?? '', $data['location'] ?? '']);
        jsonResponse(['id' => (int)$db->lastInsertId(), 'message' => '冰箱创建成功'], 201);
        break;

    case 'PUT':
        $data = getJsonInput();
        $id = $data['id'] ?? getParam('id');
        if (!$id) jsonResponse(['error' => '缺少冰箱ID'], 400);
        $stmt = $db->prepare('UPDATE fridges SET name = ?, description = ?, location = ? WHERE id = ?');
        $stmt->execute([$data['name'] ?? '', $data['description'] ?? '', $data['location'] ?? '', $id]);
        jsonResponse(['message' => '冰箱更新成功']);
        break;

    case 'DELETE':
        $id = getParam('id');
        if (!$id) jsonResponse(['error' => '缺少冰箱ID'], 400);
        $stmt = $db->prepare('DELETE FROM fridges WHERE id = ?');
        $stmt->execute([$id]);
        jsonResponse(['message' => '冰箱已删除']);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
