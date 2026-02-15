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

set_exception_handler(function (Throwable $e) {
    error_log('Uncaught exception: ' . $e->getMessage());
    error_log($e->getTraceAsString());
    json_response(['error' => 'Error interno'], 500);
});

set_error_handler(function (int $severity, string $message, string $file, int $line) {
    error_log("PHP error [$severity] $message in $file:$line");
    return false;
});

function get_client_ip(): string {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $value = $_SERVER[$key];
            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $value);
                return trim($parts[0]);
            }
            return trim($value);
        }
    }
    return 'unknown';
}

function normalize_keywords(string $input): array {
    $parts = array_map('trim', explode(',', $input));
    $parts = array_filter($parts, fn($p) => $p !== '');
    $parts = array_slice($parts, 0, 3);
    $normalized = [];
    foreach ($parts as $part) {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($part, 'UTF-8') : strtolower($part);
        $lower = trim(preg_replace('/\s+/', ' ', $lower));
        if ($lower !== '' && !in_array($lower, $normalized, true)) {
            $normalized[] = $lower;
        }
    }
    return $normalized;
}

function keyword_hash(array $keywords): string {
    return hash('sha256', implode(',', $keywords));
}

function safe_json_decode(string $raw): array {
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function fetch_serpapi(string $keyword, array $config): array {
    $baseUrl = $config['serpapi']['base_url'] ?? 'https://serpapi.com/search.json';
    $query = http_build_query([
        'engine' => 'google',
        'q' => $keyword,
        'hl' => 'es',
        'gl' => 'es',
        'num' => 20,
        'api_key' => $config['serpapi']['api_key'] ?? '',
    ]);
    $url = $baseUrl . '?' . $query;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'SemanticoBot/1.0 (+https://semantico.paucastells.com)',
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $respLen = is_string($response) ? strlen($response) : 0;
    curl_close($ch);

    if ($response === false) {
        return ['error' => $error ?: 'SerpAPI request failed'];
    }
    if ($httpCode >= 400 || $respLen === 0) {
        error_log('SerpAPI HTTP ' . $httpCode . ' errno=' . $errno . ' resp_len=' . $respLen . ' error=' . $error);
    }
    $decoded = safe_json_decode($response);
    if (!$decoded) {
        error_log('SerpAPI decode failed. Raw response: ' . substr($response, 0, 500));
        return ['error' => 'SerpAPI returned invalid JSON'];
    }
    if (isset($decoded['error'])) {
        error_log('SerpAPI error field: ' . json_encode($decoded));
    }
    if (!isset($decoded['organic_results'])) {
        error_log('SerpAPI missing organic_results. Response: ' . substr(json_encode($decoded), 0, 500));
    }
    return $decoded;
}

function extract_text(string $html, int $maxLength): string {
    $html = preg_replace('/<nav\b[^>]*>(.*?)<\/nav>/is', ' ', $html);
    $html = preg_replace('/<header\b[^>]*>(.*?)<\/header>/is', ' ', $html);
    $html = preg_replace('/<footer\b[^>]*>(.*?)<\/footer>/is', ' ', $html);
    $html = preg_replace('/<aside\b[^>]*>(.*?)<\/aside>/is', ' ', $html);
    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', ' ', $html);
    $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', ' ', $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    if (strlen($text) > $maxLength) {
        $text = substr($text, 0, $maxLength);
    }
    return $text;
}

function documents_from_serp(array $serpResults, int $maxLength, int $limitPerKeyword = 5, array $blockedUrlMap = []): array {
    $documents = [];
    foreach ($serpResults as $entry) {
        $keyword = $entry['keyword'] ?? '';
        $results = $entry['results'] ?? [];
        $count = 0;
        foreach ($results as $item) {
            if ($limitPerKeyword > 0 && $count >= $limitPerKeyword) {
                break;
            }
            $title = $item['title'] ?? '';
            $snippet = $item['snippet'] ?? '';
            $link = $item['link'] ?? '';
            if ($link !== '' && isset($blockedUrlMap[$link])) {
                continue;
            }
            $content = trim($title . ' ' . $snippet);
            if ($content === '') {
                continue;
            }
            if (strlen($content) > $maxLength) {
                $content = substr($content, 0, $maxLength);
            }
            $documents[] = [
                'url' => $link,
                'title' => $title,
                'content' => $content,
                'keyword' => $keyword,
                'source_type' => 'serp_snippet'
            ];
            $count++;
        }
    }
    return $documents;
}

