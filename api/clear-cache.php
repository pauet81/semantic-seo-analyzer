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

$config = require $rootDir . '/config.php';
require $rootDir . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    json_response(['error' => 'Metodo no permitido'], 405);
}

$pdo = require $rootDir . '/includes/db.php';
try {
    $pdo->exec('DELETE FROM semantic_analysis');
    $pdo->exec('DELETE FROM serp_cache');
    $pdo->exec('DELETE FROM usage_log');
} catch (Throwable $e) {
    error_log('Clear cache failed: ' . $e->getMessage());
    json_response(['error' => 'No se pudo limpiar la base de datos.'], 500);
}

json_response(['ok' => true]);
