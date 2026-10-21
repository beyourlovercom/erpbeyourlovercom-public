<?php

namespace App\Modules\Backup\Experience\Jobs;

use App\Modules\Backup\Application\BackupRetentionService;
use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\Contracts\BackupRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ApplyBackupRetentionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public readonly int $runId) {}

    public function handle(BackupRetentionService $retention, BackupRepository $repository): void
    {
        $run = $repository->findRun($this->runId);
        if ($run === null || $run->state !== BackupState::Verified) {
            return;
        }

        if (($run->metadata['origin'] ?? null) !== 'scheduled'
            || ($run->metadata['github_publish_status'] ?? null) !== 'published') {
            return;
        }

        $retention->prune((int) config('backup.automatic.retention_count', 5));
    }
}
