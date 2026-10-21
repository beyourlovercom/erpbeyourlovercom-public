<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Experience\Jobs\CreateBackupJob;
use App\Modules\Backup\Experience\Jobs\PublishBackupToGithubJob;
use PHPUnit\Framework\TestCase;

final class CreateBackupJobTest extends TestCase
{
    public function test_only_queued_runs_are_runnable_until_resumable_execution_exists(): void
    {
        foreach (BackupState::cases() as $state) {
            if ($state === BackupState::Queued) continue;
            $this->assertFalse(CreateBackupJob::isRunnable($state));
        }
        $this->assertTrue(CreateBackupJob::isRunnable(BackupState::Queued));
    }

    public function test_github_publication_job_is_queued_only_and_single_attempt(): void
    {
        $job = new \App\Modules\Backup\Experience\Jobs\PublishBackupToGithubJob(9);
        $this->assertSame(9, $job->runId);
        $this->assertSame(1, $job->tries);
        $this->assertSame(3600, $job->timeout);
    }

    public function test_github_publication_job_only_treats_ready_as_operationally_runnable(): void
    {
        $this->assertTrue(PublishBackupToGithubJob::isRunnable(BackupState::Ready));
        $this->assertFalse(PublishBackupToGithubJob::isRunnable(BackupState::Verified));
        $this->assertFalse(PublishBackupToGithubJob::isRunnable(BackupState::Publishing));
    }
}
