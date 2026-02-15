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

function count_syllables(string $word): int {
    $word = mb_strtolower($word, 'UTF-8');
    if (mb_strlen($word) < 2) return 1;
    
    // Diptongos y triptongos en español
    $diptongos = ['ai', 'ei', 'oi', 'au', 'eu', 'ou', 'ia', 'ie', 'io', 'ua', 'ue', 'uo'];
    
    $syllables = 0;
    $previous_was_vowel = false;
    
    for ($i = 0; $i < mb_strlen($word); $i++) {
        $char = mb_substr($word, $i, 1);
        $is_vowel = in_array($char, ['a', 'e', 'i', 'o', 'u']);
        
        if ($is_vowel && !$previous_was_vowel) {
            // Revisar si es diptongo
            $next_chars = mb_substr($word, $i, 2);
            if (!in_array($next_chars, $diptongos)) {
                $syllables++;
            }
        }
        
        $previous_was_vowel = $is_vowel;
    }
    
    return max(1, $syllables);
}

function count_total_syllables(string $text): int {
    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $total = 0;
    foreach ($words as $word) {
        $word = preg_replace('/[^a-záéíóúñ]/ui', '', $word);
        if (!empty($word)) {
            $total += count_syllables($word);
        }
    }
    return max(1, $total);
}

function calculate_flesch_readability(string $text): array {
    $text = trim($text);
    if (empty($text)) {
        return ['score' => 0, 'level' => 'Desconocido', 'description' => 'Texto vacio'];
    }
    
    // Contar palabras
    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $word_count = count($words);
    
    if ($word_count === 0) {
        return ['score' => 0, 'level' => 'Desconocido', 'description' => 'Sin palabras'];
    }
    
    // Contar oraciones (., !, ?)
    $sentence_count = preg_match_all('/[.!?]+/u', $text);
    if ($sentence_count === 0) $sentence_count = 1;
    
    // Contar sílabas
    $syllable_count = count_total_syllables($text);
    
    // Fórmula Flesch Reading Ease (adaptada para español)
    $flesch_score = 206.835 - (1.015 * ($word_count / $sentence_count)) - (84.6 * ($syllable_count / $word_count));
    $flesch_score = max(0, min(100, $flesch_score));
    
    // Determinar nivel
    if ($flesch_score >= 90) {
        $level = 'Muy fácil';
        $description = 'Niños 5-6 años, muy legible';
    } elseif ($flesch_score >= 80) {
        $level = 'Fácil';
        $description = 'Niños 6-7 años';
    } elseif ($flesch_score >= 70) {
        $level = 'Bastante fácil';
        $description = 'Primaria alta';
    } elseif ($flesch_score >= 60) {
        $level = 'Estándar';
        $description = 'Óptimo para SEO, público general';
    } elseif ($flesch_score >= 50) {
        $level = 'Bastante difícil';
        $description = 'Secundaria, especializados';
    } elseif ($flesch_score >= 30) {
        $level = 'Difícil';
        $description = 'Universitario, técnico';
    } else {
        $level = 'Muy difícil';
        $description = 'Expertos, académico';
    }
    
    return [
        'score' => round($flesch_score, 1),
        'level' => $level,
        'description' => $description,
        'metrics' => [
            'words' => $word_count,
            'sentences' => $sentence_count,
            'syllables' => $syllable_count
        ]
    ];
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

$plain = extract_plain_text($html);
$normalized = sem_normalize_text($plain);
$tokensAll = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
$wordCount = count($tokensAll);

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
    $termNormalized = sem_normalize_text($term);
    $termTokens = preg_split('/\s+/u', $termNormalized, -1, PREG_SPLIT_NO_EMPTY);
    $occ = count_phrase_occurrences($tokensAll, $termTokens);
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

// Calculate readability (Flesch)
$readability = calculate_flesch_readability($plain);

$insights = [];
if ($wordCount < $minRequired) {
    $insights[] = 'El contenido es corto: ' . $wordCount . ' palabras (minimo ' . $minRequired . ').';
}
foreach ($termStats as $stat) {
    if (!$stat['ok']) {
        $insights[] = 'Ajusta la densidad de "' . $stat['term'] . '" a ' . $stat['target'] . ' (actual ' . $stat['density'] . '%).';
    }
}
if ($readability['score'] < 60) {
    $insights[] = 'Legibilidad baja (' . $readability['score'] . ' Flesch). Simplifica oraciones y usa vocabulario más común.';
} elseif ($readability['score'] > 85) {
    $insights[] = 'Legibilidad muy alta (' . $readability['score'] . ' Flesch). Considera agregar más profundidad técnica si es necesario.';
}
if (!$insights) {
    $insights[] = 'El contenido cumple longitud, densidades y legibilidad.';
}

json_response([
    'score' => (int) round($score),
    'word_count' => $wordCount,
    'min_required' => $minRequired,
    'readability' => $readability,
    'term_stats' => $termStats,
    'insights' => $insights
]);
