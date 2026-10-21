<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\BackupArtifactData;
use App\Modules\Backup\Application\BackupRunData;
use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\BackupType;
use App\Modules\Backup\Application\Contracts\DatabaseRestorer;
use App\Modules\Backup\Application\Contracts\PreRestoreSnapshotter;
use App\Modules\Backup\Application\Contracts\RestoreCompatibility;
use App\Modules\Backup\Application\Contracts\RestoreEncryptor;
use App\Modules\Backup\Application\Contracts\RestoreHealthChecker;
use App\Modules\Backup\Application\Contracts\PostRestoreSchemaUpdater;
use App\Modules\Backup\Application\Contracts\RestorePackageExtractor;
use App\Modules\Backup\Application\Contracts\RestoreRollbackExecutor;
use App\Modules\Backup\Application\Contracts\StorageRestorer;
use App\Modules\Backup\Application\RestoreCompatibilityValidator;
use App\Modules\Backup\Application\RestoreFailure;
use App\Modules\Backup\Application\RestoreMode;
use App\Modules\Backup\Application\RestorePackageValidator;
use App\Modules\Backup\Infrastructure\ArtisanPostRestoreSchemaUpdater;
use App\Modules\Backup\Infrastructure\LocalPreRestoreSnapshotter;
use App\Modules\Backup\Infrastructure\MysqlDatabaseRestorer;
use App\Modules\Backup\Infrastructure\RuntimeRestoreHealthChecker;
use App\Modules\Backup\Infrastructure\SnapshotRestoreRollbackExecutor;
use App\Modules\Backup\Infrastructure\ZstdTarStorageRestorer;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class RestoreOperationalizationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = '/private/tmp/byl-restore-operationalization-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
        config(['backup.storage.workspace' => $this->root]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        Mockery::close();
        parent::tearDown();
    }

    public function test_container_resolves_all_runtime_restore_contracts(): void
    {
        self::assertInstanceOf(MysqlDatabaseRestorer::class, $this->app->make(DatabaseRestorer::class));
        self::assertInstanceOf(ZstdTarStorageRestorer::class, $this->app->make(StorageRestorer::class));
        self::assertInstanceOf(LocalPreRestoreSnapshotter::class, $this->app->make(PreRestoreSnapshotter::class));
        self::assertInstanceOf(ArtisanPostRestoreSchemaUpdater::class, $this->app->make(PostRestoreSchemaUpdater::class));
        self::assertInstanceOf(RuntimeRestoreHealthChecker::class, $this->app->make(RestoreHealthChecker::class));
        self::assertInstanceOf(SnapshotRestoreRollbackExecutor::class, $this->app->make(RestoreRollbackExecutor::class));
    }

    public function test_rollback_runtime_compatibility_ignores_damaged_current_migration_ledger_only(): void
    {
        $validator = new RestoreCompatibilityValidator($this->compatibility(true));
        $manifest = $this->manifest();
        self::assertSame([], $validator->rollbackRuntimeReasonCodes($manifest));
        self::assertSame(['current_compatibility_unavailable'], $validator->reasonCodes($manifest));
    }

    public function test_rollback_runtime_compatibility_still_rejects_git_mismatch(): void
    {
        $current = new class implements RestoreCompatibility {
            public function gitSha(): string { return str_repeat('b', 40); }
            public function databaseEngine(): string { return 'mysql'; }
            public function latestMigration(): ?string { throw new RuntimeException('damaged ledger'); }
            public function migrationCount(): int { throw new RuntimeException('damaged ledger'); }
        };
        try {
            (new RestoreCompatibilityValidator($current))->assertRollbackRuntimeCompatible($this->manifest());
            $this->fail('Expected rollback compatibility failure.');
        } catch (RestoreFailure $failure) {
            self::assertSame('git_sha_mismatch', $failure->reasonCode);
        }
    }

    public function test_mysql_restorer_fails_closed_before_external_tool_on_non_mysql_connection(): void
    {
        $dump = $this->root.'/fixture.sql.gz';
        $gz = gzopen($dump, 'wb9'); gzwrite($gz, 'SELECT 1;'); gzclose($gz);
        config(['database.default' => 'sqlite']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires the MySQL adapter');
        (new MysqlDatabaseRestorer())->restore($dump);
    }

    public function test_storage_restorer_rejects_scope_drift_before_external_tools(): void
    {
        $archive = $this->root.'/fixture.tar.zst';
        file_put_contents($archive, 'fixture');
        config(['backup.storage.included_roots' => ['storage/app/private']]);
        config(['backup.tooling.tar' => '/definitely/not-invoked/tar', 'backup.tooling.zstd' => '/definitely/not-invoked/zstd']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('frozen persistent storage scope');
        (new ZstdTarStorageRestorer())->restore($archive);
    }

    public function test_health_checker_rejects_non_mysql_runtime(): void
    {
        config(['database.default' => 'sqlite']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires MySQL');
        (new RuntimeRestoreHealthChecker())->check();
    }

    public function test_snapshot_rollback_restores_full_mode_even_when_current_ledger_is_unreadable(): void
    {
        $encrypted = $this->root.'/snapshot.tar.zst.enc';
        file_put_contents($encrypted, 'encrypted-snapshot');
        $artifact = new BackupArtifactData(1, 9, $encrypted, 'encrypted', filesize($encrypted), hash_file('sha256', $encrypted), true, 'test-key');
        $snapshot = new BackupRunData(
            9,
            '11111111-1111-4111-8111-111111111111',
            BackupType::PreRestore,
            BackupState::Ready,
            metadata: [
                'pre_restore_artifact_path' => $artifact->path,
                'pre_restore_artifact_size' => $artifact->size,
                'pre_restore_artifact_sha256' => $artifact->checksum,
                'pre_restore_artifact_key_id' => $artifact->keyId,
            ],
        );

        $encryptor = Mockery::mock(RestoreEncryptor::class);
        $encryptor->shouldReceive('inspect')->once()->with(realpath($encrypted))->andReturn(['header' => [], 'chunks' => 1]);
        $encryptor->shouldReceive('decrypt')->once()->andReturnUsing(fn (string $source, string $destination) => file_put_contents($destination, 'decrypted'));
        $extractor = Mockery::mock(RestorePackageExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturnUsing(function (string $source, string $destination): void { $this->createPackage($destination); });
        $database = Mockery::mock(DatabaseRestorer::class);
        $storage = Mockery::mock(StorageRestorer::class);
        $migrations = Mockery::mock(PostRestoreSchemaUpdater::class);
        $health = Mockery::mock(RestoreHealthChecker::class);
        $database->shouldReceive('restore')->once()->ordered();
        $storage->shouldReceive('restore')->once()->ordered();
        $migrations->shouldReceive('run')->once()->ordered();
        $health->shouldReceive('check')->once()->ordered();

        $validator = new RestorePackageValidator(new RestoreCompatibilityValidator($this->compatibility(true)));
        $executor = new SnapshotRestoreRollbackExecutor($encryptor, $extractor, $validator, $database, $storage, $migrations, $health);
        $executor->rollback($snapshot, RestoreMode::Full);
        self::assertDirectoryDoesNotExist($this->root.'/rollback/11111111-1111-4111-8111-111111111111');
    }

    public function test_snapshot_rollback_requires_pre_destructive_artifact_evidence_without_repository_lookup(): void
    {
        $snapshot = new BackupRunData(9, '22222222-2222-4222-8222-222222222222', BackupType::PreRestore, BackupState::Ready);
        $executor = new SnapshotRestoreRollbackExecutor(
            Mockery::mock(RestoreEncryptor::class),
            Mockery::mock(RestorePackageExtractor::class),
            new RestorePackageValidator(new RestoreCompatibilityValidator($this->compatibility(true))),
            Mockery::mock(DatabaseRestorer::class),
            Mockery::mock(StorageRestorer::class),
            Mockery::mock(PostRestoreSchemaUpdater::class),
            Mockery::mock(RestoreHealthChecker::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('evidence is invalid');
        $executor->rollback($snapshot, RestoreMode::Full);
    }

    private function compatibility(bool $ledgerThrows = false): RestoreCompatibility
    {
        return new class($ledgerThrows) implements RestoreCompatibility {
            public function __construct(private bool $ledgerThrows) {}
            public function gitSha(): string { return str_repeat('a', 40); }
            public function databaseEngine(): string { return 'mysql'; }
            public function latestMigration(): ?string { if ($this->ledgerThrows) throw new RuntimeException('damaged ledger'); return '2026_01'; }
            public function migrationCount(): int { if ($this->ledgerThrows) throw new RuntimeException('damaged ledger'); return 1; }
        };
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        return ['application' => 'BYL ERP', 'backup_format_version' => 1, 'format_version' => 1, 'backup_type' => 'pre_restore', 'created_at' => '2026-09-02T00:00:00+00:00', 'git_sha' => str_repeat('a', 40), 'database_engine' => 'mysql', 'database_version' => '8', 'latest_migration' => '2026_01', 'migration_count' => 1, 'encrypted' => true, 'encryption_algorithm' => 'AES-256-GCM', 'encryption_key_id' => 'test-key', 'storage_included' => true, 'database_dump_size' => 2, 'storage_archive_size' => 7, 'components' => ['database.sql.gz', 'storage.tar.zst'], 'environment_classification' => 'testing', 'creator_source' => 'test'];
    }

    private function createPackage(string $directory): void
    {
        file_put_contents($directory.'/database.sql.gz', 'db');
        file_put_contents($directory.'/storage.tar.zst', 'storage');
        $manifest = $this->manifest();
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $lines = [];
        foreach (['database.sql.gz', 'manifest.json', 'storage.tar.zst'] as $file) $lines[] = hash_file('sha256', $directory.'/'.$file).'  '.$file;
        file_put_contents($directory.'/checksums.sha256', implode("\n", $lines)."\n");
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (! is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) { if (in_array($entry, ['.', '..'], true)) continue; $this->removeTree($path.'/'.$entry); }
        @rmdir($path);
    }
}
