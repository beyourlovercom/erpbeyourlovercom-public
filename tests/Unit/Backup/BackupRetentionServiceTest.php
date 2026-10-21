<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\BackupArtifactCleanupService;
use App\Modules\Backup\Application\BackupRetentionService;
use App\Modules\Backup\Application\BackupRunData;
use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\BackupType;
use App\Modules\Backup\Application\Contracts\BackupRepository;
use App\Modules\Backup\Application\Contracts\GithubReleaseDeletionGateway;
use Mockery;
use Tests\TestCase;

final class BackupRetentionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_retention_keeps_five_newest_verified_published_scheduled_backups(): void
    {
        config(['backup.github.repository' => 'beyourlovercom/erp-backups']);
        $runs = array_map(fn (int $id): BackupRunData => $this->safeRun($id), range(7, 1));
        $repository = Mockery::mock(BackupRepository::class);
        $repository->shouldReceive('scheduledRunsNewestFirst')->once()->andReturn($runs);
        $github = Mockery::mock(GithubReleaseDeletionGateway::class);

        foreach ([$runs[5], $runs[6]] as $run) {
            $github->shouldReceive('delete')->once()->with('beyourlovercom/erp-backups', (string) (1000 + $run->id), 'byl-erp-backup-'.$run->runUuid);
            $repository->shouldReceive('artifactsForRun')->once()->with($run->id)->andReturn([]);
            $repository->shouldReceive('markDeleted')->once()->with($run->id)->andReturn($run->withState(BackupState::Deleted));
        }

        $cleanup = new BackupArtifactCleanupService($repository);
        $result = (new BackupRetentionService($repository, $github, $cleanup))->prune(5);

        $this->assertSame(2, $result->deleted);
        $this->assertSame(0, $result->blocked);
    }

    public function test_failed_or_unpublished_runs_never_displace_a_valid_backup(): void
    {
        config(['backup.github.repository' => 'beyourlovercom/erp-backups']);
        $safe = array_map(fn (int $id): BackupRunData => $this->safeRun($id), range(6, 1));
        $unsafe = new BackupRunData(99, 'unsafe', BackupType::Full, BackupState::Ready, null, null, null, null, ['origin' => 'scheduled']);
        $runs = [$unsafe, ...$safe];
        $repository = Mockery::mock(BackupRepository::class);
        $repository->shouldReceive('scheduledRunsNewestFirst')->once()->andReturn($runs);
        $oldestSafe = $safe[5];
        $github = Mockery::mock(GithubReleaseDeletionGateway::class);
        $github->shouldReceive('delete')->once()->with('beyourlovercom/erp-backups', (string) (1000 + $oldestSafe->id), 'byl-erp-backup-'.$oldestSafe->runUuid);
        $repository->shouldReceive('artifactsForRun')->once()->with($oldestSafe->id)->andReturn([]);
        $repository->shouldReceive('markDeleted')->once()->with($oldestSafe->id)->andReturn($oldestSafe->withState(BackupState::Deleted));

        $result = (new BackupRetentionService($repository, $github, new BackupArtifactCleanupService($repository)))->prune(5);

        $this->assertSame(1, $result->deleted);
        $this->assertSame(1, $result->blocked);
    }

    public function test_remote_deletion_failure_prevents_local_record_cleanup(): void
    {
        config(['backup.github.repository' => 'beyourlovercom/erp-backups']);
        $runs = array_map(fn (int $id): BackupRunData => $this->safeRun($id), range(6, 1));
        $candidate = $runs[5];
        $repository = Mockery::mock(BackupRepository::class);
        $repository->shouldReceive('scheduledRunsNewestFirst')->once()->andReturn($runs);
        $repository->shouldReceive('artifactsForRun')->never();
        $repository->shouldReceive('markDeleted')->never();
        $github = Mockery::mock(GithubReleaseDeletionGateway::class);
        $github->shouldReceive('delete')->once()->andThrow(new \RuntimeException('remote uncertain'));

        $this->expectException(\RuntimeException::class);
        (new BackupRetentionService($repository, $github, new BackupArtifactCleanupService($repository)))->prune(5);
    }

    private function safeRun(int $id): BackupRunData
    {
        $uuid = 'run-'.$id;
        return new BackupRunData($id, $uuid, BackupType::Full, BackupState::Verified, null, null, null, null, [
            'origin' => 'scheduled',
            'github_publish_status' => 'published',
            'repository' => 'beyourlovercom/erp-backups',
            'release_id' => (string) (1000 + $id),
            'release_tag' => 'byl-erp-backup-'.$uuid,
        ]);
    }
}
