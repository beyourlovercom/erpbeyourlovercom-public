<?php

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Application\Contracts\RestoreCompatibility;
use Illuminate\Support\Facades\DB;

final class CurrentRestoreCompatibility implements RestoreCompatibility
{
    public function gitSha(): string
    {
        $process = proc_open(['git', '-C', base_path(), 'rev-parse', 'HEAD'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        if (! is_resource($process)) {
            return 'unknown';
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $sha = is_string($stdout) ? trim($stdout) : '';

        return $code === 0 && preg_match('/^[0-9a-f]{40}$/', $sha) ? $sha : 'unknown';
    }

    public function databaseEngine(): string
    {
        return (string) DB::connection()->getDriverName();
    }

    public function latestMigration(): ?string
    {
        $value = DB::table('migrations')->orderByDesc('batch')->orderByDesc('migration')->value('migration');
        return $value === null ? null : (string) $value;
    }

    public function migrationCount(): int
    {
        return (int) DB::table('migrations')->count();
    }
}
