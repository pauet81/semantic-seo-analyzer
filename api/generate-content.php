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

// Target words inside recommended range if available.
[$minRange, $maxRange] = parse_density_range($rango);
$targetWords = $min;
if ($minRange > 0 && $maxRange > 0 && $maxRange >= $minRange) {
    $targetWords = (int) round(($minRange + $maxRange) / 2);
    $targetWords = max($min, $targetWords);
}

$densityTargets = [];
foreach (($analysis['keywords_semanticas'] ?? []) as $item) {
    $term = $item['term'] ?? '';
    $dens = $item['densidad_recomendada'] ?? '';
    if ($term !== '' && $dens !== '') {
        $densityTargets[$term] = $dens;
    }
}

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

$prompt = "Eres un redactor SEO experto. Tu tarea es generar un articulo que CUMPLA 100% el informe. ";
$prompt .= "Devuelve SOLO HTML valido (sin markdown, sin explicaciones).\n\n";

$prompt .= "DATOS DEL INFORME:\n";
$prompt .= "- Keywords objetivo: " . $keywords . "\n";
$prompt .= "- Longitud: objetivo " . $targetWords . " palabras (minimo " . $min . ")\n";
if ($rango !== '') {
    $prompt .= "- Rango recomendado: " . $rango . "\n";
}
$prompt .= "- Legibilidad objetivo: Flesch 70+ (texto claro, frases cortas)\n";
$intent = $analysis['intencion_tono'] ?? [];
if ($intent) {
    $prompt .= "- Intencion: " . ($intent['intencion'] ?? '-') . "\n";
    $prompt .= "- Tono: " . ($intent['tono'] ?? '-') . "\n";
    $prompt .= "- Nivel: " . ($intent['nivel_profundidad'] ?? '-') . "\n";
    $prompt .= "- Contexto emocional: " . ($intent['contexto_emocional'] ?? '-') . "\n";
}
$prompt .= "- H1 sugerido: " . ($h1 !== '' ? $h1 : 'usa un H1 adecuado') . "\n";
if ($seccionLines) {
    $prompt .= "- Estructura recomendada (H2/H3):\n";
    foreach ($seccionLines as $line) {
        $prompt .= "  * " . $line . "\n";
    }
}

$prompt .= "\nDENSIDADES OBLIGATORIAS:\n";
foreach ($densityTargets as $term => $densRange) {
    [$minD, $maxD] = parse_density_range($densRange);
    $minOcc = (int) floor(($minD / 100) * $targetWords);
    $maxOcc = (int) ceil(($maxD / 100) * $targetWords);
    $prompt .= "- \"" . $term . "\": " . $densRange . " (" . $minOcc . "-" . $maxOcc . " menciones)\n";
}

$clusters = $analysis['clusters_seo'] ?? [];
if ($clusters) {
    $prompt .= "\nCLUSTERS SEO (orden de importancia):\n";
    foreach ($clusters as $cluster) {
        $prompt .= "- " . ($cluster['cluster'] ?? '') . " | score " . ($cluster['salient_score'] ?? '-') . " | cobertura " . ($cluster['cobertura_top'] ?? '-') . " | profundidad " . ($cluster['profundidad_palabras'] ?? '-') . " palabras\n";
        $sub = $cluster['subtemas'] ?? [];
        if ($sub) {
            $prompt .= "  Subtemas: " . implode(', ', $sub) . "\n";
        }
    }
}

$op = $analysis['oportunidades'] ?? [];
if ($op) {
    $prompt .= "\nOPORTUNIDADES:\n";
    $cg = $op['content_gaps'] ?? [];
    $fa = $op['formatos_ausentes'] ?? [];
    $pr = $op['preguntas_sin_responder'] ?? [];
    $id = $op['ideas_diferenciacion'] ?? [];
    if ($cg) $prompt .= "- Content gaps: " . implode('; ', $cg) . "\n";
    if ($fa) $prompt .= "- Formatos ausentes: " . implode('; ', $fa) . "\n";
    if ($pr) $prompt .= "- Preguntas sin responder: " . implode('; ', $pr) . "\n";
    if ($id) $prompt .= "- Ideas diferenciacion: " . implode('; ', $id) . "\n";
}

$prompt .= "\nREGLAS ESTRICTAS:\n";
$prompt .= "1) Longitud: minimo " . $min . " palabras, objetivo " . $targetWords . ".\n";
$prompt .= "2) Densidades: cumple cada rango exactamente. No excedas.\n";
$prompt .= "3) Legibilidad: Flesch 70+ (frases cortas, listas si ayuda).\n";
$prompt .= "4) Primera mencion de cada keyword en <strong>.\n";
$prompt .= "5) HTML valido, etiquetas bien cerradas.\n";
$prompt .= "6) Sin markdown ni texto fuera del HTML.\n";

json_response([
    'prompt' => $prompt,
]);
