<?php

namespace App\Modules\Backup\Application;

use App\Modules\Backup\Application\Contracts\StorageArchiver;
use RuntimeException;

class StorageArchiveAdapter implements StorageArchiver
{
    public function archive(string $destination): void
    {
        $roots = config('backup.storage.included_roots', []);
        $configuredWorkspace = (string) config('backup.storage.workspace', 'storage/app/backups');
        $workspaceConfiguredPath = str_starts_with($configuredWorkspace, DIRECTORY_SEPARATOR) ? $configuredWorkspace : base_path($configuredWorkspace);
        $workspace = realpath($workspaceConfiguredPath) ?: $this->normalizePath($workspaceConfiguredPath);
        $paths = [];
        foreach ($roots as $root) {
            if (! in_array($root, ['storage/app/private', 'storage/app/public'], true)) {
                throw new RuntimeException('Backup storage roots must match the frozen persistent storage scope.');
            }
            $absolute = realpath(base_path($root));
            if ($absolute === false || $this->within($workspace, $absolute) || $this->within($absolute, $workspace)) {
                throw new RuntimeException('Configured backup root is invalid or overlaps the backup workspace.');
            }
            $paths[] = $absolute;
        }
        $zstd = (string) config('backup.tooling.zstd', 'zstd');
        $tar = (string) config('backup.tooling.tar', 'tar');
        $command = [$tar, '-cf', '-', '--', ...array_map(static fn (string $p): string => ltrim(str_replace(base_path().'/', '', $p), '/'), $paths)];
        $tarPath = $destination.'.tar';
        try { $this->runToFile($command, $tarPath); $this->runToFile([$zstd, '-q', '-T0', '-f', $tarPath, '-o', $destination], null); } finally { @unlink($tarPath); }
    }

    /** @param list<string> $command */
    protected function runToFile(array $command, ?string $destination): void
    {
        $spec = [1 => $destination === null ? ['pipe', 'w'] : ['file', $destination, 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $spec, $pipes, base_path());
        if (! is_resource($process)) throw new RuntimeException('Unable to start storage archive tool.');
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Storage archive failed.');
    }

    private function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private function normalizePath(string $path): string
    {
        $prefix = str_starts_with($path, DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '';
        $parts = [];
        foreach (explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR)) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') { array_pop($parts); continue; }
            $parts[] = $part;
        }
        return ($prefix === '' ? '' : DIRECTORY_SEPARATOR).implode(DIRECTORY_SEPARATOR, $parts);
    }
}
