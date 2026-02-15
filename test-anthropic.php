<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

$rootDir = __DIR__;
$config = require $rootDir . '/config.php';
require $rootDir . '/includes/admin.php';
require_admin_token($config);

echo "<h1>Test Anthropic (Claude) API</h1>";

$apiKey = $config['anthropic']['api_key'] ?? '';
$model = $config['anthropic']['model'] ?? 'claude-3-5-sonnet-20241022';

echo "<p><strong>API Key:</strong> " . (strlen($apiKey) > 0 ? substr($apiKey, 0, 15) . "..." : "NO CONFIGURADA") . "</p>";
echo "<p><strong>Model:</strong> $model</p>";
echo "<p><strong>Max Tokens:</strong> " . ($config['anthropic']['max_tokens'] ?? 4096) . "</p>";

if (empty($apiKey)) {
    die("<h2 style='color:red'>Error: API key no configurada</h2>");
}

echo "<h2>Test 1: Simple JSON response</h2>";
$payload = [
    'model' => $model,
    'max_tokens' => 500,
    'temperature' => 0.2,
    'system' => 'Eres un experto que responde solo con JSON valido, sin explicaciones, sin markdown.',
    'messages' => [
        ['role' => 'user', 'content' => 'Responde con un JSON simple: {"test": "hola", "status": "success"}']
    ]
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 60,
]);
$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p>HTTP Code: $httpCode</p>";

if ($httpCode === 200) {
    $data = json_decode($response, true);
    $content = $data['content'][0]['text'] ?? 'No content';
    echo "<p style='color:green'><strong>✓ Respuesta:</strong></p>";
    echo "<pre>" . htmlspecialchars($content) . "</pre>";
    
    // Try to parse as JSON
    $json = json_decode($content, true);
    if ($json) {
        echo "<p style='color:green'><strong>✓ JSON válido parseado</strong></p>";
    } else {
        echo "<p style='color:orange'><strong>⚠️ Respuesta no es JSON válido</strong></p>";
    }
} else {
    echo "<p style='color:red'><strong>✗ Error HTTP $httpCode</strong></p>";
    $data = json_decode($response, true);
    echo "<pre>" . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . "</pre>";
}

echo "<h2>Test 2: Embedded Markdown check</h2>";
$payload2 = [
    'model' => $model,
    'max_tokens' => 300,
    'temperature' => 0.2,
    'system' => 'Eres un experto que responde solo con JSON valido puro, sin markdown.',
    'messages' => [
        ['role' => 'user', 'content' => 'Devuelve: {"key": "value", "number": 42}']
    ]
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_POSTFIELDS => json_encode($payload2),
    CURLOPT_TIMEOUT => 60,
]);
$response2 = curl_exec($ch);
$httpCode2 = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p>HTTP Code: $httpCode2</p>";

if ($httpCode2 === 200) {
    $data2 = json_decode($response2, true);
    $content2 = $data2['content'][0]['text'] ?? 'No content';
    echo "<p><strong>Respuesta raw:</strong></p>";
    echo "<pre>" . htmlspecialchars($content2) . "</pre>";
    
    // Check for markdown wrapping
    if (strpos($content2, '```') !== false) {
        echo "<p style='color:orange'><strong>⚠️ DETECTADO: Markdown wrapping (```)</strong></p>";
    } else {
        echo "<p style='color:green'><strong>✓ Sin markdown wrapping</strong></p>";
    }
} else {
    echo "<p style='color:red'><strong>✗ Error HTTP $httpCode2</strong></p>";
}
?>
