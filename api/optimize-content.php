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

function count_word_occurrences(string $text, string $phrase): int {
    $text_lower = mb_strtolower($text, 'UTF-8');
    $phrase_lower = mb_strtolower($phrase, 'UTF-8');
    $count = 0;
    $pos = 0;
    while (($pos = mb_strpos($text_lower, $phrase_lower, $pos)) !== false) {
        $count++;
        $pos += mb_strlen($phrase_lower, 'UTF-8');
    }
    return $count;
}

function analyze_content_densities(string $html, array $keywords_data): array {
    $plain = extract_plain_text($html);
    $words = array_filter(
        preg_split('/\s+/u', $plain),
        fn($w) => mb_strlen($w) > 0
    );
    $total_words = count($words);
    
    $analysis = [];
    foreach ($keywords_data as $term => $density_range) {
        $occurrences = count_word_occurrences($plain, $term);
        $current_density = $total_words > 0 ? round(($occurrences / $total_words) * 100, 2) : 0;
        
        preg_match('/(\d+\.?\d*)-(\d+\.?\d*)/', $density_range, $matches);
        $target_min = (float)($matches[1] ?? 0);
        $target_max = (float)($matches[2] ?? 100);
        $is_ok = $current_density >= $target_min && $current_density <= $target_max;
        
        $analysis[$term] = [
            'occurrences' => $occurrences,
            'current_density' => $current_density,
            'target_min' => $target_min,
            'target_max' => $target_max,
            'is_ok' => $is_ok,
            'target_occurrences' => [
                ceil($target_min * $total_words / 100),
                floor($target_max * $total_words / 100)
            ]
        ];
    }
    
    return [
        'total_words' => $total_words,
        'keywords' => $analysis
    ];
}

function call_openai_optimize(array $config, string $prompt): array {
    $payload = [
        'model' => $config['openai']['model'],
        'temperature' => 0.2,
        'messages' => [
            ['role' => 'system', 'content' => 'Eres un experto en optimización SEO. Tu tarea es ajustar HTML para cumplir exactamente restricciones de longitud y densidades de keywords. Devuelves SOLO HTML valido, sin explicaciones, sin markdown.'],
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
        CURLOPT_TIMEOUT => 90,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        error_log('OpenAI optimize error: ' . $error);
        return ['error' => 'OpenAI request failed: ' . ($error ?: 'unknown')];
    }
    
    if ($httpCode >= 400) {
        error_log('OpenAI optimize HTTP ' . $httpCode . ': ' . substr($response, 0, 200));
        return ['error' => 'OpenAI error (HTTP ' . $httpCode . ')'];
    }
    
    $data = json_decode($response, true);
    if (!isset($data['choices'][0]['message']['content'])) {
        error_log('OpenAI optimize missing content');
        return ['error' => 'OpenAI response invalid'];
    }
    $html = $data['choices'][0]['message']['content'];
    
    $html = preg_replace('/^```html\s*/i', '', $html);
    $html = preg_replace('/^```\s*/i', '', $html);
    $html = preg_replace('/\s*```\s*$/i', '', $html);
    $html = trim($html);
    
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

// Extract density targets
$densityTargets = [];
foreach (($analysis['keywords_semanticas'] ?? []) as $item) {
    $term = $item['term'] ?? '';
    $dens = $item['densidad_recomendada'] ?? '';
    if ($term !== '' && $dens !== '') {
        $densityTargets[$term] = $dens;
    }
}

$longitud = $analysis['longitud_texto'] ?? [];
$minWords = (int) ($longitud['minima_competitiva'] ?? 600);
$minWords = max(600, $minWords);

// Analyze current content
$current = analyze_content_densities($html, $densityTargets);
$current_words = $current['total_words'];
$current_keywords = $current['keywords'];

error_log('Optimize: Current words=' . $current_words . ', Target=' . $minWords);
foreach ($current_keywords as $term => $data) {
    error_log('  ' . $term . ': ' . $data['current_density'] . '% (target ' . $data['target_min'] . '-' . $data['target_max'] . '%)');
}

// Build adjustment prompt
$prompt = "Optimiza este HTML para SEO. REQUISITOS OBLIGATORIOS:\n\n";
$prompt .= "1. LONGITUD: Debe tener " . $minWords . "+ palabras (actualmente tiene " . $current_words . ")\n";
$prompt .= "   - Si faltan palabras, expande con contenido relevante\n";
$prompt .= "   - Agrando parrafos, agrega ejemplos, detalles\n\n";

$prompt .= "2. DENSIDADES DE KEYWORDS - DEBEN ESTAR EN ESTOS RANGOS:\n";
foreach ($current_keywords as $term => $data) {
    $target_range = $densityTargets[$term] ?? '';
    $prompt .= "   \"" . $term . "\": " . $target_range . " (" . $data['target_occurrences'][0] . "-" . $data['target_occurrences'][1] . " veces)\n";
    if (!$data['is_ok']) {
        $prompt .= "      [FUERA DE RANGO] Actualmente: " . $data['current_density'] . "% (" . $data['occurrences'] . " veces)\n";
    }
}

$prompt .= "\n3. ESTRATEGIA DE AJUSTE:\n";
$prompt .= "   - Expande contenido si faltan palabras\n";
$prompt .= "   - Distribuye keywords balanceadamente en todo el articulo\n";
$prompt .= "   - Usa sinonimos para variar el texto\n";
$prompt .= "   - Mantén primera mención en <strong></strong>\n";
$prompt .= "   - Devuelve SOLO HTML valido\n\n";

$prompt .= "HTML a optimizar:\n" . substr($html, 0, 1000) . "...\n";

$optimized = call_openai_optimize($config, $prompt);
if (!empty($optimized['error'])) {
    error_log('Optimize failed: ' . $optimized['error']);
    json_response(['error' => 'No se pudo optimizar: ' . $optimized['error']], 500);
}

$optimized_html = $optimized['html'] ?? '';

// Verify optimization
$after = analyze_content_densities($optimized_html, $densityTargets);
error_log('After optimize: words=' . $after['total_words']);
foreach ($after['keywords'] as $term => $data) {
    error_log('  ' . $term . ': ' . $data['current_density'] . '% (OK: ' . ($data['is_ok'] ? 'YES' : 'NO') . ')');
}

json_response([
    'adjusted_html' => $optimized_html,
    'before' => [
        'words' => $current_words,
        'target' => $minWords,
        'densities' => array_map(fn($d) => [
            'current' => $d['current_density'],
            'target' => $d['target_min'] . '-' . $d['target_max'],
            'ok' => $d['is_ok']
        ], $current_keywords)
    ],
    'after' => [
        'words' => $after['total_words'],
        'target' => $minWords,
        'densities' => array_map(fn($d) => [
            'current' => $d['current_density'],
            'target' => $d['target_min'] . '-' . $d['target_max'],
            'ok' => $d['is_ok']
        ], $after['keywords'])
    ]
], 200);
