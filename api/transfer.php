<?php
require_once __DIR__ . '/config.php';

$db = getDB();
$method = getMethod();

switch ($method) {
    case 'GET':
        // 转移记录列表
        $stmt = $db->query('SELECT tl.*, i.name AS item_name, ff.name AS from_fridge_name, tf.name AS to_fridge_name
                           FROM transfer_logs tl
                           JOIN items i ON tl.item_id = i.id
                           JOIN fridges ff ON tl.from_fridge_id = ff.id
                           JOIN fridges tf ON tl.to_fridge_id = tf.id
                           ORDER BY tl.transfer_time DESC
                           LIMIT 50');
        jsonResponse($stmt->fetchAll());
        break;

    case 'POST':
        $data = getJsonInput();
        if (empty($data['item_id']) || empty($data['from_fridge_id']) || empty($data['to_fridge_id'])) {
            jsonResponse(['error' => '缺少必要参数'], 400);
        }
        if ($data['from_fridge_id'] === $data['to_fridge_id']) {
            jsonResponse(['error' => '来源和目标冰箱不能相同'], 400);
        }

        $quantity = (float)($data['quantity'] ?? 1);

        // 获取物品当前信息
        $stmt = $db->prepare('SELECT * FROM items WHERE id = ?');
        $stmt->execute([$data['item_id']]);
        $item = $stmt->fetch();
        if (!$item) jsonResponse(['error' => '物品不存在'], 404);

        if ($item['fridge_id'] != $data['from_fridge_id']) {
            jsonResponse(['error' => '物品不在来源冰箱中'], 400);
        }

        if ($quantity > $item['quantity']) {
            jsonResponse(['error' => '转移数量超过库存'], 400);
        }

        $db->beginTransaction();
        try {
            // 转移全部：更新物品的冰箱归属
            if ($quantity >= $item['quantity']) {
                $stmt = $db->prepare('UPDATE items SET fridge_id = ? WHERE id = ?');
                $stmt->execute([$data['to_fridge_id'], $data['item_id']]);
            } else {
                // 部分转移：减少原物品数量，在目标冰箱新建
                $stmt = $db->prepare('UPDATE items SET quantity = quantity - ? WHERE id = ?');
                $stmt->execute([$quantity, $data['item_id']]);
                $stmt = $db->prepare('INSERT INTO items (name, category_id, fridge_id, quantity, unit, production_date, shelf_life_value, shelf_life_unit, added_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$item['name'], $item['category_id'], $data['to_fridge_id'], $quantity, $item['unit'], $item['production_date'], $item['shelf_life_value'], $item['shelf_life_unit'], date('Y-m-d'), '从 "' . $data['from_fridge_id'] . '" 转移']);
            }

            // 记录转移日志
            $stmt = $db->prepare('INSERT INTO transfer_logs (item_id, from_fridge_id, to_fridge_id, quantity, notes) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$data['item_id'], $data['from_fridge_id'], $data['to_fridge_id'], $quantity, $data['notes'] ?? '']);

            $db->commit();
            jsonResponse(['message' => '转移成功']);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['error' => '转移失败: ' . $e->getMessage()], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
