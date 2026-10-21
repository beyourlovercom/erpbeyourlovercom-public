<?php

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Application\BackupRunData;
use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\BackupType;
use App\Modules\Backup\Application\Contracts\DatabaseRestorer;
use App\Modules\Backup\Application\Contracts\RestoreEncryptor;
use App\Modules\Backup\Application\Contracts\RestoreHealthChecker;
use App\Modules\Backup\Application\Contracts\PostRestoreSchemaUpdater;
use App\Modules\Backup\Application\Contracts\RestorePackageExtractor;
use App\Modules\Backup\Application\Contracts\RestoreRollbackExecutor;
use App\Modules\Backup\Application\Contracts\StorageRestorer;
use App\Modules\Backup\Application\RestoreMode;
use App\Modules\Backup\Application\RestorePackageValidator;
use RuntimeException;

final readonly class SnapshotRestoreRollbackExecutor implements RestoreRollbackExecutor
{
    public function __construct(
        private RestoreEncryptor $encryptor,
        private RestorePackageExtractor $extractor,
        private RestorePackageValidator $validator,
        private DatabaseRestorer $database,
        private StorageRestorer $storage,
        private PostRestoreSchemaUpdater $migrations,
        private RestoreHealthChecker $health,
    ) {}

    public function rollback(BackupRunData $snapshot, RestoreMode $mode): void
    {
        if ($snapshot->backupType !== BackupType::PreRestore || ! in_array($snapshot->state, [BackupState::Ready, BackupState::Verified], true)) {
            throw new RuntimeException('Rollback snapshot is not usable.');
        }
        $path = $snapshot->metadata['pre_restore_artifact_path'] ?? null;
        $size = $snapshot->metadata['pre_restore_artifact_size'] ?? null;
        $checksum = $snapshot->metadata['pre_restore_artifact_sha256'] ?? null;
        if (! is_string($path) || $path === '' || ! is_int($size) || $size < 1
            || ! is_string($checksum) || ! preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new RuntimeException('Rollback snapshot artifact evidence is invalid.');
        }

        $encrypted = realpath($path);
        $configuredWorkspace = (string) config('backup.storage.workspace', 'storage/app/backups');
        $workspacePath = str_starts_with($configuredWorkspace, DIRECTORY_SEPARATOR) ? $configuredWorkspace : base_path($configuredWorkspace);
        $workspace = realpath($workspacePath);
        if ($encrypted === false || $workspace === false || ! is_file($encrypted) || is_link($path)
            || ! str_starts_with($encrypted, rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            || ! str_ends_with($encrypted, (string) config('backup.format.asset_suffix', '.tar.zst.enc'))
            || filesize($encrypted) !== $size) throw new RuntimeException('Rollback snapshot artifact is unavailable.');
        $sha = hash_file('sha256', $encrypted);
        if ($sha === false || ! hash_equals($checksum, $sha)) throw new RuntimeException('Rollback snapshot checksum verification failed.');

        $workspace = $this->createWorkspace($snapshot->runUuid);
        try {
            $decrypted = $workspace.'/package.tar.zst';
            $package = $workspace.'/package';
            if (! mkdir($package, 0700, true) && ! is_dir($package)) throw new RuntimeException('Unable to create rollback package workspace.');
            $this->encryptor->inspect($encrypted);
            $this->encryptor->decrypt($encrypted, $decrypted);
            $this->extractor->extract($decrypted, $package);
            $this->validator->validateRollbackSnapshot($package, $mode);
            $this->database->restore($package.'/database.sql.gz');
            if ($mode === RestoreMode::Full) $this->storage->restore($package.'/storage.tar.zst');
            $this->migrations->run();
            $this->health->check();
        } finally {
            $this->removeTree($workspace);
        }
    }

    private function createWorkspace(string $uuid): string
    {
        $configured = (string) config('backup.storage.workspace', 'storage/app/backups');
        $root = str_starts_with($configured, DIRECTORY_SEPARATOR) ? $configured : base_path($configured);
        if (is_link($root)) throw new RuntimeException('Rollback workspace is unsafe.');
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) throw new RuntimeException('Rollback workspace is unavailable.');
        $realRoot = realpath($root);
        if ($realRoot === false) throw new RuntimeException('Rollback workspace is unavailable.');
        $rollbackRoot = $realRoot.'/rollback';
        if (is_link($rollbackRoot)) throw new RuntimeException('Rollback workspace is unsafe.');
        if (! is_dir($rollbackRoot) && ! mkdir($rollbackRoot, 0700, true) && ! is_dir($rollbackRoot)) throw new RuntimeException('Rollback workspace is unavailable.');
        $path = $rollbackRoot.'/'.$uuid.'-'.bin2hex(random_bytes(6));
        if (! mkdir($path, 0700, true)) throw new RuntimeException('Rollback workspace is unavailable.');
        return $path;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (! is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) continue;
            $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
        }
        @rmdir($path);
    }
}
