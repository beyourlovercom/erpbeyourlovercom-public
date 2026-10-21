<?php

namespace App\Modules\Backup\Application\Contracts;

use App\Modules\Backup\Application\BackupArtifactData;
use App\Modules\Backup\Application\BackupRunData;
use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\BackupType;

interface BackupRepository
{
    public function createRun(string $runUuid, BackupType $type, ?int $requestedBy): BackupRunData;

    public function findRun(int $id): ?BackupRunData;

    public function updateRun(int $id, array $attributes): BackupRunData;

    public function createArtifact(int $runId, array $attributes): BackupArtifactData;

    /** @return list<BackupArtifactData> */
    public function artifactsForRun(int $runId): array;

    public function deleteArtifact(int $id): void;

    public function markDeleted(int $id): BackupRunData;

    /** @return list<BackupRunData> */
    public function scheduledRunsNewestFirst(): array;

    /** @return list<BackupRunData> */
    public function recentRuns(int $limit = 5): array;
}
