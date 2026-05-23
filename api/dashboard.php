<?php
require_once __DIR__ . '/config.php';

if (getMethod() !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    $db = getDB();

    // 冰箱总数 & 物品总数 & 临期物品数
    $fridgeCount = $db->query('SELECT COUNT(*) FROM fridges')->fetchColumn();
    $itemCount = $db->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $expiringCount = (int)$db->query("
        SELECT COUNT(*) FROM items
        WHERE production_date IS NOT NULL AND shelf_life_value IS NOT NULL
        AND CASE shelf_life_unit
            WHEN 'year' THEN DATE_ADD(production_date, INTERVAL shelf_life_value YEAR)
            WHEN 'month' THEN DATE_ADD(production_date, INTERVAL shelf_life_value MONTH)
            ELSE DATE_ADD(production_date, INTERVAL shelf_life_value DAY)
        END BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ")->fetchColumn()
    + (int)$db->query("
        SELECT COUNT(*) FROM items
        WHERE production_date IS NULL AND shelf_life_value IS NOT NULL
        AND CASE shelf_life_unit
            WHEN 'year' THEN DATE_ADD(added_date, INTERVAL CEIL(shelf_life_value / 2) YEAR)
            WHEN 'month' THEN DATE_ADD(added_date, INTERVAL CEIL(shelf_life_value / 2) MONTH)
            ELSE DATE_ADD(added_date, INTERVAL CEIL(shelf_life_value / 2) DAY)
        END BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ")->fetchColumn();

    $expiringCount += (int)$db->query("
        SELECT COUNT(*) FROM items
        WHERE shelf_life_value IS NULL
        AND DATE_ADD(added_date, INTERVAL CASE WHEN storage_type = 'frozen' THEN 90 ELSE 5 END DAY) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ")->fetchColumn();

    // 各冰箱物品分布
    $fridgeStats = $db->query('SELECT f.id, f.name, f.location, COUNT(i.id) AS item_count, SUM(i.quantity) AS total_quantity FROM fridges f LEFT JOIN items i ON f.id = i.fridge_id GROUP BY f.id')->fetchAll();

    // 临期提醒（3天内过期）
    $expiringItems = $db->query("
        (SELECT i.name, i.quantity, i.unit,
            f.name AS fridge_name, c.icon AS category_icon,
            CASE i.shelf_life_unit
                WHEN 'year' THEN DATE_ADD(i.production_date, INTERVAL i.shelf_life_value YEAR)
                WHEN 'month' THEN DATE_ADD(i.production_date, INTERVAL i.shelf_life_value MONTH)
                ELSE DATE_ADD(i.production_date, INTERVAL i.shelf_life_value DAY)
            END AS computed_expire_date,
            DATEDIFF(
                CASE i.shelf_life_unit
                    WHEN 'year' THEN DATE_ADD(i.production_date, INTERVAL i.shelf_life_value YEAR)
                    WHEN 'month' THEN DATE_ADD(i.production_date, INTERVAL i.shelf_life_value MONTH)
                    ELSE DATE_ADD(i.production_date, INTERVAL i.shelf_life_value DAY)
                END,
                CURDATE()
            ) AS days_left
        FROM items i
        JOIN fridges f ON i.fridge_id = f.id
        JOIN categories c ON i.category_id = c.id
        WHERE i.production_date IS NOT NULL AND i.shelf_life_value IS NOT NULL
        HAVING computed_expire_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
        )
        UNION ALL
        (SELECT i.name, i.quantity, i.unit,
            f.name AS fridge_name, c.icon AS category_icon,
            CASE i.shelf_life_unit
                WHEN 'year' THEN DATE_ADD(i.added_date, INTERVAL CEIL(i.shelf_life_value / 2) YEAR)
                WHEN 'month' THEN DATE_ADD(i.added_date, INTERVAL CEIL(i.shelf_life_value / 2) MONTH)
                ELSE DATE_ADD(i.added_date, INTERVAL CEIL(i.shelf_life_value / 2) DAY)
            END AS computed_expire_date,
            DATEDIFF(
                CASE i.shelf_life_unit
                    WHEN 'year' THEN DATE_ADD(i.added_date, INTERVAL CEIL(i.shelf_life_value / 2) YEAR)
                    WHEN 'month' THEN DATE_ADD(i.added_date, INTERVAL CEIL(i.shelf_life_value / 2) MONTH)
                    ELSE DATE_ADD(i.added_date, INTERVAL CEIL(i.shelf_life_value / 2) DAY)
                END,
                CURDATE()
            ) AS days_left
        FROM items i
        JOIN fridges f ON i.fridge_id = f.id
        JOIN categories c ON i.category_id = c.id
        WHERE i.production_date IS NULL AND i.shelf_life_value IS NOT NULL
        HAVING computed_expire_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
        )
        UNION ALL
        (SELECT i.name, i.quantity, i.unit,
            f.name AS fridge_name, c.icon AS category_icon,
            DATE_ADD(i.added_date, INTERVAL CASE WHEN i.storage_type = 'frozen' THEN 90 ELSE 5 END DAY) AS computed_expire_date,
            DATEDIFF(DATE_ADD(i.added_date, INTERVAL CASE WHEN i.storage_type = 'frozen' THEN 90 ELSE 5 END DAY), CURDATE()) AS days_left
        FROM items i
        JOIN fridges f ON i.fridge_id = f.id
        JOIN categories c ON i.category_id = c.id
        WHERE i.shelf_life_value IS NULL
        HAVING computed_expire_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
        )
        ORDER BY computed_expire_date ASC
    ")->fetchAll();

    // 最近添加（7天内）
    $recentItems = $db->query("SELECT i.name, i.added_date, i.quantity, i.unit, f.name AS fridge_name, c.icon AS category_icon
        FROM items i
        JOIN fridges f ON i.fridge_id = f.id
        JOIN categories c ON i.category_id = c.id
        WHERE i.added_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY i.added_date DESC
        LIMIT 10")->fetchAll();

    // 常用物品排名（按名称聚合，不限制冰箱）
    $popularItems = $db->query("SELECT i.name, c.icon AS category_icon, SUM(i.quantity) AS total_quantity, COUNT(DISTINCT i.fridge_id) AS fridge_count
        FROM items i
        JOIN categories c ON i.category_id = c.id
        GROUP BY i.name
        ORDER BY total_quantity DESC
        LIMIT 10")->fetchAll();

    // 未标注物品（无生产日期且无保质期，存放超过3天）
    $warningItems = $db->query("SELECT i.name, i.added_date, i.quantity, i.unit, i.storage_type,
        f.name AS fridge_name, c.icon AS category_icon,
        DATEDIFF(CURDATE(), i.added_date) AS days_stored
        FROM items i
        JOIN fridges f ON i.fridge_id = f.id
        JOIN categories c ON i.category_id = c.id
        WHERE i.production_date IS NULL AND i.shelf_life_value IS NULL
        AND i.added_date < DATE_SUB(CURDATE(), INTERVAL 3 DAY)
        ORDER BY i.added_date ASC
        LIMIT 30")->fetchAll();

    // 已过期物品数
    $expiredCount = (int)$db->query("
        SELECT COUNT(*) FROM items
        WHERE production_date IS NOT NULL AND shelf_life_value IS NOT NULL
        AND CASE shelf_life_unit
            WHEN 'year' THEN DATE_ADD(production_date, INTERVAL shelf_life_value YEAR)
            WHEN 'month' THEN DATE_ADD(production_date, INTERVAL shelf_life_value MONTH)
            ELSE DATE_ADD(production_date, INTERVAL shelf_life_value DAY)
        END < CURDATE()
    ")->fetchColumn()
    + (int)$db->query("
        SELECT COUNT(*) FROM items
        WHERE production_date IS NULL AND shelf_life_value IS NOT NULL
        AND CASE shelf_life_unit
            WHEN 'year' THEN DATE_ADD(added_date, INTERVAL CEIL(shelf_life_value / 2) YEAR)
            WHEN 'month' THEN DATE_ADD(added_date, INTERVAL CEIL(shelf_life_value / 2) MONTH)
            ELSE DATE_ADD(added_date, INTERVAL CEIL(shelf_life_value / 2) DAY)
        END < CURDATE()
    ")->fetchColumn()
    + (int)$db->query("
        SELECT COUNT(*) FROM items
        WHERE shelf_life_value IS NULL
        AND DATE_ADD(added_date, INTERVAL CASE WHEN storage_type = 'frozen' THEN 90 ELSE 5 END DAY) < CURDATE()
    ")->fetchColumn();

    // 未标注预警数（两个日期都没有且存放超过3天）
    $warningCount = (int)$db->query("
        SELECT COUNT(*) FROM items
        WHERE production_date IS NULL AND shelf_life_value IS NULL
        AND added_date < DATE_SUB(CURDATE(), INTERVAL 3 DAY)
    ")->fetchColumn();

    jsonResponse([
        'summary' => [
            'fridge_count' => (int)$fridgeCount,
            'item_count' => (int)$itemCount,
            'expiring_count' => (int)$expiringCount,
            'expired_count' => $expiredCount,
            'warning_count' => $warningCount,
        ],
        'fridge_stats' => $fridgeStats,
        'expiring_items' => $expiringItems,
        'recent_items' => $recentItems,
        'popular_items' => $popularItems,
        'warning_items' => $warningItems,
    ]);
} catch (\Throwable $e) {
    jsonResponse(['error' => $e->getMessage() . ' (file: ' . basename($e->getFile()) . ':' . $e->getLine() . ')'], 500);
}
