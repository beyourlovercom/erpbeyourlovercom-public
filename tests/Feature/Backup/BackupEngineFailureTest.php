<?php

namespace Tests\Feature\Backup;

use App\Modules\Backup\Application\BackupEngineService;
use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\BackupType;
use App\Modules\Backup\Application\DatabaseDumpAdapter;
use App\Modules\Backup\Application\PackageBuilder;
use App\Modules\Backup\Application\StorageArchiveAdapter;
use App\Modules\Backup\Application\ChecksumService;
use App\Modules\Backup\Application\ManifestBuilder;
use App\Modules\Backup\Application\StreamingAesGcmEncryptor;
use App\Modules\Backup\Application\BackupArtifactCleanupService;
use App\Modules\Backup\Application\Contracts\BackupRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

final class BackupEngineFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_dump_failure_is_sanitized_and_plaintext_workspace_is_removed(): void
    {
        $secret = 'FAKE-BACKUP-SECRET-987';
        $workspace = base_path('storage/framework/testing/backup-engine-failure');
        @mkdir($workspace, 0700, true);
        Config::set('backup.storage.workspace', $workspace);
        $repository = $this->app->make(BackupRepository::class);
        $run = $repository->createRun('11111111-1111-4111-8111-111111111111', BackupType::Database, null);
        $engine = new BackupEngineService(new ThrowingDumpAdapter($secret), new StorageArchiveAdapter(), new StreamingAesGcmEncryptor(), new ManifestBuilder(), new ChecksumService(), new PackageBuilder(), new BackupArtifactCleanupService($repository), $repository);

        try {
            $engine->execute($run);
            $this->fail('Expected backup execution failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame($secret, $exception->getMessage());
        }

        $run = $repository->findRun($run->id);
        $this->assertSame(BackupState::Failed, $run->state);
        $this->assertNotNull($run->startedAt);
        $this->assertSame('backup_failed', $run->errorCode);
        $this->assertSame('Backup execution failed.', $run->errorMessage);
        $this->assertStringNotContainsString($secret, 'Backup execution failed.');
        $this->assertDirectoryDoesNotExist($workspace.'/'. $run->runUuid);
    }

    public function test_started_at_is_set_once_and_preserved_across_transitions(): void
    {
        $repository = $this->app->make(BackupRepository::class);
        $run = $repository->createRun('22222222-2222-4222-8222-222222222222', BackupType::Database, null);
        $engine = new BackupEngineService(new ThrowingDumpAdapter('unused'), new StorageArchiveAdapter(), new StreamingAesGcmEncryptor(), new ManifestBuilder(), new ChecksumService(), new PackageBuilder(), new BackupArtifactCleanupService($repository), $repository);

        $run = $engine->transition($run, BackupState::Dumping);
        $startedAt = $run->startedAt;
        $run = $engine->transition($run, BackupState::Packaging);

        $this->assertNotNull($startedAt);
        $this->assertEquals($startedAt, $run->startedAt);
    }
}

final class ThrowingDumpAdapter extends DatabaseDumpAdapter
{
    public function __construct(private readonly string $secret) {}

    public function dump(string $destination): void
    {
        throw new RuntimeException($this->secret);
    }
}
