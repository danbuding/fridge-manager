<?php
// 冰箱库存管理系统 · SQL 完整导出（表结构 + 数据）
require_once __DIR__ . '/config.php';

if (getMethod() !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    $db = getDB();
    $exportTime = date('Y-m-d H:i:s');

    $sql = "-- 冰箱库存管理系统 · 数据库导出\n";
    $sql .= "-- 导出时间: {$exportTime}\n";
    $sql .= "-- 本文件可直接在 phpMyAdmin 或 mysql 命令行执行\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    // 表定义顺序（按外键依赖：先无依赖的表）
    $tables = ['fridges', 'categories', 'items', 'transfer_logs'];

    // DROP + CREATE
    foreach ($tables as $table) {
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
    }
    $sql .= "\n";

    foreach ($tables as $table) {
        $createStmt = $db->query("SHOW CREATE TABLE `{$table}`");
        $row = $createStmt->fetch(PDO::FETCH_NUM);
        $createSql = $row[1];
        // 移除 AUTO_INCREMENT 当前值，让导入时重新自增
        $createSql = preg_replace('/AUTO_INCREMENT=\d+\s/', '', $createSql);
        $sql .= $createSql . ";\n\n";
    }

    // INSERT DATA
    foreach ($tables as $table) {
        $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            continue;
        }

        // 获取列名
        $columns = array_keys($rows[0]);
        $colList = '`' . implode('`, `', $columns) . '`';

        $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES\n";
        $valueLines = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $vals[] = 'NULL';
                } else {
                    $vals[] = $db->quote($value);
                }
            }
            $valueLines[] = '(' . implode(', ', $vals) . ')';
        }
        $sql .= implode(",\n", $valueLines) . ";\n\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="fridge-export-' . date('Ymd-His') . '.sql"');
    echo $sql;
    exit;

} catch (\Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
