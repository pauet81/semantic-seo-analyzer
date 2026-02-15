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

function extract_plain_text(string $html): string {
    $html = preg_replace('/<script\\b[^>]*>(.*?)<\\/script>/is', ' ', $html);
    $html = preg_replace('/<style\\b[^>]*>(.*?)<\\/style>/is', ' ', $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\\s+/u', ' ', $text);
    return trim($text);
}

function count_syllables(string $word): int {
    $word = mb_strtolower($word, 'UTF-8');
    $word = strtr($word, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u',
    ]);
    if (mb_strlen($word) < 2) return 1;

    $diptongos = ['ai', 'ei', 'oi', 'au', 'eu', 'ou', 'ia', 'ie', 'io', 'ua', 'ue', 'uo'];
    $syllables = 0;
    $previous_was_vowel = false;

    for ($i = 0; $i < mb_strlen($word); $i++) {
        $char = mb_substr($word, $i, 1);
        $is_vowel = in_array($char, ['a', 'e', 'i', 'o', 'u'], true);
        if ($is_vowel && !$previous_was_vowel) {
            $next_chars = mb_substr($word, $i, 2);
            if (!in_array($next_chars, $diptongos, true)) {
                $syllables++;
            }
        }
        $previous_was_vowel = $is_vowel;
    }

    return max(1, $syllables);
}

