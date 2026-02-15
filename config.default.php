<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'semantica',
        'user' => 'semantica_user',
        'pass' => 'change_me',
        'charset' => 'utf8mb4',
    ],
    'serper' => [
        'api_key' => 'YOUR_SERPER_API_KEY',
        'base_url' => 'https://serper.dev/search'
    ],
    'serpapi' => [
        'api_key' => 'YOUR_SERPAPI_API_KEY',
        'base_url' => 'https://serpapi.com/search.json'
    ],
    'openai' => [
        'api_key' => 'YOUR_OPENAI_API_KEY',
        'model' => 'gpt-4.1',
        'timeout' => 30
    ],
    'anthropic' => [
        'api_key' => 'YOUR_ANTHROPIC_API_KEY',
        'model' => 'claude-sonnet-4-20250514',
        'timeout' => 30,
        'max_tokens' => 2048
    ],
    'llm' => [
        'provider' => 'openai'
    ],
    'rate_limit' => [
        'daily_limit' => 10,
        'interval_hours' => 24
    ],
    'scrape' => [
        'max_length' => 12000,
        'timeout' => 15
    ],
];