function scrape_urls(array $urls, int $timeout, int $maxLength, array $urlKeywordMap = []): array {
    $multi = curl_multi_init();
    $handles = [];
    foreach ($urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'SemanticoBot/1.0 (+https://semantico.paucastells.com)',
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[(int) $ch] = ['handle' => $ch, 'url' => $url];
    }

    do {
        $status = curl_multi_exec($multi, $active);
        if ($active) {
            $select = curl_multi_select($multi, 1.0);
            if ($select === -1) {
                usleep(100000);
            }
        }
    } while ($active && $status === CURLM_OK);

    $documents = [];
    foreach ($handles as $meta) {
        $ch = $meta['handle'];
        $html = curl_multi_getcontent($ch);
        $error = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);

        if ($error || !$html || $code >= 400) {
            $reason = $error ?: 'HTTP ' . $code;
            error_log('Scrape failed: ' . $meta['url'] . ' - ' . $reason);
            continue;
        }
        $title = '';
        if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $content = extract_text($html, $maxLength);
        if ($content === '' || strlen($content) < 400) {
            continue;
        }
        $documents[] = [
            'url' => $meta['url'],
            'title' => $title,
            'content' => $content,
            'keyword' => $urlKeywordMap[$meta['url']] ?? '',
            'source_type' => 'scraped'
        ];
        error_log('Scraped OK: ' . $meta['url']);
    }

    curl_multi_close($multi);
    return $documents;
}

function doc_top_terms(string $content, int $limit = 6): array {
    $tokens = sem_tokenize($content, sem_stopwords_es());
    if (!$tokens) {
        return [];
    }
    $counts = array_count_values($tokens);
    arsort($counts);
    return array_slice(array_keys($counts), 0, $limit);
}

function doc_tone(string $content): string {
    $text = function_exists('mb_strtolower') ? mb_strtolower($content, 'UTF-8') : strtolower($content);
    $signals = [
        'comercial' => ['precio', 'oferta', 'compra', 'comprar', 'envio', 'gratis', 'descuento', 'tienda'],
        'educativo' => ['guia', 'tutorial', 'paso a paso', 'aprende', 'como ', 'cÃ³mo ', 'explicacion', 'explicaciÃ³n'],
        'opinativo' => ['opiniones', 'reseÃ±as', 'review', 'valoracion', 'valoraciÃ³n'],
    ];
    foreach ($signals as $tone => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($text, $kw) !== false) {
                return $tone;
            }
        }
    }
    return 'informativo';
}

function count_words(string $text): int {
    $normalized = sem_normalize_text($text);
    if ($normalized === '') {
        return 0;
    }
    $parts = explode(' ', $normalized);
    return count(array_filter($parts));
}

