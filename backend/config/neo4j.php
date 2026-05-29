<?php

return [
    'uri' => env('NEO4J_URI', 'bolt://localhost:7687'),
    'username' => env('NEO4J_USERNAME', 'neo4j'),
    'password' => env('NEO4J_PASSWORD', 'soulgraph_secret'),
    'database' => env('NEO4J_DATABASE', 'neo4j'),
];
