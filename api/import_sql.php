<?php
// 冰箱库存管理系统 · SQL 导入（仅导入数据，不改变表结构）
require_once __DIR__ . '/config.php';

if (getMethod() !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    $db = getDB();
    $input = getJsonInput();
    $sqlText = $input['content'] ?? '';
    if (!$sqlText) {
        jsonResponse(['error' => '请提供 SQL 内容'], 400);
    }

    // 只允许操作的表
    $allowedTables = ['fridges', 'categories', 'items', 'transfer_logs'];

    $result = [
        'imported' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    // 提取所有 INSERT INTO 语句
    // 支持多行 VALUES，以分号结束
    $insertPattern = '/INSERT\s+INTO\s+`?(\w+)`?\s*\([^)]+\)\s*VALUES\s*\(.*?\)\s*;?\s*/is';
    // 对于多行 VALUES，需要更灵活的匹配
    // 先按分号分割语句
    $statements = splitSqlStatements($sqlText);

    $db->beginTransaction();
    try {
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if (empty($stmt)) continue;

            // 只处理 INSERT INTO
            if (!preg_match('/^INSERT\s+INTO\s+`?(\w+)`?\s*/i', $stmt, $m)) {
                continue;
            }

            $table = $m[1];
            if (!in_array($table, $allowedTables)) {
                $result['errors'][] = "跳过非允许表: {$table}";
                continue;
            }

            // 使用 INSERT IGNORE 跳过主键/唯一键冲突
            $insertStmt = preg_replace('/^INSERT\s+INTO/i', 'INSERT IGNORE INTO', $stmt);
            // 去掉末尾分号
            $insertStmt = rtrim($insertStmt, ';');

            $count = $db->exec($insertStmt);
            if ($count === false) {
                $errorInfo = $db->errorInfo();
                $result['errors'][] = "表 {$table} 执行失败: " . ($errorInfo[2] ?? '未知错误');
            } else {
                $result['imported'] += $count;
            }
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        $result['errors'][] = '导入中断: ' . $e->getMessage();
    }

    jsonResponse($result);

} catch (\Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

function splitSqlStatements(string $sql): array {
    // 移除注释
    $sql = preg_replace('/-- .*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

    $statements = [];
    $current = '';
    $depth = 0; // 跟踪括号嵌套，避免分号在 VALUES 括号内被误分割

    for ($i = 0; $i < strlen($sql); $i++) {
        $ch = $sql[$i];
        $current .= $ch;

        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
        } elseif ($ch === ';' && $depth === 0) {
            $trimmed = trim($current);
            if (!empty($trimmed)) {
                $statements[] = $trimmed;
            }
            $current = '';
        }
    }

    $trimmed = trim($current);
    if (!empty($trimmed)) {
        $statements[] = $trimmed;
    }

    return $statements;
}
