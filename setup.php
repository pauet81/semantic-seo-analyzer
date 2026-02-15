<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

$rootDir = __DIR__;
$config = require $rootDir . '/config.php';
require $rootDir . '/includes/admin.php';
require_admin_token($config);

echo "<h1>Importar Schema SQL</h1>";

// Conectar a la BD
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', 
    $config['db']['host'], 
    $config['db']['name'], 
    $config['db']['charset']
);

try {
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    
    $sqlFile = $rootDir . '/sql/schema.sql';
    if (!file_exists($sqlFile)) {
        die("Error: No se encontró sql/schema.sql");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Ejecutar cada statement por separado
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
            echo "✓ Ejecutado: " . substr($statement, 0, 50) . "...<br>";
        }
    }
    
    echo "<h2 style='color:green'>✓ Schema importado correctamente</h2>";
    
    // Listar tablas
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<h3>Tablas creadas:</h3>";
    echo "<ul>";
    foreach ($tables as $table) {
        $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        echo "<li><strong>$table</strong> ($count registros)</li>";
    }
    echo "</ul>";
    
    echo "<p><strong>Siguiente paso:</strong> Puedes eliminar este archivo (setup.php) del servidor.</p>";
    
} catch (PDOException $e) {
    echo "<h2 style='color:red'>✗ Error:</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>
