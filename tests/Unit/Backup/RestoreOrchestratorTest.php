<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\BackupRunData;
use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\BackupType;
use App\Modules\Backup\Application\Contracts\DatabaseRestorer;
use App\Modules\Backup\Application\Contracts\PreRestoreSnapshotter;
use App\Modules\Backup\Application\Contracts\RestoreEncryptor;
use App\Modules\Backup\Application\Contracts\RestoreHealthChecker;
use App\Modules\Backup\Application\Contracts\PostRestoreSchemaUpdater;
use App\Modules\Backup\Application\Contracts\RestorePackageExtractor;
use App\Modules\Backup\Application\Contracts\RestoreRollbackExecutor;
use App\Modules\Backup\Application\Contracts\RestoreSource;
use App\Modules\Backup\Application\Contracts\StorageRestorer;
use App\Modules\Backup\Application\RestoreCompatibilityValidator;
use App\Modules\Backup\Application\RestoreMode;
use App\Modules\Backup\Application\RestoreOrchestrator;
use App\Modules\Backup\Application\RestorePackageValidator;
use App\Modules\Backup\Application\RestoreRequest;
use App\Modules\Backup\Application\RestoreState;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

final class RestoreOrchestratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = '/private/tmp/byl-restore-orchestrator-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
        config(['backup.storage.workspace' => $this->root]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        Mockery::close();
        parent::tearDown();
    }

    public function test_database_only_success_never_calls_storage(): void
    {
        [$orchestrator, $calls] = $this->orchestrator();
        $result = $orchestrator->execute($this->request(RestoreMode::DatabaseOnly));

        self::assertSame(RestoreState::Completed, $result->state);
        self::assertSame(['database', 'migrations', 'health'], $calls['events']);
    }

    public function test_full_success_calls_storage(): void
    {
        [$orchestrator, $calls] = $this->orchestrator();
        $result = $orchestrator->execute($this->request(RestoreMode::Full));

        self::assertSame(RestoreState::Completed, $result->state);
        self::assertSame(['database', 'storage', 'migrations', 'health'], $calls['events']);
    }

    public function test_unverified_snapshot_blocks_all_destructive_calls(): void
    {
        [$orchestrator, $calls] = $this->orchestrator(false);
        $result = $orchestrator->execute($this->request(RestoreMode::Full));

        self::assertSame(RestoreState::Failed, $result->state);
        self::assertSame('pre_restore_snapshot_not_verified', $result->errorCode);
    }

    public function test_failure_before_destructive_phase_is_failed_without_rollback(): void
    {
        [$orchestrator, $calls] = $this->orchestrator(true, 'snapshot');
        $result = $orchestrator->execute($this->request(RestoreMode::DatabaseOnly));

        self::assertSame(RestoreState::Failed, $result->state);
        self::assertSame('restore_execution_failed', $result->errorCode);
    }

    public function test_post_destructive_failures_roll_back_exact_snapshot_and_mode(): void
    {
        foreach (['database', 'storage', 'migrations', 'health'] as $failure) {
            [$orchestrator, $calls, $snapshot] = $this->orchestrator(true, $failure);
            $calls['rollback']->shouldReceive('rollback')->once()->with($snapshot, RestoreMode::Full);

            $result = $orchestrator->execute($this->request(RestoreMode::Full));

            self::assertSame(RestoreState::RolledBack, $result->state, $failure);
            self::assertSame('restore_execution_failed', $result->errorCode, $failure);
        }
    }

    public function test_rollback_failure_is_failed_with_rollback_failed_code(): void
    {
        [$orchestrator, $calls] = $this->orchestrator(true, 'health');
        $calls['rollback']->shouldReceive('rollback')->once()->andThrow(new \RuntimeException('rollback'));

        $result = $orchestrator->execute($this->request(RestoreMode::Full));

        self::assertSame(RestoreState::Failed, $result->state);
        self::assertSame('rollback_failed', $result->errorCode);
    }

    private function orchestrator(bool $verified = true, ?string $failure = null): array
    {
        $events = [];
        $snapshot = new BackupRunData(
            7,
            'snapshot-uuid',
            BackupType::PreRestore,
            BackupState::Verified,
            1,
            CarbonImmutable::now(),
            metadata: [
                'pre_restore_artifact_path' => '/private/tmp/snapshot.tar.zst.enc',
                'pre_restore_artifact_size' => 123,
                'pre_restore_artifact_sha256' => str_repeat('a', 64),
            ],
        );
        $source = Mockery::mock(RestoreSource::class);
        $source->shouldReceive('materialize')->once()->andReturnUsing(function (string $source, string $destination, string $sha): void {
            file_put_contents($destination, 'encrypted fixture');
        });
        $encryptor = Mockery::mock(RestoreEncryptor::class);
        $encryptor->shouldReceive('inspect')->once()->andReturn(['header' => [], 'chunks' => 1]);
        $encryptor->shouldReceive('decrypt')->once()->andReturnUsing(function (string $source, string $destination): void {
            file_put_contents($destination, 'decrypted');
        });
        $extractor = Mockery::mock(RestorePackageExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturnUsing(function (string $package, string $destination): void {
            $this->createPackage($destination);
        });
        $snapshotter = Mockery::mock(PreRestoreSnapshotter::class);
        if ($failure === 'snapshot') {
            $snapshotter->shouldReceive('createAndVerify')->once()->andThrow(new \RuntimeException('snapshot'));
        } else {
            $snapshotter->shouldReceive('createAndVerify')->once()->andReturn($verified ? $snapshot : new BackupRunData(7, 'snapshot-uuid', BackupType::PreRestore, BackupState::Failed));
        }
        $database = Mockery::mock(DatabaseRestorer::class);
        $database->shouldReceive('restore')->zeroOrMoreTimes()->ordered()->andReturnUsing(function () use (&$events, $failure): void { $events[] = 'database'; if ($failure === 'database') throw new \RuntimeException('database'); });
        $storage = Mockery::mock(StorageRestorer::class);
        $storage->shouldReceive('restore')->zeroOrMoreTimes()->ordered()->andReturnUsing(function () use (&$events, $failure): void { $events[] = 'storage'; if ($failure === 'storage') throw new \RuntimeException('storage'); });
        $migrations = Mockery::mock(PostRestoreSchemaUpdater::class);
        $migrations->shouldReceive('run')->zeroOrMoreTimes()->ordered()->andReturnUsing(function () use (&$events, $failure): void { $events[] = 'migrations'; if ($failure === 'migrations') throw new \RuntimeException('migrations'); });
        $health = Mockery::mock(RestoreHealthChecker::class);
        $health->shouldReceive('check')->zeroOrMoreTimes()->ordered()->andReturnUsing(function () use (&$events, $failure): void { $events[] = 'health'; if ($failure === 'health') throw new \RuntimeException('health'); });
        $rollback = Mockery::mock(RestoreRollbackExecutor::class);
        $validator = new RestorePackageValidator(new RestoreCompatibilityValidator(new class implements \App\Modules\Backup\Application\Contracts\RestoreCompatibility {
            public function gitSha(): string { return str_repeat('a', 40); }
            public function databaseEngine(): string { return 'mysql'; }
            public function latestMigration(): ?string { return '2026_01'; }
            public function migrationCount(): int { return 1; }
        }));
        $calls = compact('database', 'storage', 'rollback');
        $calls['events'] =& $events;
        return [new RestoreOrchestrator($source, $encryptor, $extractor, $validator, $snapshotter, $database, $storage, $migrations, $rollback, $health), $calls, $snapshot];
    }

    private function request(RestoreMode $mode): RestoreRequest
    {
        return new RestoreRequest('local://fixture', hash('sha256', 'encrypted fixture'), $mode);
    }

    private function createPackage(string $directory): void
    {
        if (! is_dir($directory)) mkdir($directory, 0700, true);
        file_put_contents($directory.'/database.sql.gz', 'db');
        file_put_contents($directory.'/storage.tar.zst', 'storage');
        $manifest = ['application' => 'BYL ERP', 'backup_format_version' => 1, 'format_version' => 1, 'backup_type' => 'full', 'created_at' => '2026-09-01T00:00:00+00:00', 'git_sha' => str_repeat('a', 40), 'database_engine' => 'mysql', 'database_version' => '8', 'latest_migration' => '2026_01', 'migration_count' => 1, 'encrypted' => true, 'encryption_algorithm' => 'AES-256-GCM', 'encryption_key_id' => 'runtime-key', 'storage_included' => true, 'database_dump_size' => 2, 'storage_archive_size' => 7, 'components' => ['database.sql.gz', 'storage.tar.zst'], 'environment_classification' => 'testing', 'creator_source' => 'test'];
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $lines = [];
        foreach (['database.sql.gz', 'manifest.json', 'storage.tar.zst'] as $file) $lines[] = hash_file('sha256', $directory.'/'.$file).'  '.$file;
        file_put_contents($directory.'/checksums.sha256', implode("\n", $lines)."\n");
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path) || is_link($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) { if (in_array($entry, ['.', '..'], true)) continue; $child = $path.'/'.$entry; if (is_dir($child) && ! is_link($child)) $this->removeTree($child); else @unlink($child); }
        @rmdir($path);
    }
}
