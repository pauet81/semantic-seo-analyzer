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
$stmt = $pdo->prepare('SELECT result_json, tfidf_json, keywords, created_at FROM semantic_analysis WHERE keyword_hash = ? ORDER BY created_at DESC LIMIT 1');
$stmt->execute([$keywordHash]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['error' => 'Informe no encontrado.'], 404);
}

$analysis = json_decode($row['result_json'], true);
if (!is_array($analysis)) {
    json_response(['error' => 'Informe invalido.'], 500);
}

$text = extract_plain_text($html);
$tokens = sem_tokenize($text, sem_stopwords_es());
$wordCount = count($tokens);

$length = $analysis['longitud_texto'] ?? [];
$range = $length['rango_recomendado'] ?? '';
[$minRange, $maxRange] = parse_density_range($range);
$minCompetitive = (int) ($length['minima_competitiva'] ?? 0);
$minRequired = max(600, $minCompetitive, (int) $minRange);

$lengthScore = 0.0;
if ($wordCount >= $minRequired && $maxRange > 0 && $wordCount <= $maxRange) {
    $lengthScore = 1.0;
} elseif ($wordCount >= $minRequired && $maxRange === 0) {
    $lengthScore = 1.0;
} elseif ($minRequired > 0) {
    $lengthScore = max(0.0, min(1.0, $wordCount / $minRequired));
}

$termStats = [];
$termScores = [];
foreach (($analysis['keywords_semanticas'] ?? []) as $item) {
    $term = $item['term'] ?? '';
    $densityRange = $item['densidad_recomendada'] ?? '';
    if ($term === '' || $wordCount === 0) {
        continue;
    }
    $termTokens = sem_tokenize($term, []);
    $occ = count_phrase_occurrences($tokens, $termTokens);
    $density = ($wordCount > 0) ? ($occ / $wordCount) * 100 : 0;
    [$minD, $maxD] = parse_density_range($densityRange);
    $ok = ($minD === 0.0 && $maxD === 0.0) ? false : ($density >= $minD && $density <= $maxD);
    $score = 0.0;
    if ($ok) {
        $score = 1.0;
    } elseif ($minD > 0.0) {
        $score = max(0.0, 1 - (($minD - $density) / $minD));
    }
    $score = max(0.0, min(1.0, $score));
    $termScores[] = $score;
    $termStats[] = [
        'term' => $term,
        'density' => round($density, 2),
        'target' => $densityRange,
        'ok' => $ok,
        'occurrences' => $occ
    ];
}

$avgTermScore = $termScores ? array_sum($termScores) / count($termScores) : 0.0;
$score = (0.6 * $avgTermScore + 0.4 * $lengthScore) * 100;
$score = max(0, min(100, $score));

$insights = [];
if ($wordCount < $minRequired) {
    $insights[] = 'El contenido es corto: ' . $wordCount . ' palabras (minimo ' . $minRequired . ').';
}
foreach ($termStats as $stat) {
    if (!$stat['ok']) {
        $insights[] = 'Ajusta la densidad de "' . $stat['term'] . '" a ' . $stat['target'] . ' (actual ' . $stat['density'] . '%).';
    }
}
if (!$insights) {
    $insights[] = 'El contenido cumple longitud y densidades clave.';
}

json_response([
    'score' => (int) round($score),
    'word_count' => $wordCount,
    'min_required' => $minRequired,
    'term_stats' => $termStats,
    'insights' => $insights
]);
