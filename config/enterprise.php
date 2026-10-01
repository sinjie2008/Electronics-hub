<?php

return [
    'version' => env('APP_VERSION', '1.0.0'),
    'locales' => ['en'],
    'initial_admin' => [
        'name' => env('INITIAL_ADMIN_NAME'),
        'email' => env('INITIAL_ADMIN_EMAIL'),
        'password' => env('INITIAL_ADMIN_PASSWORD'),
    ],
    'backups' => [
        'schedule_enabled' => (bool) env('BACKUP_SCHEDULE_ENABLED', false),
        'run_at' => env('BACKUP_RUN_AT', '02:00'),
        'clean_at' => env('BACKUP_CLEAN_AT', '03:00'),
    ],
];
