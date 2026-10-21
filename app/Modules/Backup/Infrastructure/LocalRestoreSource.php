<?php

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Application\Contracts\RestoreSource;
use App\Modules\Backup\Application\RestoreFailure;

final class LocalRestoreSource implements RestoreSource
{
    public function materialize(string $source, string $destination, string $expectedSha256): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $expectedSha256)) {
            throw new RestoreFailure('encrypted_checksum_invalid');
        }
        if (! str_ends_with($source, (string) config('backup.format.asset_suffix', '.tar.zst.enc'))) {
            throw new RestoreFailure('invalid_source_suffix');
        }

        $root = $this->backupRoot();
        $path = realpath($source);
        if ($path === false || ! is_file($path) || is_link($source) || ! $this->within($path, $root)) {
            throw new RestoreFailure('source_path_outside_allowed_root');
        }
        $this->assertDestination($destination, $root);

        if (! copy($path, $destination)) {
            throw new RestoreFailure('source_unavailable');
        }
        $actual = hash_file('sha256', $destination);
        if ($actual === false || ! hash_equals($expectedSha256, $actual)) {
            @unlink($destination);
            throw new RestoreFailure('encrypted_checksum_mismatch');
        }
    }

    private function backupRoot(): string
    {
        $configured = (string) config('backup.storage.workspace', 'storage/app/backups');
        $root = str_starts_with($configured, DIRECTORY_SEPARATOR) ? $configured : base_path($configured);
        $real = realpath($root);
        if ($real === false || ! is_dir($real) || is_link($root)) {
            throw new RestoreFailure('workspace_unavailable');
        }
        return $real;
    }

    private function assertDestination(string $destination, string $root): void
    {
        if (! str_ends_with($destination, '.tar.zst.enc') || file_exists($destination) || is_link($destination)) {
            throw new RestoreFailure('restore_destination_invalid');
        }
        $restoreRoot = realpath($root.'/restore');
        $parent = realpath(dirname($destination));
        if ($restoreRoot === false || $parent === false || ! $this->within($parent, $restoreRoot)) {
            throw new RestoreFailure('restore_destination_invalid');
        }
    }

    private function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