function build_prompt(array $keywords, array $documents, array $tfidf, array $serpResults, array $stats): string {
    $docsPayload = [];
    foreach ($documents as $doc) {
        $docsPayload[] = [
            'url' => $doc['url'],
            'title' => $doc['title'],
            'content_excerpt' => $doc['content']
        ];
    }

    $payload = [
        'keywords' => $keywords,
        'serp' => $serpResults,
        'documents' => $docsPayload,
        'tfidf_terms' => $tfidf['terms'],
        'cooccurrences' => $tfidf['cooccurrences'],
        'word_stats' => $stats,
    ];

    $jsonContext = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return "Eres un analista SEO senior. Usa el contexto para generar un informe JSON estricto.\n\nContexto (JSON):\n" . $jsonContext . "\n\nDevuelve exclusivamente JSON con esta estructura:\n{\n  \"keywords_semanticas\": [\n    {\n      \"term\": \"...\",\n      \"tfidf_score\": 0.0123,\n      \"densidad_recomendada\": \"1.2-1.8%\",\n      \"menciones_sugeridas\": 12\n    }\n  ],\n  \"longitud_texto\": {\n    \"promedio_palabras\": 2300,\n    \"rango_recomendado\": \"2200-2600\",\n    \"minima_competitiva\": 2000\n  },\n  \"intencion_tono\": {\n    \"intencion\": \"informacional\",\n    \"tono\": \"educativo\",\n    \"nivel_profundidad\": \"intermedio\",\n    \"contexto_emocional\": \"...\"\n  },\n  \"clusters_seo\": [\n    {\n      \"cluster\": \"...\",\n      \"salient_score\": 85,\n      \"cobertura_top\": \"4/5\",\n      \"profundidad_palabras\": 400,\n      \"subtemas\": [\"...\"]\n    }\n  ],\n  \"estructura_propuesta\": {\n    \"h1\": \"...\",\n    \"secciones\": [\n      {\n        \"h2\": \"...\",\n        \"h3\": [\"...\"],\n        \"longitud_palabras\": 320,\n        \"orden\": 1\n      }\n    ]\n  },\n  \"oportunidades\": {\n    \"content_gaps\": [\"...\"],\n    \"formatos_ausentes\": [\"...\"],\n    \"preguntas_sin_responder\": [\"...\"],\n    \"ideas_diferenciacion\": [\"...\"]\n  },\n}\n\nReglas: usa los datos TF-IDF para justificar terminos y clusters. No incluyas texto fuera del JSON.";
}

function call_openai(array $config, string $prompt): array {
    $payload = [
        'model' => $config['openai']['model'],
        'temperature' => 0.2,
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => 'Eres un experto SEO que responde solo con JSON valido.'],
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
    $data = safe_json_decode($response);
    if (!isset($data['choices'][0]['message']['content'])) {
        return ['error' => 'OpenAI response missing content', 'raw' => $data];
    }
    $content = $data['choices'][0]['message']['content'];
    $analysis = safe_json_decode($content);
    if (!$analysis) {
        return ['error' => 'OpenAI returned invalid JSON', 'raw' => $content];
    }
    return ['analysis' => $analysis, 'usage' => $data['usage'] ?? null];
}

function call_anthropic(array $config, string $prompt): array {
    $payload = [
        'model' => $config['anthropic']['model'],
        'max_tokens' => $config['anthropic']['max_tokens'] ?? 2048,
        'temperature' => 0.2,
        'system' => 'Eres un experto SEO que responde solo con JSON valido.',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ]
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $config['anthropic']['api_key'],
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => $config['anthropic']['timeout'] ?? 30,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['error' => $error ?: 'Anthropic request failed'];
    }
    $data = safe_json_decode($response);
    if (!isset($data['content'][0]['text'])) {
        return ['error' => 'Anthropic response missing content', 'raw' => $data];
    }
    $content = $data['content'][0]['text'];
    $analysis = safe_json_decode($content);
    if (!$analysis) {
        return ['error' => 'Anthropic returned invalid JSON', 'raw' => $content];
    }
    $usage = $data['usage'] ?? null;
    if (is_array($usage)) {
        $usage['total_tokens'] = ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0);
    }
    return ['analysis' => $analysis, 'usage' => $usage];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    json_response(['error' => 'Metodo no permitido'], 405);
}

$raw = file_get_contents('php://input');
$payload = safe_json_decode($raw);
$keywordHashInput = $payload['keyword_hash'] ?? null;
$keywordsInput = $payload['keywords'] ?? $_POST['keywords'] ?? '';

