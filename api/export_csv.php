<?php
// 冰箱库存管理系统 · CSV 导出（兼容 Excel）
require_once __DIR__ . '/config.php';

if (getMethod() !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    $db = getDB();

    // 导出时间
    $exportTime = date('Y-m-d H:i:s');

    $isTemplate = getParam('template') !== null;

    $items = $isTemplate ? [] : $db->query("
        SELECT i.*, c.name AS category_name, f.name AS fridge_name
        FROM items i
        JOIN categories c ON i.category_id = c.id
        JOIN fridges f ON i.fridge_id = f.id
        ORDER BY i.id
    ")->fetchAll();

    // 无论是否模板，冰箱表都输出已有数据（供模板参考 ID）
    $fridges = $db->query('SELECT * FROM fridges ORDER BY id')->fetchAll();

    // UTF-8 BOM + 内存流
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, "\xEF\xBB\xBF");

    // ===== 冰箱表 =====
    fputcsv($fh, ['# 冰箱库存数据']);
    fputcsv($fh, ['# 导出时间: ' . $exportTime]);
    fputcsv($fh, []);

    if ($isTemplate && !empty($fridges)) {
        fputcsv($fh, ['# 以下为现有冰箱列表，填写物品时请参考冰箱 ID：']);
    }

    // 冰箱注释行
    fputcsv($fh, ['# 冰箱']);
    // BOM 后正常的 CSV 表头
    fputcsv($fh, ['ID', '名称', '位置', '描述']);
    foreach ($fridges as $f) {
        fputcsv($fh, [$f['id'], $f['name'], $f['location'] ?? '', $f['description'] ?? '']);
    }

    fputcsv($fh, []);

    // ===== 物品表 =====
    fputcsv($fh, ['# 物品']);
    $cats = $db->query('SELECT name, icon FROM categories ORDER BY sort_order')->fetchAll();
    $catList = implode(', ', array_map(fn($c) => $c['icon'] . $c['name'], $cats));
    fputcsv($fh, ['# 可用分类: ' . $catList]);
    fputcsv($fh, ['# 单位: 个/瓶/袋/盒/斤/公斤/把 ; 保质期单位: day=天, month=月, year=年']);
    $headers = ['名称', '分类', '冰箱ID', '数量', '单位', '存储类型', '生产日期', '保质期值', '保质期单位', '添加日期', '备注'];
    fputcsv($fh, $headers);

    $storageMap = ['cold' => '冷藏', 'frozen' => '冷冻', '' => ''];
    foreach ($items as $item) {
        fputcsv($fh, [
            $item['name'],
            $item['category_name'],
            (int)$item['fridge_id'],
            $item['quantity'] ?? '1',
            $item['unit'] ?? '个',
            $storageMap[$item['storage_type'] ?? ''] ?? '',
            $item['production_date'] ?? '',
            $item['shelf_life_value'] ?? '',
            $item['shelf_life_unit'] ?? '',
            $item['added_date'] ?? '',
            $item['notes'] ?? '',
        ]);
    }

    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fridge-export-' . date('Ymd-His') . '.csv"');
    echo $csv;
    exit;

} catch (\Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
