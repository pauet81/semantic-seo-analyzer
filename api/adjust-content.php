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
require $rootDir . '/includes/tfidf.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function extract_plain_text(string $html): string {
    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', ' ', $html);
    $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', ' ', $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function call_openai_text(array $config, string $prompt): array {
    $payload = [
        'model' => $config['openai']['model'],
        'temperature' => 0.2,
        'messages' => [
            ['role' => 'system', 'content' => 'Eres un editor SEO. Devuelves solo HTML valido, sin explicaciones.'],
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
    curl_close($ch);

    if ($response === false) {
        return ['error' => $error ?: 'OpenAI request failed'];
    }
    $data = json_decode($response, true);
    if (!isset($data['choices'][0]['message']['content'])) {
        return ['error' => 'OpenAI response missing content', 'raw' => $data];
    }
    $html = $data['choices'][0]['message']['content'];
    // Strip OpenAI citation artifacts like: :contentReference[oaicite:0]{index=0}
    $html = preg_replace('/:contentReference\\[[^\\]]+\\]\\{[^\\}]+\\}/', '', $html);
    return ['html' => $html];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    json_response(['error' => 'Metodo no permitido'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
$html = is_array($payload) ? ($payload['html'] ?? '') : '';
$keywordHash = is_array($payload) ? ($payload['keyword_hash'] ?? '') : '';
if (!is_string($html) || trim($html) === '') {
    json_response(['error' => 'Contenido HTML requerido.'], 400);
}
if (!is_string($keywordHash) || $keywordHash === '') {
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
$plain = extract_plain_text($html);

$longitud = $analysis['longitud_texto'] ?? [];
$minWords = (int) ($longitud['minima_competitiva'] ?? 600);
$range = $longitud['rango_recomendado'] ?? '';
$densidades = [];
foreach (($analysis['keywords_semanticas'] ?? []) as $item) {
    $term = $item['term'] ?? '';
    $dens = $item['densidad_recomendada'] ?? '';
    if ($term !== '' && $dens !== '') {
        $densidades[] = $term . ' ' . $dens;
    }
}

$prompt = "Ajusta el siguiente HTML para cumplir las directrices del informe SEO.\n";
$prompt .= "Requisitos:\n";
$prompt .= "- Mantener idioma y tema original.\n";
$prompt .= "- Longitud total: " . ($range !== '' ? $range : $minWords) . " palabras (minimo " . max(600, $minWords) . ").\n";
if ($densidades) {
    $prompt .= "- Densidades objetivo: " . implode(', ', $densidades) . ".\n";
}
$prompt .= "- Mantener estructura HTML basica (titulos, parrafos, listas) y devolver SOLO HTML valido.\n";
$prompt .= "- No anadir explicaciones ni markdown.\n\n";
$prompt .= "HTML ORIGINAL:\n" . $html . "\n";

$ai = call_openai_text($config, $prompt);
if (!empty($ai['error'])) {
    json_response(['error' => $ai['error']], 500);
}

json_response([
    'ok' => true,
    'adjusted_html' => $ai['html'] ?? ''
]);
