<?php
// 冰箱库存管理系统 · 快速取用（减量/删除）
require_once __DIR__ . '/config.php';

if (getMethod() !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    $db = getDB();
    $data = getJsonInput();
    $id = $data['id'] ?? getParam('id');
    $amount = (int)($data['amount'] ?? getParam('amount', 1));

    if (!$id || $amount < 1) {
        jsonResponse(['error' => '参数错误'], 400);
    }

    // 获取物品
    $stmt = $db->prepare('SELECT * FROM items WHERE id = ?');
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    if (!$item) {
        jsonResponse(['error' => '物品不存在'], 404);
    }

    $newQty = (float)$item['quantity'] - $amount;

    if ($newQty <= 0) {
        // 数量归零，删除物品
        $stmt = $db->prepare('DELETE FROM items WHERE id = ?');
        $stmt->execute([$id]);
        jsonResponse(['action' => 'deleted', 'message' => '物品已取完并删除']);
    } else {
        // 更新数量
        $stmt = $db->prepare('UPDATE items SET quantity = ? WHERE id = ?');
        $stmt->execute([$newQty, $id]);
        jsonResponse(['action' => 'updated', 'quantity' => $newQty, 'message' => "已取用 {$amount}，剩余 {$newQty}"]);
    }

} catch (\Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
