<?php
// 冰箱库存管理系统 · CSV 导入（兼容 Excel）
require_once __DIR__ . '/config.php';

if (getMethod() !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    $db = getDB();
    $input = getJsonInput();
    $csvText = $input['content'] ?? '';
    if (!$csvText) {
        jsonResponse(['error' => '请提供 CSV 内容'], 400);
    }

    $result = [
        'fridges_imported' => 0,
        'fridges_skipped' => 0,
        'items_imported' => 0,
        'items_skipped' => 0,
        'errors' => [],
    ];

    // 预加载分类 name → id
    $catMap = [];
    $cats = $db->query('SELECT id, name FROM categories')->fetchAll();
    foreach ($cats as $c) {
        $catMap[$c['name']] = (int)$c['id'];
    }

    // 预加载已有冰箱
    $existingFridges = [];       // name → id
    $existingFridgeIds = [];     // id → true
    $fridgeRows = $db->query('SELECT id, name FROM fridges')->fetchAll();
    foreach ($fridgeRows as $f) {
        $existingFridges[$f['name']] = (int)$f['id'];
        $existingFridgeIds[(int)$f['id']] = true;
    }

    // 按行切分
    $lines = explode("\n", $csvText);
    $currentSection = '';
    $headers = [];
    $inData = false;

    foreach ($lines as $i => $line) {
        $line = rtrim($line, "\r\n ");
        if ($line === '') continue;

        // 先解析 CSV，再取第一个单元格判断
        $cells = str_getcsv($line);
        $first = trim($cells[0] ?? '');

        // 检测 section 注释标记
        if (preg_match('/^#\s*冰箱\s*$/', $first)) {
            $currentSection = 'fridge';
            $headers = [];
            $inData = false;
            continue;
        }
        if (preg_match('/^#\s*物品\s*$/', $first)) {
            $currentSection = 'item';
            $headers = [];
            $inData = false;
            continue;
        }

        // 跳过其他注释行
        if (str_starts_with($first, '#')) continue;

        if (empty($headers)) {
            // 首行非注释非空行作为表头
            $headers = $cells;
            $inData = true;
            continue;
        }

        if (!$inData || count($cells) < 2) continue;

        if ($currentSection === 'fridge') {
            importFridgeRow($db, $cells, $headers, $existingFridges, $existingFridgeIds, $result);
        } elseif ($currentSection === 'item') {
            importItemRow($db, $cells, $headers, $catMap, $existingFridges, $result);
        }
    }

    jsonResponse($result);

} catch (\Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

// ========== 辅助函数 ==========

function getCell(array $headers, array $cells, string $key): ?string {
    $idx = array_search($key, $headers);
    if ($idx === false || !isset($cells[$idx])) return null;
    $val = trim($cells[$idx]);
    return $val === '' ? null : $val;
}

function importFridgeRow(PDO $db, array $cells, array $headers, array &$existingFridges, array &$existingFridgeIds, array &$result): void {
    $name = getCell($headers, $cells, '名称');
    if (!$name) {
        $result['errors'][] = '跳过无名称的冰箱行';
        return;
    }

    $location = getCell($headers, $cells, '位置') ?? '';
    $description = getCell($headers, $cells, '描述') ?? '';

    // 优先按 ID 匹配
    $idCell = getCell($headers, $cells, 'ID');
    if ($idCell !== null && is_numeric($idCell)) {
        $fid = (int)$idCell;
        if (isset($existingFridgeIds[$fid])) {
            $result['fridges_skipped']++;
            if (!isset($existingFridges[$name])) {
                $existingFridges[$name] = $fid;
            }
            return;
        }
        $stmt = $db->prepare('INSERT INTO fridges (id, name, location, description) VALUES (?, ?, ?, ?)');
        $stmt->execute([$fid, $name, $location, $description]);
        $existingFridges[$name] = $fid;
        $existingFridgeIds[$fid] = true;
        $result['fridges_imported']++;
        return;
    }

    // 无 ID：按名称匹配
    if (isset($existingFridges[$name])) {
        $result['fridges_skipped']++;
        return;
    }
    $stmt = $db->prepare('INSERT INTO fridges (name, location, description) VALUES (?, ?, ?)');
    $stmt->execute([$name, $location, $description]);
    $newId = (int)$db->lastInsertId();
    $existingFridges[$name] = $newId;
    $existingFridgeIds[$newId] = true;
    $result['fridges_imported']++;
}

function importItemRow(PDO $db, array $cells, array $headers, array $catMap, array &$existingFridges, array &$result): void {
    $name = getCell($headers, $cells, '名称');
    if (!$name) {
        $result['errors'][] = '跳过无名称的物品行';
        return;
    }

    // 分类映射
    $catName = getCell($headers, $cells, '分类');
    $categoryId = $catName ? ($catMap[$catName] ?? null) : null;
    if (!$categoryId) {
        $result['errors'][] = "跳过物品「{$name}」：未知分类「{$catName}」";
        $result['items_skipped']++;
        return;
    }

    // 冰箱匹配：优先 ID，回退名称
    $fridgeId = null;
    $idCell = getCell($headers, $cells, '冰箱ID');
    if ($idCell !== null && is_numeric($idCell)) {
        $fid = (int)$idCell;
        $checkId = $db->prepare('SELECT id FROM fridges WHERE id = ?');
        $checkId->execute([$fid]);
        if ($checkId->fetch()) {
            $fridgeId = $fid;
        }
    }
    if (!$fridgeId) {
        $fridgeName = getCell($headers, $cells, '冰箱');
        $fridgeId = $fridgeName ? ($existingFridges[$fridgeName] ?? null) : null;
    }
    if (!$fridgeId) {
        $result['errors'][] = "跳过物品「{$name}」：冰箱ID或名称不匹配";
        $result['items_skipped']++;
        return;
    }

    $quantity = (float)(getCell($headers, $cells, '数量') ?? 1);
    $unit = getCell($headers, $cells, '单位') ?? '个';
    $storageRaw = getCell($headers, $cells, '存储类型') ?? '';
    $storageMap = ['冷藏' => 'cold', '冷冻' => 'frozen'];
    $storageType = $storageMap[$storageRaw] ?? null;
    $productionDate = getCell($headers, $cells, '生产日期') ?? null;
    $shelfValue = getCell($headers, $cells, '保质期值');
    $shelfValue = $shelfValue !== null ? (int)$shelfValue : null;
    $shelfUnit = getCell($headers, $cells, '保质期单位') ?? null;
    $addedDate = getCell($headers, $cells, '添加日期') ?? date('Y-m-d');
    $notes = getCell($headers, $cells, '备注') ?? '';

    $stmt = $db->prepare('INSERT INTO items (name, category_id, fridge_id, quantity, unit, production_date, shelf_life_value, shelf_life_unit, storage_type, added_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $categoryId, $fridgeId, $quantity, $unit, $productionDate, $shelfValue, $shelfUnit, $storageType, $addedDate, $notes]);
    $result['items_imported']++;
}
