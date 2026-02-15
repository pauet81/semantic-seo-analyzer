<?php
$rootDir = __DIR__;
$config = require $rootDir . '/config.php';
require $rootDir . '/includes/admin.php';
require_admin_token($config);

$logFile = $rootDir . '/logs/api-error.log';

if (!file_exists($logFile)) {
    die("No log file found at: $logFile");
}

$lines = file($logFile, FILE_SKIP_EMPTY_LINES);
$recentLines = array_slice($lines, -100); // Últimas 100 líneas

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Viewer</title>
    <style>
        body {
            font-family: monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        pre {
            background: #252526;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            border: 1px solid #3e3e42;
        }
        .error {
            color: #f48771;
        }
        .success {
            color: #89d185;
        }
        h1 {
            color: #4ec9b0;
        }
        .controls {
            margin-bottom: 20px;
        }
        button {
            background: #0e639c;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 3px;
            cursor: pointer;
        }
        button:hover {
            background: #1177bb;
        }
    </style>
</head>
<body>
    <h1>📋 API Error Log Viewer</h1>
    <div class="controls">
        <button onclick="location.reload()">🔄 Refrescar</button>
        <button onclick="clearLog()">🗑️ Limpiar log</button>
    </div>
    <pre><?php foreach ($recentLines as $line): ?>
<span class="<?php echo strpos($line, 'error') !== false || strpos($line, 'Error') !== false ? 'error' : 'success'; ?>"><?php echo htmlspecialchars($line); ?></span><?php endforeach; ?></pre>

    <script>
        function clearLog() {
            if (confirm('¿Seguro que deseas limpiar el log?')) {
                fetch('<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'clear=1'
                }).then(() => location.reload());
            }
        }
    </script>
</body>
</html>

<?php
// Permitir limpiar el log
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear'])) {
    file_put_contents($logFile, '');
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}
?>
