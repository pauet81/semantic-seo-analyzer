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
        'temperature' => 0.3,
        'messages' => [
            ['role' => 'system', 'content' => 'Eres un redactor SEO experto especialista en generar contenido largo, detallado y completamente optimizado para SEO. Tu UNICA tarea es seguir instrucciones al pie de la letra. Tu contenido DEBE cumplir exactamente: longitud total (>=1000 palabras), densidades de keywords, estructura H1/H2/H3, formato con negritas en keywords. Devuelves SOLO HTML valido, bien formateado, con todos los tags cerrados correctamente. NO incluyas explicaciones, markdown, o bloques de código. SIEMPRE asegura que el HTML tenga la longitud requerida.'],
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
        return ['error' => 'OpenAI request failed: ' . ($error ?: 'unknown')];
    }
    if ($httpCode >= 400) {
        error_log('OpenAI generate HTTP ' . $httpCode . ' response: ' . $response);
        return ['error' => 'OpenAI error (HTTP ' . $httpCode . '): ' . substr($response, 0, 200)];
    }
    $data = json_decode($response, true);
    if (!isset($data['choices'][0]['message']['content'])) {
        error_log('OpenAI generate missing content in response: ' . json_encode($data));
        return ['error' => 'OpenAI response invalid or missing content'];
    }
    $html = $data['choices'][0]['message']['content'];
    
    // Strip markdown code blocks if present
    $html = preg_replace('/^```html\s*/i', '', $html);
    $html = preg_replace('/^```\s*/i', '', $html);
    $html = preg_replace('/\s*```\s*$/i', '', $html);
    $html = trim($html);
    
    // Strip OpenAI citation artifacts
    $html = preg_replace('/:contentReference\\[[^\\]]+\\]\\{[^\\}]+\\}/', '', $html);
    
    error_log('OpenAI HTML generated (first 200 chars): ' . substr($html, 0, 200));
    
    return ['html' => $html];
}

function parse_density_range(string $range): array {
    $range = str_replace('%', '', $range);
    $parts = array_map('trim', explode('-', $range));
    if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
        return [(float) $parts[0], (float) $parts[1]];
    }
    if (is_numeric($range)) {
        $value = (float) $range;
        return [$value, $value];
    }
    return [0.0, 0.0];
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

// Extract density targets into structured format
$densityTargets = [];
foreach (($analysis['keywords_semanticas'] ?? []) as $item) {
    $term = $item['term'] ?? '';
    $dens = $item['densidad_recomendada'] ?? '';
    if ($term !== '' && $dens !== '') {
        $densityTargets[$term] = $dens;
    }
}

$prompt = "======================== TAREA CRITICA: GENERAR CONTENIDO LARGO Y DENSIDADES PERFECTAS ========================\n\n";

$prompt .= "⚠️ RESTRICTION #1 - LONGITUD:\n";
$prompt .= "MINIMO " . $min . " palabras (total, contando solo parrafos)\n";
$prompt .= "Estructura: Intro (150-200) + 4 secciones H2 (200-250 cada) + Conclusion (150-200) = " . $min . "+\n\n";

$prompt .= "⚠️ RESTRICTION #2 - DENSIDADES EXACTAS Y BALANCEADAS:\n";
$prompt .= "CADA PALABRA CLAVE DEBE TENER EXACTAMENTE ESTAS MENCIONES:\n";
foreach ($densityTargets as $term => $densRange) {
    [$minD, $maxD] = parse_density_range($densRange);
    $minOcc = (int) floor(($minD / 100) * $min);
    $maxOcc = (int) ceil(($maxD / 100) * $min);
    $rangeMsg = $minOcc . "-" . $maxOcc;
    $prompt .= "   \"" . $term . "\" (" . $densRange . "): use EXACTAMENTE " . $rangeMsg . " veces\n";
}

$prompt .= "\n🎯 ESTRATEGIA DE DISTRIBUCION (CRITICA):\n";
$prompt .= "NO concentres palabras clave en pocas secciones. DISTRIBUYE:\n";
foreach ($densityTargets as $term => $densRange) {
    [$minD, $maxD] = parse_density_range($densRange);
    $minOcc = (int) floor(($minD / 100) * $min);
    $maxOcc = (int) ceil(($maxD / 100) * $min);
    $rangeMsg = $minOcc . "-" . $maxOcc;
    $prompt .= "   \"" . $term . "\": " . $rangeMsg . " menciones → Intro (0-1) + Sec1 (0-1) + Sec2 (0-1) + Sec3 (0-1) + Sec4 (0-1) + Conc (0-1)\n";
}

$prompt .= "\n✅ ESTRUCTURA HTML:\n";
if ($h1 !== '') {
    $prompt .= "   <h1>" . $h1 . "</h1>\n";
}
$prompt .= "   <p>Intro: 150-200 palabras</p>\n";
$prompt .= "   <h2>Seccion 1</h2>\n";
$prompt .= "   <p>Parrafo 130-150 palabras</p>\n";
$prompt .= "   <p>Parrafo 130-150 palabras</p>\n";
$prompt .= "   <h2>Seccion 2</h2>\n";
$prompt .= "   ...[REPETIR ESTRUCTURA]...\n";
$prompt .= "   <p>Conclusion: 150-200 palabras</p>\n\n";

$prompt .= "📌 REGLAS DE KEYWORDS:\n";
$prompt .= "1. PRIMERA MENCION en cada sección: <strong>palabra clave</strong>\n";
$prompt .= "2. NO repitas mas veces que se indica\n";
$prompt .= "3. USA SINONIMOS en menciones posteriores (no siempre la misma frase)\n";
$prompt .= "4. DISTRIBUYE EN DIFERENTES PARRAFOS (no todos en uno)\n";
$prompt .= "5. Si necesitas mencionar en H2: incluye la palabra clave\n\n";

$prompt .= "🚫 ERRORES CRITICOS:\n";
$prompt .= "- Menos de " . $min . " palabras = INVALIDO\n";
$prompt .= "- Una palabra clave con más menciones de lo especificado = INVALIDO\n";
$prompt .= "- Todas las menciones de un keyword en 1 sección = INVALIDO\n";
$prompt .= "- Markdown, explicaciones, HTML malformado = INVALIDO\n\n";

$prompt .= "📤 DEVUELVE: SOLO HTML valido con " . $min . "+ palabras y densidades perfectas.\n";

$ai = call_openai_html($config, $prompt);
if (!empty($ai['error'])) {
    json_response(['error' => $ai['error']], 500);
}

json_response(['html' => $ai['html'] ?? '']);
