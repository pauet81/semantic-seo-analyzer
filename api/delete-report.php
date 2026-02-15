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

$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
if ($method !== 'POST') {
    json_response(['error' => 'Metodo no permitido'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$id = isset($data['id']) ? (int) $data['id'] : 0;
if ($id <= 0) {
    json_response(['error' => 'ID invalido.'], 400);
}

$pdo = require $rootDir . '/includes/db.php';
try {
    $stmt = $pdo->prepare('DELETE FROM semantic_analysis WHERE id = ?');
    $stmt->execute([$id]);
} catch (Throwable $e) {
    error_log('Delete report failed: ' . $e->getMessage());
    json_response(['error' => 'No se pudo eliminar el registro.'], 500);
}

json_response(['ok' => true]);
