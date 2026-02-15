<?php
$rootDir = dirname(__FILE__);
try {
    $config = require $rootDir . '/config.php';
    require $rootDir . '/includes/admin.php';
    require_admin_token($config);
    $pdo = require $rootDir . '/includes/db.php';
    
    $stmt = $pdo->prepare('DELETE FROM serp_cache');
    $stmt->execute();
    $rowsDeleted = $stmt->rowCount();
    
    echo json_encode([
        'success' => true,
        'message' => 'SerpAPI cache cleared',
        'rows_deleted' => $rowsDeleted
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
