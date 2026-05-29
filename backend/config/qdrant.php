<?php

return [
    'host' => env('QDRANT_HOST', 'localhost'),
    'port' => (int) env('QDRANT_PORT', 6333),
    'https' => (bool) env('QDRANT_HTTPS', false),
    'api_key' => env('QDRANT_API_KEY'),
    'vector_size' => (int) env('QDRANT_VECTOR_SIZE', 1536),
];
