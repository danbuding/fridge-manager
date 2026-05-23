<?php
require_once __DIR__ . '/config.php';

$method = getMethod();

if ($method === 'GET') {
    $db = getDB();
    $stmt = $db->query('SELECT * FROM categories ORDER BY sort_order');
    jsonResponse($stmt->fetchAll());
}

jsonResponse(['error' => 'Method not allowed'], 405);
