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

set_exception_handler(function (Throwable $e) {
    error_log('Uncaught exception: ' . $e->getMessage());
    error_log($e->getTraceAsString());
    json_response(['error' => 'Error interno'], 500);
});

set_error_handler(function (int $severity, string $message, string $file, int $line) {
    error_log("PHP error [$severity] $message in $file:$line");
    return false;
});

function call_openai_html(array $config, string $prompt): array {
    $payload = [
        'model' => $config['openai']['model'],
        'temperature' => 0.2,
        'messages' => [
            ['role' => 'system', 'content' => 'Eres un redactor SEO. Devuelves solo HTML valido, sin explicaciones.'],
            ['role' => 'user', 'content' => $prompt]
        ]
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['openai']['api_key'],
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => $config['openai']['timeout'] ?? 30,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        error_log('OpenAI generate failed: ' . ($error ?: 'unknown'));
        return ['error' => $error ?: 'OpenAI request failed'];
    }
    if ($httpCode >= 400) {
        error_log('OpenAI generate HTTP ' . $httpCode . ' response: ' . $response);
        return ['error' => 'OpenAI HTTP ' . $httpCode];
    }
    $data = json_decode($response, true);
    if (!isset($data['choices'][0]['message']['content'])) {
        error_log('OpenAI generate missing content: ' . $response);
        return ['error' => 'OpenAI response missing content', 'raw' => $data];
    }
    $html = $data['choices'][0]['message']['content'];
    $html = preg_replace('/:contentReference\\[[^\\]]+\\]\\{[^\\}]+\\}/', '', $html);
    return ['html' => $html];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    error_log('generate-content: invalid method ' . $method);
    json_response(['error' => 'Metodo no permitido'], 405);
}

try {
    $config = require $rootDir . '/config.php';
    require $rootDir . '/includes/tfidf.php';
} catch (Throwable $e) {
    error_log('generate-content: config/include failed: ' . $e->getMessage());
    json_response(['error' => 'Error interno'], 500);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
$keywordHash = is_array($payload) ? ($payload['keyword_hash'] ?? '') : '';
if (!is_string($keywordHash) || $keywordHash === '') {
    error_log('generate-content: missing keyword_hash');
    json_response(['error' => 'Informe no seleccionado.'], 400);
}

$pdo = require $rootDir . '/includes/db.php';
$stmt = $pdo->prepare('SELECT result_json, keywords FROM semantic_analysis WHERE keyword_hash = ? ORDER BY created_at DESC LIMIT 1');
$stmt->execute([$keywordHash]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['error' => 'Informe no encontrado.'], 404);
}

$analysis = json_decode($row['result_json'], true);
if (!is_array($analysis)) {
    json_response(['error' => 'Informe invalido.'], 500);
}

$keywords = is_string($row['keywords']) ? $row['keywords'] : '';
$longitud = $analysis['longitud_texto'] ?? [];
$rango = $longitud['rango_recomendado'] ?? '';
$min = (int) ($longitud['minima_competitiva'] ?? 600);
$min = max(600, $min);

$densidades = [];
foreach (($analysis['keywords_semanticas'] ?? []) as $item) {
    $term = $item['term'] ?? '';
    $dens = $item['densidad_recomendada'] ?? '';
    if ($term !== '' && $dens !== '') {
        $densidades[] = $term . ' ' . $dens;
    }
}
$densLine = $densidades ? implode(', ', $densidades) : '';

$estructura = $analysis['estructura_propuesta'] ?? [];
$h1 = $estructura['h1'] ?? '';
$secciones = $estructura['secciones'] ?? [];
$seccionLines = [];
foreach ($secciones as $sec) {
    $h2 = $sec['h2'] ?? '';
    $h3 = $sec['h3'] ?? [];
    $len = $sec['longitud_palabras'] ?? '';
    $line = trim($h2);
    if ($h3) {
        $line .= ' | H3: ' . implode(', ', $h3);
    }
    if ($len !== '') {
        $line .= ' | ' . $len . ' palabras';
    }
    if ($line !== '') {
        $seccionLines[] = $line;
    }
}

$prompt = "Genera un articulo HTML completo y profesional en espanol que cumpla TODAS las directrices del informe.\n";
$prompt .= "Longitud total: " . ($rango !== '' ? $rango : $min) . " palabras (minimo " . $min . ").\n";
if ($densLine !== '') {
    $prompt .= "Densidades objetivo: " . $densLine . ".\n";
}
if ($h1 !== '') {
    $prompt .= "H1: " . $h1 . ".\n";
}
if ($seccionLines) {
    $prompt .= "Estructura H2/H3 y longitudes:\n- " . implode(\"\\n- \", $seccionLines) . \"\\n\";
}
$prompt .= "Keywords principales: " . $keywords . ".\n";
$prompt .= "Devuelve SOLO HTML valido con titulos, parrafos y listas cuando corresponda. No incluyas explicaciones ni markdown.";

$ai = call_openai_html($config, $prompt);
if (!empty($ai['error'])) {
    json_response(['error' => $ai['error']], 500);
}

json_response(['html' => $ai['html'] ?? '']);
