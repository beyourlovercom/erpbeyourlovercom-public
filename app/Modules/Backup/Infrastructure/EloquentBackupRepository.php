<?php

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Application\BackupArtifactData;
use App\Modules\Backup\Application\BackupRunData;
use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\BackupType;
use App\Modules\Backup\Application\Contracts\BackupRepository;
use App\Modules\Backup\Infrastructure\Models\BackupArtifact;
use App\Modules\Backup\Infrastructure\Models\BackupRun;

final class EloquentBackupRepository implements BackupRepository
{
    public function createRun(string $runUuid, BackupType $type, ?int $requestedBy): BackupRunData
    {
        return $this->runData(BackupRun::query()->create([
            'run_uuid' => $runUuid,
            'backup_type' => $type,
            'state' => BackupState::Queued,
            'requested_by' => $requestedBy,
        ]));
    }

    public function findRun(int $id): ?BackupRunData
    {
        $run = BackupRun::query()->find($id);
        return $run === null ? null : $this->runData($run);
    }

    public function updateRun(int $id, array $attributes): BackupRunData
    {
        $run = BackupRun::query()->findOrFail($id);
        $run->fill($attributes)->save();
        return $this->runData($run->fresh());
    }

    public function createArtifact(int $runId, array $attributes): BackupArtifactData
    {
        return $this->artifactData(BackupArtifact::query()->create(['backup_run_id' => $runId, ...$attributes]));
    }

    public function artifactsForRun(int $runId): array
    {
        return BackupArtifact::query()->where('backup_run_id', $runId)->get()->map(fn (BackupArtifact $artifact): BackupArtifactData => $this->artifactData($artifact))->all();
    }

    public function deleteArtifact(int $id): void
    {
        BackupArtifact::query()->findOrFail($id)->delete();
    }

    public function markDeleted(int $id): BackupRunData
    {
        return $this->updateRun($id, ['state' => BackupState::Deleted, 'completed_at' => now()]);
    }

    public function scheduledRunsNewestFirst(): array
    {
        return BackupRun::query()
            ->where('state', '!=', BackupState::Deleted->value)
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (BackupRun $run): bool => ($run->metadata['origin'] ?? null) === 'scheduled')
            ->map(fn (BackupRun $run): BackupRunData => $this->runData($run))
            ->values()
            ->all();
    }

    public function recentRuns(int $limit = 5): array
    {
        return BackupRun::query()
            ->where('state', '!=', BackupState::Deleted->value)
            ->orderByDesc('created_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (BackupRun $run): BackupRunData => $this->runData($run))
            ->all();
    }

    private function runData(BackupRun $run): BackupRunData
    {
        return new BackupRunData(
            (int) $run->id,
            (string) $run->run_uuid,
            $run->backup_type,
            $run->state,
            $run->requested_by === null ? null : (int) $run->requested_by,
            $run->started_at,
            $run->error_code,
            $run->error_message,
            $run->metadata ?? [],
            $run->completed_at,
            $run->created_at,
        );
    }

    private function artifactData(BackupArtifact $artifact): BackupArtifactData
    {
        return new BackupArtifactData((int) $artifact->id, (int) $artifact->backup_run_id, (string) $artifact->path, (string) $artifact->kind, (int) $artifact->size, (string) $artifact->checksum, (bool) $artifact->encrypted, $artifact->key_id === null ? null : (string) $artifact->key_id, $artifact->package_checksum === null ? null : (string) $artifact->package_checksum);
    }
}
