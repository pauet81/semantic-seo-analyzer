<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

$rootDir = __DIR__;
$config = require $rootDir . '/config.php';
require $rootDir . '/includes/admin.php';
require_admin_token($config);

echo "<h2>Configuración cargada:</h2>";
echo "DB Host: " . $config['db']['host'] . "<br>";
echo "DB Name: " . $config['db']['name'] . "<br>";
echo "DB User: " . $config['db']['user'] . "<br>";
echo "DB Pass: " . (isset($config['db']['pass']) && $config['db']['pass'] ? "***" : "VACIA") . "<br>";
echo "<br>";

echo "<h2>Test de conexión:</h2>";
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', 
    $config['db']['host'], 
    $config['db']['name'], 
    $config['db']['charset']
);
echo "DSN: " . $dsn . "<br>";

try {
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "<span style='color:green'><strong>✓ Conexión exitosa</strong></span><br>";
    
    // Verificar tablas
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<h2>Tablas en la BD:</h2>";
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>$table</li>";
    }
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<span style='color:red'><strong>✗ Error de conexión:</strong></span><br>";
    echo "Error: " . $e->getMessage() . "<br>";
}
?>
