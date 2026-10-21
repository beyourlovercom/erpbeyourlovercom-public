<?php

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Application\Contracts\RestoreHealthChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class RuntimeRestoreHealthChecker implements RestoreHealthChecker
{
    public function check(): void
    {
        $connection = (string) config('database.default');
        $settings = config("database.connections.{$connection}");
        if (! is_array($settings) || ($settings['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('Restore health check requires MySQL.');
        }

        DB::purge($connection);
        $probe = DB::selectOne('SELECT 1 AS restore_health');
        if ($probe === null || (int) ($probe->restore_health ?? 0) !== 1 || ! Schema::hasTable('migrations')) {
            throw new RuntimeException('Restore database health check failed.');
        }

        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles(database_path('migrations'));
        $pending = array_diff(array_keys($files), $migrator->getRepository()->getRan());
        if ($pending !== []) {
            throw new RuntimeException('Restore health check found pending migrations.');
        }

        foreach ((array) config('backup.storage.included_roots', []) as $root) {
            if (! in_array($root, ['storage/app/private', 'storage/app/public'], true)) {
                throw new RuntimeException('Restore storage health scope is invalid.');
            }
            $path = base_path($root);
            if (! is_dir($path) || is_link($path) || realpath($path) === false) {
                throw new RuntimeException('Restore storage health check failed.');
            }
        }
    }
}
