<?php
require_once __DIR__ . '/config.php';

$db = getDB();
$method = getMethod();

switch ($method) {
    case 'GET':
        $fridge_id = getParam('fridge_id');
        $category_id = getParam('category_id');
        $expiring = getParam('expiring'); // 临期筛选：3天内
        $search = getParam('search');
        $limit = getParam('limit');
        $page = getParam('page');
        $perPage = getParam('per_page', 30);

        $baseFrom = 'FROM items i
                JOIN categories c ON i.category_id = c.id
                JOIN fridges f ON i.fridge_id = f.id
                WHERE 1=1';
        $where = '';
        $whereParams = [];

        if ($fridge_id) { $where .= ' AND i.fridge_id = ?'; $whereParams[] = $fridge_id; }
        if ($category_id) { $where .= ' AND i.category_id = ?'; $whereParams[] = $category_id; }
        if ($expiring) {
            $where .= " AND i.shelf_life_value IS NOT NULL";
            $where .= " AND CASE WHEN i.production_date IS NOT NULL THEN";
            $where .= "     CASE i.shelf_life_unit";
            $where .= "         WHEN 'year' THEN DATE_ADD(i.production_date, INTERVAL i.shelf_life_value YEAR)";
            $where .= "         WHEN 'month' THEN DATE_ADD(i.production_date, INTERVAL i.shelf_life_value MONTH)";
            $where .= "         ELSE DATE_ADD(i.production_date, INTERVAL i.shelf_life_value DAY)";
            $where .= "     END";
            $where .= " ELSE";
            $where .= "     CASE i.shelf_life_unit";
            $where .= "         WHEN 'year' THEN DATE_ADD(i.added_date, INTERVAL CEIL(i.shelf_life_value / 2) YEAR)";
            $where .= "         WHEN 'month' THEN DATE_ADD(i.added_date, INTERVAL CEIL(i.shelf_life_value / 2) MONTH)";
            $where .= "         ELSE DATE_ADD(i.added_date, INTERVAL CEIL(i.shelf_life_value / 2) DAY)";
            $where .= "     END";
            $where .= " END <= DATE_ADD(CURDATE(), INTERVAL ? DAY)";
            $whereParams[] = (int)$expiring;
        }
        if ($search) { $where .= ' AND i.name LIKE ?'; $whereParams[] = '%' . $search . '%'; }

        $orderBy = " ORDER BY"
            . " CASE WHEN i.shelf_life_value IS NOT NULL THEN"
            . "     CASE WHEN i.production_date IS NOT NULL THEN"
            . "         CASE i.shelf_life_unit"
            . "             WHEN 'year' THEN DATE_ADD(i.production_date, INTERVAL i.shelf_life_value YEAR)"
            . "             WHEN 'month' THEN DATE_ADD(i.production_date, INTERVAL i.shelf_life_value MONTH)"
            . "             ELSE DATE_ADD(i.production_date, INTERVAL i.shelf_life_value DAY)"
            . "         END"
            . "     ELSE"
            . "         CASE i.shelf_life_unit"
            . "             WHEN 'year' THEN DATE_ADD(i.added_date, INTERVAL CEIL(i.shelf_life_value / 2) YEAR)"
            . "             WHEN 'month' THEN DATE_ADD(i.added_date, INTERVAL CEIL(i.shelf_life_value / 2) MONTH)"
            . "             ELSE DATE_ADD(i.added_date, INTERVAL CEIL(i.shelf_life_value / 2) DAY)"
            . "         END"
            . "     END"
            . "     ELSE NULL"
            . " END ASC, i.added_date DESC";

        if ($page !== null) {
            // Pagination mode: return {items, total, page, per_page}
            $page = max(1, (int)$page);
            $perPage = max(1, min(100, (int)$perPage));
            $offset = ($page - 1) * $perPage;

            $countSql = "SELECT COUNT(*)" . $baseFrom . $where;
            $countStmt = $db->prepare($countSql);
            $countStmt->execute($whereParams);
            $total = (int)$countStmt->fetchColumn();

            $dataSql = "SELECT i.*, c.name AS category_name, c.icon AS category_icon, f.name AS fridge_name " . $baseFrom . $where . $orderBy . " LIMIT ? OFFSET ?";
            $dataParams = array_merge($whereParams, [$perPage, $offset]);
            $stmt = $db->prepare($dataSql);
            $stmt->execute($dataParams);
            $items = $stmt->fetchAll();
        } elseif ($limit) {
            $dataSql = "SELECT i.*, c.name AS category_name, c.icon AS category_icon, f.name AS fridge_name " . $baseFrom . $where . $orderBy . " LIMIT ?";
            $dataParams = array_merge($whereParams, [(int)$limit]);
            $stmt = $db->prepare($dataSql);
            $stmt->execute($dataParams);
            $items = $stmt->fetchAll();
        } else {
            $dataSql = "SELECT i.*, c.name AS category_name, c.icon AS category_icon, f.name AS fridge_name " . $baseFrom . $where . $orderBy;
            $stmt = $db->prepare($dataSql);
            $stmt->execute($whereParams);
            $items = $stmt->fetchAll();
        }

        // 计算存放天数
        foreach ($items as &$item) {
            $now = new DateTime();
            $item['days_stored'] = (int)($now->diff(new DateTime($item['added_date'])))->days;
            $item['is_expiring'] = false;
            $item['is_expired'] = false;
            $item['is_warning'] = false;
            $item['expire_date'] = null;
            $item['days_left'] = null;

            if ($item['production_date'] && $item['shelf_life_value']) {
                $prodDate = new DateTime($item['production_date']);
                $value = (int)$item['shelf_life_value'];
                $unit = $item['shelf_life_unit'] ?? 'day';
                $expDate = clone $prodDate;
                switch ($unit) {
                    case 'year':  $expDate->modify("+{$value} years"); break;
                    case 'month': $expDate->modify("+{$value} months"); break;
                    default:      $expDate->modify("+{$value} days"); break;
                }
                $item['expire_date'] = $expDate->format('Y-m-d');
                $today = new DateTime('today');
                $diff = $today->diff($expDate);
                $item['days_left'] = (int)$diff->days;
                if ($expDate < $today) {
                    $item['is_expired'] = true;
                    $item['days_left'] = -$item['days_left'];
                } elseif ($expDate <= new DateTime('+3 days')) {
                    $item['is_expiring'] = true;
                }
            } elseif (!$item['production_date'] && $item['shelf_life_value']) {
                $halfValue = max(1, (int)ceil($item['shelf_life_value'] / 2));
                $unit = $item['shelf_life_unit'] ?? 'day';
                $expDate = new DateTime($item['added_date']);
                switch ($unit) {
                    case 'year':  $expDate->modify("+{$halfValue} years"); break;
                    case 'month': $expDate->modify("+{$halfValue} months"); break;
                    default:      $expDate->modify("+{$halfValue} days"); break;
                }
                $item['expire_date'] = $expDate->format('Y-m-d');
                $today = new DateTime('today');
                $diff = $today->diff($expDate);
                $item['days_left'] = (int)$diff->days;
                if ($expDate < $today) {
                    $item['is_expired'] = true;
                    $item['days_left'] = -$item['days_left'];
                } elseif ($expDate <= new DateTime('+3 days')) {
                    $item['is_expiring'] = true;
                }
            } else {
                // 只有生产日期没有保质期，或无任何日期：默认按存储类型推算
                $days = ($item['storage_type'] ?? '') === 'frozen' ? 90 : 5;
                $expDate = (new DateTime($item['added_date']))->modify("+{$days} days");
                $item['expire_date'] = $expDate->format('Y-m-d');
                $today = new DateTime('today');
                $diff = $today->diff($expDate);
                $item['days_left'] = (int)$diff->days;
                if ($expDate < $today) {
                    $item['is_expired'] = true;
                    $item['days_left'] = -$item['days_left'];
                } elseif ($expDate <= new DateTime('+3 days')) {
                    $item['is_expiring'] = true;
                }
            }
        }

        if ($page !== null) {
            jsonResponse(['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
        } else {
            jsonResponse($items);
        }
        break;

    case 'POST':
        $data = getJsonInput();
        if (empty($data['name']) || empty($data['fridge_id']) || empty($data['category_id'])) {
            jsonResponse(['error' => '物品名称、冰箱、分类为必填项'], 400);
        }
        $stmt = $db->prepare('INSERT INTO items (name, category_id, fridge_id, quantity, unit, production_date, shelf_life_value, shelf_life_unit, storage_type, added_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $data['name'],
            $data['category_id'],
            $data['fridge_id'],
            $data['quantity'] ?? 1,
            $data['unit'] ?? '个',
            $data['production_date'] ?? null,
            $data['shelf_life_value'] ?? null,
            $data['shelf_life_unit'] ?? null,
            $data['storage_type'] ?? null,
            $data['added_date'] ?? date('Y-m-d'),
            $data['notes'] ?? ''
        ]);
        jsonResponse(['id' => (int)$db->lastInsertId(), 'message' => '物品添加成功'], 201);
        break;

    case 'PUT':
        $data = getJsonInput();
        $id = $data['id'] ?? getParam('id');
        if (!$id) jsonResponse(['error' => '缺少物品ID'], 400);
        $stmt = $db->prepare('UPDATE items SET name = ?, category_id = ?, fridge_id = ?, quantity = ?, unit = ?, production_date = ?, shelf_life_value = ?, shelf_life_unit = ?, storage_type = ?, notes = ? WHERE id = ?');
        $stmt->execute([
            $data['name'] ?? '',
            $data['category_id'] ?? 0,
            $data['fridge_id'] ?? 0,
            $data['quantity'] ?? 1,
            $data['unit'] ?? '个',
            $data['production_date'] ?? null,
            $data['shelf_life_value'] ?? null,
            $data['shelf_life_unit'] ?? null,
            $data['storage_type'] ?? null,
            $data['notes'] ?? '',
            $id
        ]);
        jsonResponse(['message' => '物品更新成功']);
        break;

    case 'DELETE':
        $id = getParam('id');
        if (!$id) jsonResponse(['error' => '缺少物品ID'], 400);
        $stmt = $db->prepare('DELETE FROM items WHERE id = ?');
        $stmt->execute([$id]);
        jsonResponse(['message' => '物品已删除']);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
