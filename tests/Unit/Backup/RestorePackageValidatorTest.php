<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\Contracts\RestoreCompatibility;
use App\Modules\Backup\Application\RestoreCompatibilityValidator;
use App\Modules\Backup\Application\RestoreFailure;
use App\Modules\Backup\Application\RestoreMode;
use App\Modules\Backup\Application\RestorePackageValidator;
use PHPUnit\Framework\TestCase;

final class RestorePackageValidatorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = '/private/tmp/byl-restore-package-'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0700, true);
        $this->writePackage('full');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function test_valid_full_package_passes_and_database_mode_can_ignore_storage(): void
    {
        $validator = $this->validator();
        self::assertSame('full', $validator->validate($this->directory, RestoreMode::Full)['backup_type']);
        self::assertSame('full', $validator->validate($this->directory, RestoreMode::DatabaseOnly)['backup_type']);
    }

    public function test_full_restore_requires_storage_in_manifest(): void
    {
        $this->writePackage('database');
        $this->assertReason('storage_not_included', fn () => $this->validator()->validate($this->directory, RestoreMode::Full));
    }

    public function test_checksum_mismatch_is_rejected(): void
    {
        file_put_contents($this->directory.'/database.sql.gz', 'tampered');
        $this->assertReason('package_checksum_mismatch', fn () => $this->validator()->validate($this->directory, RestoreMode::Full));
    }

    public function test_incomplete_manifest_is_rejected(): void
    {
        $manifest = json_decode((string) file_get_contents($this->directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($manifest['git_sha']);
        file_put_contents($this->directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->rewriteChecksums();
        $this->assertReason('manifest_incomplete', fn () => $this->validator()->validate($this->directory, RestoreMode::Full));
    }

    public function test_unexpected_file_and_symlink_are_rejected(): void
    {
        file_put_contents($this->directory.'/unexpected.env', 'no');
        try {
            $this->validator()->validate($this->directory, RestoreMode::Full);
            self::fail('Unexpected package file was accepted.');
        } catch (RestoreFailure $e) {
            self::assertSame('unexpected_package_file', $e->reasonCode);
        }
        unlink($this->directory.'/unexpected.env');

        unlink($this->directory.'/storage.tar.zst');
        symlink($this->directory.'/database.sql.gz', $this->directory.'/storage.tar.zst');
        $this->assertReason('unsafe_package_entry', fn () => $this->validator()->validate($this->directory, RestoreMode::Full));
    }

    public function test_component_size_mismatch_is_rejected(): void
    {
        $manifest = json_decode((string) file_get_contents($this->directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['database_dump_size']++;
        file_put_contents($this->directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->rewriteChecksums();
        $this->assertReason('component_size_mismatch', fn () => $this->validator()->validate($this->directory, RestoreMode::Full));
    }

    public function test_manifest_format_and_compatibility_mismatches_are_rejected(): void
    {
        foreach ([
            'backup_format_version' => 'manifest_format_mismatch',
            'git_sha' => 'git_sha_mismatch',
            'database_engine' => 'database_engine_mismatch',
            'latest_migration' => 'latest_migration_mismatch',
            'migration_count' => 'migration_count_mismatch',
        ] as $field => $reason) {
            $this->writePackage('full');
            $manifest = json_decode((string) file_get_contents($this->directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $manifest[$field] = match ($field) {
                'backup_format_version' => 2,
                'git_sha' => str_repeat('b', 40),
                'database_engine' => 'pgsql',
                'latest_migration' => '2026_02',
                'migration_count' => 2,
            };
            file_put_contents($this->directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->rewriteChecksums();
            $this->assertReason($reason, fn () => $this->validator()->validate($this->directory, RestoreMode::Full));
        }
    }

    private function validator(): RestorePackageValidator
    {
        $current = new class implements RestoreCompatibility {
            public function gitSha(): string { return str_repeat('a', 40); }
            public function databaseEngine(): string { return 'mysql'; }
            public function latestMigration(): ?string { return '2026_01'; }
            public function migrationCount(): int { return 1; }
        };

        return new RestorePackageValidator(new RestoreCompatibilityValidator($current));
    }

    private function writePackage(string $backupType): void
    {
        foreach (['database.sql.gz', 'storage.tar.zst', 'manifest.json', 'checksums.sha256'] as $file) {
            @unlink($this->directory.'/'.$file);
        }
        file_put_contents($this->directory.'/database.sql.gz', 'db');
        file_put_contents($this->directory.'/storage.tar.zst', $backupType === 'database' ? '' : 'storage');
        $manifest = [
            'application' => 'BYL ERP',
            'backup_format_version' => 1,
            'format_version' => 1,
            'backup_type' => $backupType,
            'created_at' => '2026-09-01T00:00:00+00:00',
            'git_sha' => str_repeat('a', 40),
            'database_engine' => 'mysql',
            'database_version' => '8',
            'latest_migration' => '2026_01',
            'migration_count' => 1,
            'encrypted' => true,
            'encryption_algorithm' => 'AES-256-GCM',
            'encryption_key_id' => 'runtime-key',
            'storage_included' => $backupType !== 'database',
            'database_dump_size' => filesize($this->directory.'/database.sql.gz'),
            'storage_archive_size' => filesize($this->directory.'/storage.tar.zst'),
            'components' => $backupType === 'database'
                ? ['database.sql.gz']
                : ['database.sql.gz', 'storage.tar.zst'],
            'environment_classification' => 'testing',
            'creator_source' => 'backup-engine',
        ];
        file_put_contents(
            $this->directory.'/manifest.json',
            json_encode($manifest, JSON_THROW_ON_ERROR),
        );
        $this->rewriteChecksums();
    }

    private function rewriteChecksums(): void
    {
        $lines = [];
        foreach (['database.sql.gz', 'manifest.json', 'storage.tar.zst'] as $file) {
            $lines[] = hash_file('sha256', $this->directory.'/'.$file).'  '.$file;
        }
        file_put_contents($this->directory.'/checksums.sha256', implode("\n", $lines)."\n");
    }

    private function assertReason(string $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected restore failure [{$reason}].");
        } catch (RestoreFailure $e) {
            self::assertSame($reason, $e->reasonCode);
        }
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) continue;
            $child = $path.'/'.$entry;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
