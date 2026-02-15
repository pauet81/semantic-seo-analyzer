<?php
function sem_normalize_text(string $text): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }
    $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function sem_stopwords_es(): array {
    return [
        'de','la','que','el','en','y','a','los','del','se','las','por','un','para','con','no','una','su','al','lo',
        'como','mas','pero','sus','le','ya','o','este','si','porque','esta','entre','cuando','muy','sin','sobre','tambien',
        'me','hasta','hay','donde','quien','desde','todo','nos','durante','todos','uno','les','ni','contra','otros','ese',
        'eso','ante','ellos','e','esto','mi','antes','algunos','que','unos','yo','otro','otras','otra','el','tanto','esa',
        'estos','mucho','quienes','nada','muchos','cual','poco','ella','estar','estas','algunas','algo','nosotros','mi',
        'mis','tu','te','ti','tus','ellas','nosotras','vosostros','vosostras','os','mio','mia','mios','mias','tuyo',
        'tuya','tuyos','tuyas','suyo','suya','suyos','suyas','nuestro','nuestra','nuestros','nuestras','vuestro','vuestra',
        'vuestros','vuestras','esos','esas','estoy','estas','esta','estamos','estais','estan','este','estes','esteis','esten',
        'estare','estaras','estara','estaremos','estareis','estaran','estaria','estarias','estariamos','estariais','estarian',
        'estaba','estabas','estabamos','estabais','estaban','estuve','estuviste','estuvo','estuvimos','estuvisteis','estuvieron',
        'estuviera','estuvieras','estuvieramos','estuvierais','estuvieran','estuviese','estuvieses','estuviesemos','estuvieseis',
        'estuviesen','estando','estado','estada','estados','estadas','estad','he','has','ha','hemos','habeis','han','haya',
        'hayas','hayamos','hayais','hayan','habre','habras','habra','habremos','habreis','habran','habria','habrias','habriamos',
        'habriais','habrian','habia','habias','habiamos','habiais','habian','hube','hubiste','hubo','hubimos','hubisteis',
        'hubieron','hubiera','hubieras','hubieramos','hubierais','hubieran','hubiese','hubieses','hubiesemos','hubieseis',
        'hubiesen','habiendo','habido','habida','habidos','habidas','soy','eres','es','somos','sois','son','sea','seas','seamos',
        'seais','sean','sere','seras','sera','seremos','sereis','seran','seria','serias','seriamos','seriais','serian','era','eras',
        'eramos','erais','eran','fui','fuiste','fue','fuimos','fuisteis','fueron','fuera','fueras','fueramos','fuerais','fueran',
        'fuese','fueses','fuesemos','fueseis','fuesen','siendo','sido','tengo','tienes','tiene','tenemos','teneis','tienen','tenga',
        'tengas','tengamos','tengais','tengan','tendre','tendras','tendra','tendremos','tendreis','tendran','tendria','tendrias',
        'tendriamos','tendriais','tendrian','tenia','tenias','teniamos','teniais','tenian','tuve','tuviste','tuvo','tuvimos',
        'tuvisteis','tuvieron','tuviera','tuvieras','tuvieramos','tuvierais','tuvieran','tuviese','tuvieses','tuviesemos',
        'tuvieseis','tuviesen','teniendo','tenido','tenida','tenidos','tenidas','tengo','tienes','tiene','tenemos','teneis','tienen',
        'tener',
        'cookies','cookie','aceptar','acepta','aceptas','aceptamos','acepto','rechazar','rechaza','rechazo','rechazamos',
        'consentimiento','privacidad','terminos','terminos','aviso','legal','politica','politicas','licencia',
        'gdpr','rgpd','cierre','cerrar','configurar','configuracion','preferencias','idioma','inicio','menu','navegacion',
        'suscribete','suscribirse','suscripcion','newsletter','banner','popup','modal','noticias','contacto',
        'wikipedia','editar','edicion','buscar','barra','pdf','articulo','articulos','pagina','paginas'
    ];
}

