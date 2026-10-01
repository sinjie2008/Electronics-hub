<?php

declare(strict_types=1);

return [
    'prefix' => env('CATALOG_ROUTE_PREFIX', 'catalog'),
    'legacy_urls' => true,
    'project_root_urls' => false,
    'root_typst_api' => 'public',
    'base_url' => env('CATALOG_BASE_URL'),
    'connection' => env('CATALOG_DB_CONNECTION'),
    'storage_root' => env('CATALOG_STORAGE_ROOT'),
    // Existing APIs are public and do not require session/CSRF middleware.
    // Add the host's chosen access/CORS middleware here when integrating clients.
    'middleware' => [],
    'settings' => [
        'seed_name' => 'initial_catalog_v1',
        'storage' => [],
        'media' => [
            'max_bytes' => 10485760,
            'allowed_mime' => ['application/pdf', 'model/gltf-binary'],
            'allowed_extensions' => ['glb'],
        ],
        'truncate' => [
            'token' => 'TRUNCATE',
            'lock_key' => 'catalog_truncate_lock',
            'reason_max' => 256,
        ],
        'latex' => [
            'default_binary' => env('CATALOG_PDFLATEX_BIN', 'pdflatex'),
            'pdflatex_env' => 'CATALOG_PDFLATEX_BIN',
        ],
        'typst' => [
            'binary' => env('CATALOG_TYPST_BIN', 'typst'),
        ],
        'logging' => [
            'enabled' => true,
            'level' => 'info',
            'rotation' => ['max_bytes' => 1048576],
            'timezone' => 'UTC',
        ],
    ],
];
