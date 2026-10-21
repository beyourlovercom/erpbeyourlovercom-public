<?php

namespace App\Modules\Backup\Experience\Jobs;

use App\Modules\Backup\Application\BackupEngineService;
use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\Contracts\BackupRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class CreateBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;
    public int $timeout = 3600;
    public function __construct(public readonly int $runId) {}
    public function handle(BackupEngineService $engine, BackupRepository $repository): void { $run = $repository->findRun($this->runId); if ($run === null || ! self::isRunnable($run->state)) return; $engine->execute($run); }

    public static function isRunnable(BackupState $state): bool
    {
        return $state === BackupState::Queued;
    }
}