function count_total_syllables(string $text): int {
    $words = preg_split('/\\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $total = 0;
    foreach ($words as $word) {
        $word = preg_replace('/[^a-záéíóúñü]/ui', '', $word);
        if ($word !== '') {
            $total += count_syllables($word);
        }
    }
    return max(1, $total);
}

function calculate_flesch_readability(string $text): array {
    $text = trim($text);
    if ($text === '') {
        return ['score' => 0.0];
    }

    $words = preg_split('/\\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $word_count = is_array($words) ? count($words) : 0;
    if ($word_count === 0) {
        return ['score' => 0.0];
    }

    $sentence_count = preg_match_all('/[.!?]+/u', $text);
    if ($sentence_count === false || $sentence_count === 0) {
        $sentence_count = 1;
    }

    $syllable_count = count_total_syllables($text);

    $flesch_score = 206.835 - (1.015 * ($word_count / $sentence_count)) - (84.6 * ($syllable_count / $word_count));
    $flesch_score = max(0, min(100, $flesch_score));

    return ['score' => round($flesch_score, 1)];
}

function count_phrase_occurrences(array $tokens, array $phraseTokens): int {
    $count = 0;
    $len = count($phraseTokens);
    if ($len === 0) {
        return 0;
    }
    for ($i = 0; $i <= count($tokens) - $len; $i++) {
        $match = true;
        for ($j = 0; $j < $len; $j++) {
            if ($tokens[$i + $j] !== $phraseTokens[$j]) {
                $match = false;
                break;
            }
        }
        if ($match) {
            $count++;
        }
    }
    return $count;
}

function evaluate_generated_html(string $html, array $analysis): array {
    $plain = extract_plain_text($html);
    $normalized = sem_normalize_text($plain);
    $tokensAll = preg_split('/\\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    $wordCount = count($tokensAll);

    $length = $analysis['longitud_texto'] ?? [];
    $range = $length['rango_recomendado'] ?? '';
    [$minRange, $maxRange] = parse_density_range($range);
    $minCompetitive = (int) ($length['minima_competitiva'] ?? 0);
    $minRequired = max(600, $minCompetitive, (int) $minRange);

    $readability = calculate_flesch_readability($plain);
    $readabilityOk = (($readability['score'] ?? 0) >= 70);

    $termStats = [];
    $allDensitiesOk = true;
    foreach (($analysis['keywords_semanticas'] ?? []) as $item) {
        $term = $item['term'] ?? '';
        $densityRange = $item['densidad_recomendada'] ?? '';
        if ($term === '' || $wordCount === 0) {
            continue;
        }
        [$minD, $maxD] = parse_density_range($densityRange);
        $termNormalized = sem_normalize_text($term);
        $termTokens = preg_split('/\\s+/u', $termNormalized, -1, PREG_SPLIT_NO_EMPTY);
        $occ = count_phrase_occurrences($tokensAll, $termTokens);
        $density = ($occ / $wordCount) * 100;
        $ok = ($minD > 0.0 || $maxD > 0.0) && ($density >= $minD && $density <= $maxD);
        if (!$ok) {
            $allDensitiesOk = false;
        }
        $termStats[] = [
            'term' => $term,
            'density' => round($density, 2),
            'target' => $densityRange,
            'ok' => $ok,
            'occurrences' => $occ,
        ];
    }

    $lengthOk = ($wordCount >= $minRequired) && (($maxRange <= 0) || ($wordCount <= (int) $maxRange));

    return [
        'ok' => ($lengthOk && $allDensitiesOk && $readabilityOk),
        'word_count' => $wordCount,
        'min_required' => $minRequired,
        'max_recommended' => (int) $maxRange,
        'readability_score' => $readability['score'] ?? 0,
        'term_stats' => $termStats,
    ];
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

// Try to aim inside the recommended range when available (middle of the range).
[$minRange, $maxRange] = parse_density_range($rango);
$targetWords = $min;
if ($minRange > 0 && $maxRange > 0 && $maxRange >= $minRange) {
    $targetWords = (int) round(($minRange + $maxRange) / 2);
    $targetWords = max($min, $targetWords);
}

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
$prompt .= "OBJETIVO " . $targetWords . " palabras (minimo " . $min . ")\n";
$prompt .= "Estructura: Intro (150-220) + secciones H2/H3 segun el informe + Conclusion (150-220)\n\n";

$prompt .= "⚠️ RESTRICTION #2 - DENSIDADES EXACTAS Y BALANCEADAS:\n";
$prompt .= "CADA PALABRA CLAVE DEBE TENER EXACTAMENTE ESTAS MENCIONES:\n";
foreach ($densityTargets as $term => $densRange) {
    [$minD, $maxD] = parse_density_range($densRange);
    $minOcc = (int) floor(($minD / 100) * $targetWords);
    $maxOcc = (int) ceil(($maxD / 100) * $targetWords);
    $rangeMsg = $minOcc . "-" . $maxOcc;
    $prompt .= "   \"" . $term . "\" (" . $densRange . "): use EXACTAMENTE " . $rangeMsg . " veces\n";
}

$prompt .= "\n🎯 ESTRATEGIA DE DISTRIBUCION (CRITICA):\n";
$prompt .= "NO concentres palabras clave en pocas secciones. DISTRIBUYE:\n";
foreach ($densityTargets as $term => $densRange) {
    [$minD, $maxD] = parse_density_range($densRange);
    $minOcc = (int) floor(($minD / 100) * $targetWords);
    $maxOcc = (int) ceil(($maxD / 100) * $targetWords);
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
$prompt .= "- Legibilidad < 70 Flesch = INVALIDO (escribe mas claro)\n";
$prompt .= "- Una palabra clave con más menciones de lo especificado = INVALIDO\n";
$prompt .= "- Todas las menciones de un keyword en 1 sección = INVALIDO\n";
$prompt .= "- Markdown, explicaciones, HTML malformado = INVALIDO\n\n";

$prompt .= "📤 DEVUELVE: SOLO HTML valido con " . $min . "+ palabras y densidades perfectas.\n";

$ai = call_openai_html($config, $prompt);
if (!empty($ai['error'])) {
    json_response(['error' => $ai['error']], 500);
}

$htmlOut = $ai['html'] ?? '';
$evaluation = evaluate_generated_html($htmlOut, $analysis);

// One self-repair pass if output doesn't meet the report constraints.
if (!$evaluation['ok']) {
    $issues = [];
    if (($evaluation['word_count'] ?? 0) < ($evaluation['min_required'] ?? 0)) {
        $issues[] = 'Faltan palabras: ' . ($evaluation['word_count'] ?? 0) . ' (min ' . ($evaluation['min_required'] ?? 0) . ')';
    }
    if (($evaluation['readability_score'] ?? 0) < 70) {
        $issues[] = 'Legibilidad Flesch ' . ($evaluation['readability_score'] ?? 0) . ' (objetivo 70+)';
    }
    foreach (($evaluation['term_stats'] ?? []) as $stat) {
        if (!($stat['ok'] ?? false)) {
            $issues[] = 'Densidad "' . ($stat['term'] ?? '') . '" ' . ($stat['density'] ?? 0) . '% (objetivo ' . ($stat['target'] ?? '') . ')';
        }
    }

    $repairPrompt = "Corrige el siguiente HTML para cumplir EXACTAMENTE el informe SEO.\n\n";
    $repairPrompt .= "Requisitos obligatorios:\n";
    $repairPrompt .= "- Longitud: minimo " . $min . " palabras (objetivo " . $targetWords . ")\n";
    $repairPrompt .= "- Densidades: respetar rangos para cada keyword\n";
    $repairPrompt .= "- Legibilidad: Flesch 70+ (oraciones cortas, listas si ayuda)\n";
    $repairPrompt .= "- Devuelve SOLO HTML valido\n\n";
    $repairPrompt .= "Problemas detectados:\n- " . implode("\n- ", $issues) . "\n\n";
    $repairPrompt .= "HTML a corregir:\n" . $htmlOut;

    $fixed = call_openai_html($config, $repairPrompt);
    if (empty($fixed['error']) && !empty($fixed['html'])) {
        $htmlOut = $fixed['html'];
        $evaluation = evaluate_generated_html($htmlOut, $analysis);
    }
}

json_response([
    'html' => $htmlOut,
    'evaluation' => $evaluation,
]);
