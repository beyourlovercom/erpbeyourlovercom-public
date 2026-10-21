<?php

namespace App\Modules\Backup\Application;

use JsonException;

final readonly class RestorePackageValidator
{
    /** @var list<string> */
    private const PACKAGE_FILES = ['checksums.sha256', 'database.sql.gz', 'manifest.json', 'storage.tar.zst'];

    /** @var list<string> */
    private const CHECKSUMMED_FILES = ['database.sql.gz', 'manifest.json', 'storage.tar.zst'];

    public function __construct(private RestoreCompatibilityValidator $compatibility) {}

    /** @return array<string, mixed> */
    public function validate(string $directory, RestoreMode $mode): array
    {
        $manifest = $this->validateCore($directory, $mode);
        $this->compatibility->assertCompatible($manifest);
        return $manifest;
    }

    /** @return array<string, mixed> */
    public function validateRollbackSnapshot(string $directory, RestoreMode $mode): array
    {
        $manifest = $this->validateCore($directory, $mode);
        if (($manifest['backup_type'] ?? null) !== BackupType::PreRestore->value) {
            throw new RestoreFailure('rollback_snapshot_type_invalid');
        }
        $this->compatibility->assertRollbackRuntimeCompatible($manifest);
        return $manifest;
    }

    /** @return array<string, mixed> */
    private function validateCore(string $directory, RestoreMode $mode): array
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root) || is_link($root)) {
            throw new RestoreFailure('package_missing');
        }

        $entries = array_values(array_filter(scandir($root) ?: [], static fn (string $entry): bool => ! in_array($entry, ['.', '..'], true)));
        sort($entries);
        if ($entries !== self::PACKAGE_FILES) {
            throw new RestoreFailure('unexpected_package_file');
        }

        foreach (self::PACKAGE_FILES as $file) {
            $path = $root.DIRECTORY_SEPARATOR.$file;
            $real = realpath($path);
            if ($real === false || ! is_file($real) || is_link($path) || ! $this->within($real, $root)) {
                throw new RestoreFailure('unsafe_package_entry');
            }
        }

        if (! $this->checksumsMatch($root.'/checksums.sha256', $root)) {
            throw new RestoreFailure('package_checksum_mismatch');
        }

        try {
            $manifest = json_decode((string) file_get_contents($root.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RestoreFailure('manifest_invalid');
        }
        if (! is_array($manifest) || ! ManifestContract::isComplete($manifest)) {
            throw new RestoreFailure('manifest_incomplete');
        }
        if (($manifest['backup_format_version'] ?? null) !== 1
            || ($manifest['encrypted'] ?? null) !== true
            || ($manifest['encryption_algorithm'] ?? null) !== 'AES-256-GCM') {
            throw new RestoreFailure('manifest_format_mismatch');
        }

        $backupType = $manifest['backup_type'] ?? null;
        if (! in_array($backupType, array_column(BackupType::cases(), 'value'), true)) {
            throw new RestoreFailure('backup_type_invalid');
        }
        $components = $manifest['components'] ?? null;
        if (! is_array($components) || array_values(array_filter($components, 'is_string')) !== $components) {
            throw new RestoreFailure('components_invalid');
        }
        $expectedComponents = $backupType === BackupType::Database->value
            ? ['database.sql.gz']
            : ['database.sql.gz', 'storage.tar.zst'];
        if ($components !== $expectedComponents) {
            throw new RestoreFailure('components_mismatch');
        }

        $storageIncluded = $manifest['storage_included'] ?? null;
        if (! is_bool($storageIncluded) || $storageIncluded !== ($backupType !== BackupType::Database->value)) {
            throw new RestoreFailure('storage_manifest_mismatch');
        }
        if ($mode === RestoreMode::Full && $storageIncluded !== true) {
            throw new RestoreFailure('storage_not_included');
        }

        $databaseSize = filesize($root.'/database.sql.gz');
        $storageSize = filesize($root.'/storage.tar.zst');
        if (! is_int($manifest['database_dump_size'] ?? null)
            || ! is_int($manifest['storage_archive_size'] ?? null)
            || $databaseSize === false
            || $storageSize === false
            || $manifest['database_dump_size'] !== $databaseSize
            || $manifest['storage_archive_size'] !== $storageSize) {
            throw new RestoreFailure('component_size_mismatch');
        }

        return $manifest;
    }

    private function checksumsMatch(string $checksumFile, string $root): bool
    {
        $lines = file($checksumFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        $seen = [];
        foreach ($lines as $line) {
            if (! preg_match('/^([a-f0-9]{64})  ([A-Za-z0-9._-]+)$/', $line, $match)
                || ! in_array($match[2], self::CHECKSUMMED_FILES, true)
                || isset($seen[$match[2]])) {
                return false;
            }
            $seen[$match[2]] = true;
            $actual = hash_file('sha256', $root.DIRECTORY_SEPARATOR.$match[2]);
            if ($actual === false || ! hash_equals($match[1], $actual)) {
                return false;
            }
        }

        $names = array_keys($seen);
        sort($names);
        $expected = self::CHECKSUMMED_FILES;
        sort($expected);
        return $names === $expected;
    }

    private function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
