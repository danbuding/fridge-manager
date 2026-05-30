<?php
// 冰箱库存管理系统 · Markdown 导出
require_once __DIR__ . '/config.php';

if (getMethod() !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    $db = getDB();

    // 导出时间
    $exportTime = date('Y-m-d H:i:s');
    $isTemplate = getParam('template') !== null;

    $md = "# 冰箱库存数据\n";
    $md .= "> 导出时间: {$exportTime}\n\n";

    // ===== 冰箱表 =====
    // 无论是否模板，冰箱表都输出已有数据（供模板参考 ID）
    $fridges = $db->query('SELECT * FROM fridges ORDER BY id')->fetchAll();
    if ($isTemplate && !empty($fridges)) {
        $md .= "> 以下为现有冰箱列表，填写物品时请参考冰箱 ID：\n";
    }
    $md .= "## 冰箱\n";
    $md .= "| ID | 名称 | 位置 | 描述 |\n";
    $md .= "|:---|------|------|------|\n";
    foreach ($fridges as $f) {
        $md .= '| ' . (int)$f['id']
             . ' | ' . escMd($f['name'])
             . ' | ' . escMd($f['location'] ?? '')
             . ' | ' . escMd($f['description'] ?? '')
             . " |\n";
    }
    $md .= "\n";

    // ===== 物品表 =====
    $items = $isTemplate ? [] : $db->query("
        SELECT i.*, c.name AS category_name, f.name AS fridge_name
        FROM items i
        JOIN categories c ON i.category_id = c.id
        JOIN fridges f ON i.fridge_id = f.id
        ORDER BY i.id
    ")->fetchAll();

    $md .= "## 物品\n";
    $md .= "| 名称 | 分类 | 冰箱ID | 数量 | 单位 | 存储类型 | 生产日期 | 保质期值 | 保质期单位 | 添加日期 | 备注 |\n";
    $md .= "|------|------|:------|:----|------|----------|----------|----------|------------|----------|------|\n";
    $cats = $db->query('SELECT name, icon FROM categories ORDER BY sort_order')->fetchAll();
    $catList = implode(', ', array_map(fn($c) => $c['icon'] . $c['name'], $cats));
    $md .= "<!-- 可用分类: {$catList} -->\n";
    $md .= "<!-- 单位: 个/瓶/袋/盒/斤/公斤/把 ; 保质期单位: day=天, month=月, year=年 -->\n";
    $storageMap = ['cold' => '冷藏', 'frozen' => '冷冻', '' => ''];
    foreach ($items as $item) {
        $md .= '| ' . escMd($item['name'])
             . ' | ' . escMd($item['category_name'])
             . ' | ' . (int)$item['fridge_id']
             . ' | ' . ($item['quantity'] ?? '1')
             . ' | ' . escMd($item['unit'] ?? '个')
             . ' | ' . ($storageMap[$item['storage_type'] ?? ''] ?? '')
             . ' | ' . ($item['production_date'] ?? '')
             . ' | ' . ($item['shelf_life_value'] ?? '')
             . ' | ' . ($item['shelf_life_unit'] ?? '')
             . ' | ' . ($item['added_date'] ?? '')
             . ' | ' . escMd($item['notes'] ?? '')
             . " |\n";
    }

    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="fridge-export-' . date('Ymd-His') . '.md"');
    echo $md;
    exit;

} catch (\Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

function escMd(string $str): string {
    // 转义 Markdown 表格特殊字符：管道符和换行
    $str = str_replace('|', '\\|', $str);
    $str = str_replace(["\r\n", "\r", "\n"], ' ', $str);
    return $str;
}
