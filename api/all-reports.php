<?php
$rootDir = dirname(__DIR__);
$logDir = $rootDir . '/logs';
$logFile = $logDir . '/api-error.log';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
ini_set('log_errors', '1');
ini_set('error_log', $logFile);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    json_response(['error' => 'Metodo no permitido'], 405);
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
if ($limit <= 0 || $limit > 1000) {
    $limit = 200;
}

$pdo = require $rootDir . '/includes/db.php';
try {
    $stmt = $pdo->prepare('SELECT id, keyword_hash, keywords, created_at FROM semantic_analysis ORDER BY created_at DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('All reports failed: ' . $e->getMessage());
    json_response(['error' => 'No se pudo cargar el historial.'], 500);
}

json_response(['items' => $items]);
