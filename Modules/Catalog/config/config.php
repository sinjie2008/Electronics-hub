<?php

declare(strict_types=1);

return [
    'prefix' => 'catalog',
    'connection' => env('CATALOG_CONNECTION', 'catalog'),
    'legacy_urls' => true,
    'root_typst_api' => 'root',
    'project_root_urls' => false,
    'middleware' => [],
    'base_url' => null,
    'storage_root' => env('CATALOG_STORAGE_ROOT'),
    'database' => [
        'driver' => 'mysql',
        'host' => env('CATALOG_DB_HOST', env('DB_HOST', '127.0.0.1')),
        'port' => env('CATALOG_DB_PORT', env('DB_PORT', '3306')),
        'database' => env('CATALOG_DB_DATABASE', 'electronics_catalog_migrated'),
        'username' => env('CATALOG_DB_USERNAME', env('DB_USERNAME', 'root')),
        'password' => env('CATALOG_DB_PASSWORD', env('DB_PASSWORD', '')),
        'unix_socket' => env('CATALOG_DB_SOCKET', env('DB_SOCKET', '')),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => null,
    ],
    'settings' => [
        'bootstrap_schema' => false,
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
            'pdflatex_env' => 'CATALOG_PDFLATEX_BIN',
            'default_binary' => env('CATALOG_PDFLATEX_BIN', 'pdflatex'),
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