function sem_tokenize(string $text, array $stopwords): array {
    $text = sem_normalize_text($text);
    if ($text === '') {
        return [];
    }
    $parts = explode(' ', $text);
    $tokens = [];
    $stop = array_flip($stopwords);
    foreach ($parts as $part) {
        if ($part === '' || strlen($part) < 3) {
            continue;
        }
        if (isset($stop[$part])) {
            continue;
        }
        $tokens[] = $part;
    }
    return $tokens;
}

function sem_tfidf(array $documents): array {
    $stopwords = sem_stopwords_es();
    $docTerms = [];
    $docWordCounts = [];
    $docFreq = [];

    foreach ($documents as $index => $doc) {
        $tokens = sem_tokenize($doc['content'], $stopwords);
        $docTerms[$index] = $tokens;
        $docWordCounts[$index] = count($tokens);
        $unique = array_unique($tokens);
        foreach ($unique as $term) {
            $docFreq[$term] = ($docFreq[$term] ?? 0) + 1;
        }
    }

    $docCount = max(count($documents), 1);
    $idf = [];
    foreach ($docFreq as $term => $df) {
        $idf[$term] = log(($docCount + 1) / ($df + 1)) + 1;
    }

    $docScores = [];
    $termStats = [];
    foreach ($docTerms as $docIndex => $tokens) {
        $total = max(count($tokens), 1);
        $counts = array_count_values($tokens);
        $scores = [];
        foreach ($counts as $term => $count) {
            if (!isset($idf[$term])) {
                continue;
            }
            $tf = $count / $total;
            $score = $tf * $idf[$term];
            $scores[$term] = $score;
            if (!isset($termStats[$term])) {
                $termStats[$term] = ['sum' => 0, 'max' => 0, 'df' => $docFreq[$term] ?? 0];
            }
            $termStats[$term]['sum'] += $score;
            if ($score > $termStats[$term]['max']) {
                $termStats[$term]['max'] = $score;
            }
        }
        arsort($scores);
        $docScores[$docIndex] = array_slice($scores, 0, 30, true);
    }

    $summary = [];
    foreach ($termStats as $term => $stats) {
        $avg = $stats['sum'] / $docCount;
        $summary[$term] = ['avg' => $avg, 'max' => $stats['max'], 'df' => $stats['df']];
    }
    uasort($summary, function ($a, $b) {
        return $b['avg'] <=> $a['avg'];
    });

    $summary = array_slice($summary, 0, 50, true);
    $terms = [];
    foreach ($summary as $term => $stats) {
        $terms[] = [
            'term' => $term,
            'avg_score' => round($stats['avg'], 6),
            'max_score' => round($stats['max'], 6),
            'df' => $stats['df']
        ];
    }

    $cooccurrenceCounts = [];
    foreach ($docScores as $docIndex => $scores) {
        $topTerms = array_keys($scores);
        $topTerms = array_slice($topTerms, 0, 20);
        $termCount = count($topTerms);
        for ($i = 0; $i < $termCount; $i++) {
            for ($j = $i + 1; $j < $termCount; $j++) {
                $a = $topTerms[$i];
                $b = $topTerms[$j];
                $key = ($a < $b) ? $a . '|' . $b : $b . '|' . $a;
                $cooccurrenceCounts[$key] = ($cooccurrenceCounts[$key] ?? 0) + 1;
            }
        }
    }
    arsort($cooccurrenceCounts);
    $cooccurrenceCounts = array_slice($cooccurrenceCounts, 0, 20, true);
    $cooccurrences = [];
    foreach ($cooccurrenceCounts as $key => $count) {
        [$a, $b] = explode('|', $key);
        $cooccurrences[] = ['term_a' => $a, 'term_b' => $b, 'count' => $count];
    }

    return [
        'terms' => $terms,
        'doc_terms' => $docTerms,
        'cooccurrences' => $cooccurrences,
        'doc_word_counts' => $docWordCounts
    ];
}
