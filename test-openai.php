<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

$rootDir = __DIR__;
$config = require $rootDir . '/config.php';
require $rootDir . '/includes/admin.php';
require_admin_token($config);

echo "<h1>Test OpenAI API</h1>";

$apiKey = $config['openai']['api_key'] ?? '';
$model = $config['openai']['model'] ?? 'gpt-4-turbo';

echo "<p><strong>API Key:</strong> " . (strlen($apiKey) > 0 ? substr($apiKey, 0, 10) . "..." : "NO CONFIGURADA") . "</p>";
echo "<p><strong>Model:</strong> $model</p>";

if (empty($apiKey)) {
    die("<h2 style='color:red'>Error: API key no configurada</h2>");
}

// Test 1: Ver modelos disponibles
echo "<h2>Test 1: Listar modelos disponibles</h2>";
$ch = curl_init('https://api.openai.com/v1/models');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_TIMEOUT => 10,
]);
$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($response, true);
    $models = array_slice($data['data'] ?? [], 0, 10);
    echo "<p>Primeros 10 modelos disponibles:</p>";
    echo "<ul>";
    foreach ($models as $item) {
        $id = $item['id'] ?? 'unknown';
        $owner = $item['owned_by'] ?? 'unknown';
        echo "<li><strong>$id</strong> (by $owner)</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color:red'><strong>Error HTTP $httpCode</strong></p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
}

// Test 2: Hacer un chat simple
echo "<h2>Test 2: Chat simple (test de generación)</h2>";
$payload = [
    'model' => $model,
    'temperature' => 0.2,
    'max_tokens' => 50,
    'messages' => [
        ['role' => 'user', 'content' => 'Dime hola en 5 palabras']
    ]
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 30,
]);
$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p>HTTP Code: $httpCode</p>";

if ($httpCode === 200) {
    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? 'No content';
    echo "<p style='color:green'><strong>✓ Respuesta:</strong> $content</p>";
} else {
    echo "<p style='color:red'><strong>✗ Error HTTP $httpCode</strong></p>";
    $data = json_decode($response, true);
    $error = $data['error']['message'] ?? $response;
    echo "<pre>" . htmlspecialchars($error) . "</pre>";
}
?>
