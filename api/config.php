<?php
// 数据库配置 — 通过 install.php 自动生成，也可手动修改
define('DB_HOST', '{{DB_HOST}}');
define('DB_PORT', '{{DB_PORT}}');
define('DB_NAME', '{{DB_NAME}}');
define('DB_USER', '{{DB_USER}}');
define('DB_PASS', '{{DB_PASS}}');

// 连接数据库
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

// 输出 JSON 响应
function jsonResponse($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取请求体 JSON
function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?: [];
}

// 获取请求方法
function getMethod(): string {
    return $_SERVER['REQUEST_METHOD'];
}

// GET 参数获取
function getParam(string $key, $default = null) {
    return $_GET[$key] ?? $default;
}
