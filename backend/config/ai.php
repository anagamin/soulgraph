<?php

return [
    'default' => env('AI_PROVIDER', 'gptunnel'),

    'gptunnel' => [
        'base_url' => env('GPTUNNEL_BASE_URL', 'https://gptunnel.ru/v1'),
        'api_key' => env('GPTUNNEL_API_KEY'),
        'chat_model' => env('GPTUNNEL_CHAT_MODEL', 'gpt-4o-mini'),
        'embed_model' => env('GPTUNNEL_EMBED_MODEL', 'text-embedding-3-small'),
        'max_retries' => (int) env('GPTUNNEL_MAX_RETRIES', 3),
        'timeout' => (int) env('GPTUNNEL_TIMEOUT', 180),
    ],

    'deduplication' => [
        'keyed_types' => [
            'person', 'place', 'pattern', 'belief', 'value', 'identity',
            'fear', 'emotion', 'interpretation', 'motivation', 'goal',
            'practice', 'relationship', 'event', 'epoch',
        ],
        'auto_merge_threshold' => 0.92,
        'suggest_threshold' => 0.80,
        'label_similarity_threshold' => 0.88,
    ],

    'interview' => [
        'prompt_version' => 'interview/v3-significance',
    ],

    'extraction' => [
        'prompt_version' => 'extraction/v3-significance',
        'min_confidence' => 0.3,
    ],

    'psychologist' => [
        'prompt_version' => 'psychologist/v1',
        'context_limit' => 8000,
    ],

    'autobiography' => [
        'chat_model' => env('AUTOBIOGRAPHY_CHAT_MODEL'),
        'fallback_chat_model' => env('AUTOBIOGRAPHY_FALLBACK_CHAT_MODEL', 'gpt-4o-mini'),
        'reasoning_effort' => env('AUTOBIOGRAPHY_REASONING_EFFORT', 'low'),
        'outline_max_tokens' => (int) env('AUTOBIOGRAPHY_OUTLINE_MAX_TOKENS', 8192),
        'context_limit' => (int) env('AUTOBIOGRAPHY_CONTEXT_LIMIT', 28000),
        'summary_max_chars' => (int) env('AUTOBIOGRAPHY_SUMMARY_MAX_CHARS', 400),
        'compact_summary_max_chars' => (int) env('AUTOBIOGRAPHY_COMPACT_SUMMARY_MAX_CHARS', 120),
        'full_detail_min_score' => (float) env('AUTOBIOGRAPHY_FULL_DETAIL_MIN_SCORE', 0.55),
        'multi_pass' => filter_var(env('AUTOBIOGRAPHY_MULTI_PASS', true), FILTER_VALIDATE_BOOLEAN),
        'single_pass_max_entities' => (int) env('AUTOBIOGRAPHY_SINGLE_PASS_MAX_ENTITIES', 12),
        'batch_size' => (int) env('AUTOBIOGRAPHY_BATCH_SIZE', 8),
        'neighbors_per_seed' => (int) env('AUTOBIOGRAPHY_NEIGHBORS_PER_SEED', 4),
        'max_batches' => (int) env('AUTOBIOGRAPHY_MAX_BATCHES', 12),
        'merge_fragment_max_chars' => (int) env('AUTOBIOGRAPHY_MERGE_FRAGMENT_MAX_CHARS', 3500),
        'outline_excerpt_chars' => (int) env('AUTOBIOGRAPHY_OUTLINE_EXCERPT_CHARS', 2000),
        'merge_timeout_seconds' => (int) env('AUTOBIOGRAPHY_MERGE_TIMEOUT', 300),
    ],
];
