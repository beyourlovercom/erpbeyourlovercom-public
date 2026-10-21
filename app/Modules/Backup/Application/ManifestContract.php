<?php

namespace App\Modules\Backup\Application;

final class ManifestContract
{
    /** @var list<string> */
    public const REQUIRED_FIELDS = [
        'application',
        'backup_format_version',
        'backup_type',
        'created_at',
        'git_sha',
        'database_engine',
        'database_version',
        'latest_migration',
        'migration_count',
        'encrypted',
        'encryption_algorithm',
        'encryption_key_id',
        'storage_included',
        'database_dump_size',
        'storage_archive_size',
        'components',
        'environment_classification',
        'creator_source',
    ];

    /** @var list<string> */
    public const PUBLIC_FIELDS = [
        'application',
        'backup_type',
        'created_at',
        'git_sha',
        'format_version',
        'encrypted',
    ];

    public static function isComplete(array $manifest): bool
    {
        return array_diff(self::REQUIRED_FIELDS, array_keys($manifest)) === [];
    }

    /** @return array<string, mixed> */
    public static function public(array $manifest): array
    {
        $public = [];
        foreach (self::PUBLIC_FIELDS as $field) {
            if (array_key_exists($field, $manifest)) {
                $public[$field] = $manifest[$field];
            }
        }

        return $public;
    }
}
