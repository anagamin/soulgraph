<?php

return [
    'default' => env('AI_PROVIDER', 'gptunnel'),

    'gptunnel' => [
        'base_url' => env('GPTUNNEL_BASE_URL', 'https://gptunnel.ru/v1'),
        'api_key' => env('GPTUNNEL_API_KEY'),
        'chat_model' => env('GPTUNNEL_CHAT_MODEL', 'gpt-4o-mini'),
        'embed_model' => env('GPTUNNEL_EMBED_MODEL', 'text-embedding-3-small'),
        'max_retries' => (int) env('GPTUNNEL_MAX_RETRIES', 3),
        'timeout' => (int) env('GPTUNNEL_TIMEOUT', 120),
    ],

    'extraction' => [
        'prompt_version' => 'extraction/v1',
        'min_confidence' => 0.3,
    ],

    'interview' => [
        'prompt_version' => 'interview/v1',
    ],

    'psychologist' => [
        'prompt_version' => 'psychologist/v1',
        'context_limit' => 8000,
    ],
];
