<?php

namespace App\Modules\Backup\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditService;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Backup\Application\Contracts\BackupRepository;

final class BackupArtifactCleanupService
{
    public function __construct(private readonly BackupRepository $repository, private readonly ?AuditService $audit = null) {}

    public function delete(BackupRunData $run): void
    {
        foreach ($this->repository->artifactsForRun($run->id) as $artifact) { $path = $this->confinedPath($artifact->path); if (is_file($path)) unlink($path); $this->repository->deleteArtifact($artifact->id); }
        $run = $this->repository->markDeleted($run->id);
        $this->audit?->record(AuditActor::system(AuditSource::InternalSystem), 'backup.deleted', 'backup_run', $run->id, null, ['backup_type' => $run->backupType->value, 'state' => $run->state->value]);
    }

    public function cleanupWorkspace(string $workspace, ?string $preserve = null): void
    {
        $root = $this->workspaceRoot(); $candidate = realpath($workspace);
        if ($candidate === false || ! $this->within($candidate, $root)) return;
        $preserved = $preserve === null ? null : $this->confinedPath($preserve);
        foreach (glob($candidate.'/*', GLOB_NOSORT) ?: [] as $path) { if ($preserved !== null && $path === $preserved) continue; if (is_file($path) || is_link($path)) @unlink($path); }
        @rmdir($candidate);
    }

    public function deleteUntrackedFinalAsset(string $asset): void
    {
        $root = $this->workspaceRoot();
        $real = realpath($asset);
        if ($real === false || ! $this->within($real, $root) || is_dir($real)) {
            return;
        }
        @unlink($real);
    }

    private function confinedPath(string $path): string { $root = $this->workspaceRoot(); $real = realpath($path); if ($real === false || ! $this->within($real, $root) || is_dir($real)) throw new \RuntimeException('Backup artifact path is outside the configured workspace.'); return $real; }
    private function workspaceRoot(): string { $configured = (string) config('backup.storage.workspace', 'storage/app/backups'); $root = str_starts_with($configured, DIRECTORY_SEPARATOR) ? $configured : base_path($configured); $real = realpath($root); if ($real === false) throw new \RuntimeException('Configured backup workspace is unavailable.'); return rtrim($real, DIRECTORY_SEPARATOR); }
    private function within(string $path, string $root): bool { return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR); }
}
