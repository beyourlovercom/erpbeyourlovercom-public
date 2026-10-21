<?php

namespace App\Modules\Backup\Application;

use App\Modules\Backup\Application\Contracts\BackupRepository;
use App\Modules\Backup\Application\Contracts\GithubReleaseDeletionGateway;

final readonly class BackupRetentionService
{
    public function __construct(
        private BackupRepository $repository,
        private GithubReleaseDeletionGateway $github,
        private BackupArtifactCleanupService $cleanup,
    ) {}

    public function prune(int $keep): BackupRetentionResult
    {
        if ($keep < 1) {
            throw new \InvalidArgumentException('Backup retention count must be at least one.');
        }

        $runs = $this->repository->scheduledRunsNewestFirst();
        $eligible = array_values(array_filter($runs, fn (BackupRunData $run): bool => $this->safeToDelete($run)));
        $deleted = 0;
        $blocked = count($runs) - count($eligible);

        foreach (array_slice($eligible, $keep) as $run) {

            $repository = (string) $run->metadata['repository'];
            $releaseId = (string) $run->metadata['release_id'];
            $releaseTag = (string) $run->metadata['release_tag'];
            $this->github->delete($repository, $releaseId, $releaseTag);
            $this->cleanup->delete($run);
            $deleted++;
        }

        return new BackupRetentionResult($deleted, $blocked);
    }

    private function safeToDelete(BackupRunData $run): bool
    {
        if ($run->backupType !== BackupType::Full || $run->state !== BackupState::Verified) {
            return false;
        }

        $metadata = $run->metadata;
        if (($metadata['origin'] ?? null) !== 'scheduled' || ($metadata['github_publish_status'] ?? null) !== 'published') {
            return false;
        }

        $repository = $metadata['repository'] ?? null;
        $releaseId = $metadata['release_id'] ?? null;
        $releaseTag = $metadata['release_tag'] ?? null;

        return is_string($repository)
            && $repository === (string) config('backup.github.repository')
            && (is_string($releaseId) || is_int($releaseId))
            && (string) $releaseId !== ''
            && is_string($releaseTag)
            && $releaseTag === 'byl-erp-backup-'.$run->runUuid;
    }
}
