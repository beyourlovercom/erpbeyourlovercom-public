<?php

namespace App\Modules\Backup\Experience\Jobs;

use App\Modules\Backup\Application\GithubReleasePublisher;
use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\Contracts\BackupRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class PublishBackupToGithubJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;
    public int $timeout = 3600;
    public function __construct(public readonly int $runId) {}
    public static function isRunnable(BackupState $state): bool
    { return $state === BackupState::Ready; }
    public function handle(GithubReleasePublisher $publisher, BackupRepository $repository): void
    { $run = $repository->findRun($this->runId); if ($run !== null && (self::isRunnable($run->state) || ($run->state === BackupState::Verified && ($run->metadata['github_publish_status'] ?? null) === 'published'))) $publisher->publish($run); }
}
