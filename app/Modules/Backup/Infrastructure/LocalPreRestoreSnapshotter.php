<?php

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Application\BackupEngineService;
use App\Modules\Backup\Application\BackupRunData;
use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\BackupType;
use App\Modules\Backup\Application\Contracts\BackupRepository;
use App\Modules\Backup\Application\Contracts\PreRestoreSnapshotter;
use RuntimeException;

final readonly class LocalPreRestoreSnapshotter implements PreRestoreSnapshotter
{
    public function __construct(private BackupEngineService $engine, private BackupRepository $repository) {}

    public function createAndVerify(): BackupRunData
    {
        $run = $this->engine->create(BackupType::PreRestore);
        $this->engine->execute($run);
        $run = $this->repository->findRun($run->id);
        if ($run === null || $run->backupType !== BackupType::PreRestore || $run->state !== BackupState::Ready) {
            throw new RuntimeException('Pre-restore snapshot did not reach Ready state.');
        }

        $artifacts = $this->repository->artifactsForRun($run->id);
        if (count($artifacts) !== 1 || $artifacts[0]->kind !== 'encrypted' || $artifacts[0]->encrypted !== true) {
            throw new RuntimeException('Pre-restore snapshot artifact is invalid.');
        }
        $artifact = $artifacts[0];
        $real = realpath($artifact->path);
        $configuredWorkspace = (string) config('backup.storage.workspace', 'storage/app/backups');
        $workspacePath = str_starts_with($configuredWorkspace, DIRECTORY_SEPARATOR) ? $configuredWorkspace : base_path($configuredWorkspace);
        $workspace = realpath($workspacePath);
        if ($real === false || $workspace === false || ! is_file($real) || is_link($artifact->path)
            || ! str_starts_with($real, rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            || ! str_ends_with($real, (string) config('backup.format.asset_suffix', '.tar.zst.enc'))
            || filesize($real) !== $artifact->size) {
            throw new RuntimeException('Pre-restore snapshot artifact is unavailable.');
        }
        $checksum = hash_file('sha256', $real);
        if ($checksum === false || ! hash_equals($artifact->checksum, $checksum)) {
            throw new RuntimeException('Pre-restore snapshot checksum verification failed.');
        }

        return $this->repository->updateRun($run->id, [
            'metadata' => array_merge($run->metadata, [
                'pre_restore_artifact_path' => $real,
                'pre_restore_artifact_size' => $artifact->size,
                'pre_restore_artifact_sha256' => $artifact->checksum,
                'pre_restore_artifact_key_id' => $artifact->keyId,
            ]),
        ]);
    }
}
