<?php

declare(strict_types=1);

return [
    'enabled' => env('CATALOG_PUBLIC_API_ENABLED', false),
    'database' => [
        'username' => env('CATALOG_PUBLIC_API_DB_USERNAME'),
        'password' => env('CATALOG_PUBLIC_API_DB_PASSWORD'),
    ],
    'rate_limits' => [
        'standard' => (int) env('CATALOG_PUBLIC_API_RATE_STANDARD', 60),
        'search' => (int) env('CATALOG_PUBLIC_API_RATE_SEARCH', 20),
        'expensive' => (int) env('CATALOG_PUBLIC_API_RATE_EXPENSIVE', 30),
    ],
    'default_per_page' => 25,
    'max_per_page' => 100,
    'max_search_length' => 100,
    'max_query_bytes' => 8192,
    'max_filters' => 10,
    'max_filter_values' => 20,
    'max_value_length' => 255,
    'max_nodes' => 10000,
    'max_depth' => 64,
    'max_fields' => 200,
    'max_facet_values' => 1000,
    'max_response_bytes' => 8388608,
    'cache_ttl' => (int) env('CATALOG_PUBLIC_API_CACHE_TTL', 300),
    'cache_store' => env('CATALOG_PUBLIC_API_CACHE_STORE'),
    'cache_slots' => 512,
    'max_cache_bytes' => 1048576,
    'media_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('CATALOG_PUBLIC_API_MEDIA_HOSTS', ''))))),
];