if (is_string($keywordHashInput) && $keywordHashInput !== '') {
    $pdo = require $rootDir . '/includes/db.php';
    $stmt = $pdo->prepare('SELECT result_json, tfidf_json, created_at, keywords, keyword_hash FROM semantic_analysis WHERE keyword_hash = ? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$keywordHashInput]);
    $cachedReport = $stmt->fetch();
    if ($cachedReport) {
        $tfidfCached = safe_json_decode($cachedReport['tfidf_json']);
        json_response([
            'cached' => true,
            'keywords' => explode(', ', $cachedReport['keywords']),
            'keyword_hash' => $cachedReport['keyword_hash'],
            'analysis' => safe_json_decode($cachedReport['result_json']),
            'tfidf' => $tfidfCached,
            'stats' => $tfidfCached['stats'] ?? null,
            'created_at' => $cachedReport['created_at']
        ]);
    }
    json_response(['error' => 'Informe no encontrado.'], 404);
}
if (!is_string($keywordsInput) || trim($keywordsInput) === '') {
    json_response(['error' => 'Introduce entre 1 y 3 palabras clave.'], 400);
}

$keywords = normalize_keywords($keywordsInput);
if (!$keywords) {
    json_response(['error' => 'No se encontraron palabras clave validas.'], 400);
}

$pdo = require $rootDir . '/includes/db.php';
$ip = get_client_ip();

$limit = (int) ($config['rate_limit']['daily_limit'] ?? 10);
$interval = (int) ($config['rate_limit']['interval_hours'] ?? 24);
$limitStmt = $pdo->prepare('SELECT COUNT(*) FROM usage_log WHERE ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)');
$limitStmt->execute([$ip, $interval]);
$used = (int) $limitStmt->fetchColumn();
if ($used >= $limit) {
    json_response(['error' => 'Limite diario alcanzado.'], 429);
}

$keywordHash = keyword_hash($keywords);
$cacheStmt = $pdo->prepare('SELECT result_json, tfidf_json, created_at FROM semantic_analysis WHERE keyword_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY created_at DESC LIMIT 1');
$cacheStmt->execute([$keywordHash]);
$cached = $cacheStmt->fetch();
if ($cached) {
    $tfidfCached = safe_json_decode($cached['tfidf_json']);
    json_response([
        'cached' => true,
        'keywords' => $keywords,
        'keyword_hash' => $keywordHash,
        'analysis' => safe_json_decode($cached['result_json']),
        'tfidf' => $tfidfCached,
        'stats' => $tfidfCached['stats'] ?? null,
        'created_at' => $cached['created_at']
    ]);
}

if (empty($config['serpapi']['api_key']) || $config['serpapi']['api_key'] === 'YOUR_SERPAPI_API_KEY') {
    json_response(['error' => 'Configura la API key de SerpAPI.'], 500);
}
if (empty($config['openai']['api_key']) || $config['openai']['api_key'] === 'YOUR_OPENAI_API_KEY') {
    json_response(['error' => 'Configura la API key de OpenAI.'], 500);
}

$serpResults = [];
$serpApiCalls = 0;
$urls = [];
$urlKeywordMap = [];
foreach ($keywords as $keyword) {
    $serpHash = hash('sha256', $keyword);
    $serpStmt = $pdo->prepare('SELECT results_json, created_at FROM serp_cache WHERE keyword_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY created_at DESC LIMIT 1');
    $serpStmt->execute([$serpHash]);
    $serpCached = $serpStmt->fetch();
    if ($serpCached) {
        $serpData = safe_json_decode($serpCached['results_json']);
    } else {
        $serpData = fetch_serpapi($keyword, $config);
        $serpApiCalls++;
        if (!empty($serpData['error'])) {
            error_log('SerpAPI error for keyword "' . $keyword . '": ' . $serpData['error']);
            $serpData = [];
        } else {
            $insertSerp = $pdo->prepare('INSERT INTO serp_cache (keyword, keyword_hash, results_json, created_at) VALUES (?, ?, ?, NOW())');
            $insertSerp->execute([$keyword, $serpHash, json_encode($serpData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
    }

    $organic = $serpData['organic_results'] ?? [];
    $top = [];
    foreach ($organic as $item) {
        if (!empty($item['link'])) {
            $url = $item['link'];
            $top[] = [
                'title' => $item['title'] ?? '',
                'link' => $url,
                'snippet' => $item['snippet'] ?? ''
            ];
            $urls[] = $url;
            $urlKeywordMap[$url] = $keyword;
        }
        if (count($top) >= 5) {
            break;
        }
    }
    $serpResults[] = [
        'keyword' => $keyword,
        'results' => $top
    ];
}

$urls = array_values(array_unique($urls));
$blockHostSuffixes = [
    'pinterest.com',
    'amazon.es',
    'amazon.com',
    'canva.com',
    'wikipedia.org',
    'play.google.com',
    'apps.apple.com',
    'itunes.apple.com',
    'twitter.com',
    'x.com',
    'facebook.com',
    'instagram.com',
    'tiktok.com',
];
$blockExt = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx'];
$blockedUrls = [];
$urls = array_values(array_filter($urls, function (string $url) use ($blockHostSuffixes, $blockExt, &$blockedUrls) {
    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');
    if ($host) {
        foreach ($blockHostSuffixes as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                $blockedUrls[$url] = true;
                return false;
            }
        }
    }
    $path = strtolower($parts['path'] ?? '');
    if ($path !== '') {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if ($ext && in_array($ext, $blockExt, true)) {
            $blockedUrls[$url] = true;
            return false;
        }
    }
    return true;
}));
$urls = array_slice($urls, 0, 60);

$fallbackMode = null;
$documents = scrape_urls($urls, (int) $config['scrape']['timeout'], (int) $config['scrape']['max_length'], $urlKeywordMap);
if (!$documents) {
    error_log('No documents scraped. URLs count: ' . count($urls) . '. Using SERP snippets fallback.');
    $fallbackMode = 'serp_snippets';
    $documents = documents_from_serp($serpResults, (int) $config['scrape']['max_length'], 5, $blockedUrls);
}
if (!$documents) {
    error_log('No documents from SERP snippets. Using keywords fallback.');
    $fallbackMode = 'keywords';
    $documents = array_map(function ($keyword) {
        return [
            'url' => '',
            'title' => $keyword,
            'content' => $keyword
        ];
    }, $keywords);
}

// Top-up to ensure up to 5 sources per keyword using SERP snippets.
$byKeyword = [];
foreach ($documents as $doc) {
    $key = $doc['keyword'] ?? '';
    if ($key === '') {
        continue;
    }
    $byKeyword[$key] = ($byKeyword[$key] ?? 0) + 1;
}
$existingUrls = [];
foreach ($documents as $doc) {
    if (!empty($doc['url'])) {
        $existingUrls[$doc['url']] = true;
    }
}
$snippetDocs = documents_from_serp($serpResults, (int) $config['scrape']['max_length'], 10, $blockedUrls);
foreach ($snippetDocs as $doc) {
    $key = $doc['keyword'] ?? '';
    if ($key === '') {
        continue;
    }
    if (($byKeyword[$key] ?? 0) >= 5) {
        continue;
    }
    if (!empty($doc['url']) && isset($existingUrls[$doc['url']])) {
        continue;
    }
    $documents[] = $doc;
    if (!empty($doc['url'])) {
        $existingUrls[$doc['url']] = true;
    }
    $byKeyword[$key] = ($byKeyword[$key] ?? 0) + 1;
}

$tfidf = sem_tfidf($documents);
$wordCounts = [];
foreach ($documents as $doc) {
    $wordCounts[] = count_words($doc['content']);
}
$avgWords = $wordCounts ? (int) round(array_sum($wordCounts) / count($wordCounts)) : 0;
$minWords = $wordCounts ? min($wordCounts) : 0;
$maxWords = $wordCounts ? max($wordCounts) : 0;
$minAcceptable = 600;
$avgWords = max($avgWords, $minAcceptable);
$minWords = max($minWords, $minAcceptable);
$stats = [
    'avg_words' => $avgWords,
    'min_words' => $minWords,
    'max_words' => $maxWords,
    'doc_count' => count($documents)
];
$tfidf['stats'] = $stats;

$prompt = build_prompt($keywords, $documents, $tfidf, $serpResults, $stats);
$provider = $config['llm']['provider'] ?? 'openai';
if ($provider === 'anthropic') {
    $ai = call_anthropic($config, $prompt);
} else {
    $ai = call_openai($config, $prompt);
}
if (!empty($ai['error'])) {
    $analysis = [
        'error' => $ai['error'],
        'fallback' => true
    ];
} else {
    $analysis = $ai['analysis'];
}

// Enforce length stats from real docs to avoid mismatches.
$analysis['longitud_texto'] = [
    'promedio_palabras' => $avgWords,
    'rango_recomendado' => ($avgWords > 0)
        ? (int) floor($avgWords * 0.9) . '-' . (int) ceil($avgWords * 1.1)
        : '0-0',
    'minima_competitiva' => $minWords
];


$writePdo = require $rootDir . '/includes/db.php';
$insertAnalysis = $writePdo->prepare('INSERT INTO semantic_analysis (keyword_hash, keywords, result_json, tfidf_json, created_at) VALUES (?, ?, ?, ?, NOW())');
$insertAnalysis->execute([
    $keywordHash,
    implode(', ', $keywords),
    json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    json_encode((function (array $data) {
        unset($data['doc_terms']);
        return $data;
    })($tfidf), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
]);

$usageTokens = isset($ai['usage']['total_tokens']) ? (int) $ai['usage']['total_tokens'] : 0;
$logStmt = $writePdo->prepare('INSERT INTO usage_log (ip, keywords, tokens, created_at) VALUES (?, ?, ?, NOW())');
$logStmt->execute([$ip, implode(', ', $keywords), $usageTokens]);

$scrapedOk = 0;
$serpSnippets = 0;
foreach ($documents as $doc) {
    if (($doc['source_type'] ?? '') === 'scraped') {
        $scrapedOk++;
    }
    if (($doc['source_type'] ?? '') === 'serp_snippet') {
        $serpSnippets++;
    }
}

$serpapiCostPerSearch = 0.025; // USD, approx based on 1000 searches / $25 plan.
$openaiBlendedPer1M = 1.84; // USD per 1M tokens (blended) for gpt-4.1.
$openaiCost = ($provider === 'openai') ? ($usageTokens / 1000000) * $openaiBlendedPer1M : 0;
$serpapiCost = $serpApiCalls * $serpapiCostPerSearch;

json_response([
    'cached' => false,
    'keywords' => $keywords,
    'keyword_hash' => $keywordHash,
    'analysis' => $analysis,
    'tfidf' => $tfidf,
    'stats' => $stats,
    'fallback_mode' => $fallbackMode,
    'usage' => [
        'llm_provider' => $provider,
        'serpapi_calls' => $serpApiCalls,
        'openai_tokens' => $usageTokens,
        'urls_total' => count($urls),
        'scraped_ok' => $scrapedOk,
        'serp_snippets_used' => $serpSnippets,
        'serpapi_cost_usd' => round($serpapiCost, 4),
        'openai_cost_usd' => round($openaiCost, 4),
        'serpapi_rate_usd' => $serpapiCostPerSearch,
        'openai_rate_usd_per_1m' => $openaiBlendedPer1M,
        'pricing_note' => 'Costes aproximados segun tarifas publicas'
    ],
    'documents' => array_map(function ($doc) {
        $content = $doc['content'] ?? '';
        $wordCount = $content !== '' ? count_words($content) : 0;
        return [
            'url' => $doc['url'] ?? '',
            'title' => $doc['title'] ?? '',
            'keyword' => $doc['keyword'] ?? '',
            'source_type' => $doc['source_type'] ?? 'scraped',
            'content_length' => strlen($content),
            'word_count' => $wordCount,
            'top_terms' => doc_top_terms($content, 6),
            'tone' => doc_tone($content)
        ];
    }, $documents)
]);


