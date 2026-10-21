<?php

namespace App\Modules\Backup\Application;

use Illuminate\Support\Facades\DB;

final class ManifestBuilder
{
    /** @return array<string,mixed> */
    public function build(BackupType $type, string $createdAt, int $databaseSize = 0, int $storageSize = 0): array
    {
        $connection = DB::connection(); $migrationCount = (int) ($connection->table('migrations')->count() ?? 0);
        $latest = $connection->table('migrations')->orderByDesc('batch')->orderByDesc('migration')->value('migration');
        return [
            'application' => (string) config('app.name', 'BYL ERP'), 'backup_format_version' => 1, 'format_version' => 1, 'backup_type' => $type->value,
            'created_at' => $createdAt, 'git_sha' => $this->gitSha(), 'database_engine' => $connection->getDriverName(),
            'database_version' => $this->databaseVersion(), 'latest_migration' => $latest, 'migration_count' => $migrationCount,
            'encrypted' => true, 'encryption_algorithm' => 'AES-256-GCM', 'encryption_key_id' => config('backup.encryption.key_id'),
            'storage_included' => $type !== BackupType::Database, 'database_dump_size' => $databaseSize, 'storage_archive_size' => $storageSize,
            'components' => $type === BackupType::Database ? ['database.sql.gz'] : ['database.sql.gz', 'storage.tar.zst'],
            'environment_classification' => app()->environment(), 'creator_source' => 'backup-engine',
        ];
    }

    private function gitSha(): string
    {
        $sha = @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse HEAD');
        return is_string($sha) && preg_match('/^[0-9a-f]{40}$/', trim($sha)) ? trim($sha) : 'unknown';
    }

    private function databaseVersion(): ?string
    {
        try { return (string) DB::selectOne('select version() as version')->version; } catch (\Throwable) { return null; }
    }
}
