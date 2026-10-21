<?php

return [
    'format' => [
        'name' => 'backup-format-v1',
        'version' => 1,
        'asset_suffix' => '.tar.zst.enc',
    ],
    'storage' => [
        'included_roots' => [
            'storage/app/private',
            'storage/app/public',
        ],
        'workspace' => 'storage/app/backups',
    ],
    'encryption' => [
        'algorithm' => 'AES-256-GCM',
        'key' => env('BACKUP_ENCRYPTION_KEY'),
        'key_id' => env('BACKUP_ENCRYPTION_KEY_ID'),
        'chunk_size' => 1024 * 1024,
    ],
    'github' => [
        'repository' => 'beyourlovercom/erp-backups',
        'visibility' => 'private',
        'token' => env('BACKUP_GITHUB_TOKEN'),
        'api_base' => env('BACKUP_GITHUB_API_BASE', 'https://api.github.com'),
        'api_version' => env('BACKUP_GITHUB_API_VERSION', '2026-03-10'),
        'timeout' => (int) env('BACKUP_GITHUB_TIMEOUT', 30),
        'connect_timeout' => (int) env('BACKUP_GITHUB_CONNECT_TIMEOUT', 10),
        'upload_timeout' => (int) env('BACKUP_GITHUB_UPLOAD_TIMEOUT', 3600),
        'user_agent' => env('BACKUP_GITHUB_USER_AGENT', 'BYL-ERP-Backup'),
        'max_asset_bytes' => (int) env('BACKUP_GITHUB_MAX_ASSET_BYTES', 2147483647),
    ],
    'restore' => [
        'max_decrypted_package_bytes' => (int) env('BACKUP_RESTORE_MAX_DECRYPTED_PACKAGE_BYTES', 2147483647),
        'max_tar_bytes' => (int) env('BACKUP_RESTORE_MAX_TAR_BYTES', 4294967296),
    ],
    'automatic' => [
        'time' => '02:00',
        'timezone' => env('BACKUP_AUTOMATIC_TIMEZONE', config('app.timezone', 'UTC')),
        'weekly_day' => 1,
        'monthly_day' => 1,
        'retention_count' => 5,
    ],
    'manifest' => [
        'version' => 1,
    ],
    'tooling' => [
        'mysqldump' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),
        'mysql' => env('BACKUP_MYSQL_BINARY', 'mysql'),
        'tar' => env('BACKUP_TAR_BINARY', 'tar'),
        'zstd' => env('BACKUP_ZSTD_BINARY', 'zstd'),
    ],
];
