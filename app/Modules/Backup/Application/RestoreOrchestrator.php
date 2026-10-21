<?php

namespace App\Modules\Backup\Application;

use App\Modules\Backup\Application\Contracts\DatabaseRestorer;
use App\Modules\Backup\Application\Contracts\PreRestoreSnapshotter;
use App\Modules\Backup\Application\Contracts\RestoreEncryptor;
use App\Modules\Backup\Application\Contracts\RestoreHealthChecker;
use App\Modules\Backup\Application\Contracts\PostRestoreSchemaUpdater;
use App\Modules\Backup\Application\Contracts\RestorePackageExtractor;
use App\Modules\Backup\Application\Contracts\RestoreRollbackExecutor;
use App\Modules\Backup\Application\Contracts\RestoreSource;
use App\Modules\Backup\Application\Contracts\StorageRestorer;
use Illuminate\Support\Str;
use Throwable;

final readonly class RestoreOrchestrator
{
    public function __construct(
        private RestoreSource $source,
        private RestoreEncryptor $encryptor,
        private RestorePackageExtractor $extractor,
        private RestorePackageValidator $validator,
        private PreRestoreSnapshotter $snapshotter,
        private DatabaseRestorer $database,
        private StorageRestorer $storage,
        private PostRestoreSchemaUpdater $migrations,
        private RestoreRollbackExecutor $rollback,
        private RestoreHealthChecker $health,
        private RestoreStateMachine $states = new RestoreStateMachine(),
    ) {}

    public function execute(RestoreRequest $request): RestoreRunData
    {
        $run = new RestoreRunData((string) Str::uuid(), RestoreState::Uploaded, $request->mode);
        $workspace = null;
        $snapshot = null;
        $destructive = false;

        try {
            if (! preg_match('/^[a-f0-9]{64}$/', $request->expectedEncryptedSha256)) {
                throw new RestoreFailure('encrypted_checksum_invalid');
            }

            $workspace = $this->createWorkspace($run->runUuid);
            $encrypted = $workspace.'/source.tar.zst.enc';
            $decrypted = $workspace.'/package.tar.zst';
            $package = $workspace.'/package';

            $run = $this->transition($run, RestoreState::Downloading);
            $this->source->materialize($request->source, $encrypted, $request->expectedEncryptedSha256);
            $encryptedSha = is_file($encrypted) && ! is_link($encrypted) ? hash_file('sha256', $encrypted) : false;
            if ($encryptedSha === false || ! hash_equals($request->expectedEncryptedSha256, $encryptedSha)) {
                throw new RestoreFailure('encrypted_checksum_mismatch');
            }

            $run = $this->transition($run, RestoreState::Verifying);
            $this->encryptor->inspect($encrypted);

            $run = $this->transition($run, RestoreState::Decrypting);
            $this->encryptor->decrypt($encrypted, $decrypted);
            $decryptedSize = @filesize($decrypted);
            $maxDecryptedBytes = (int) config('backup.restore.max_decrypted_package_bytes', config('backup.github.max_asset_bytes', 2147483647));
            if ($decryptedSize === false || $decryptedSize < 1 || $decryptedSize > $maxDecryptedBytes) {
                throw new RestoreFailure('decrypted_package_size_invalid');
            }

            $this->makeDirectory($package);
            $this->extractor->extract($decrypted, $package);
            $manifest = $this->validator->validate($package, $request->mode);

            $run = $this->transition($run, RestoreState::Compatible, [
                'manifest' => ManifestContract::public($manifest),
            ]);

            $run = $this->transition($run, RestoreState::Snapshotting);
            $snapshot = $this->snapshotter->createAndVerify();
            $snapshotChecksum = $snapshot->metadata['pre_restore_artifact_sha256'] ?? null;
            $snapshotPath = $snapshot->metadata['pre_restore_artifact_path'] ?? null;
            $snapshotSize = $snapshot->metadata['pre_restore_artifact_size'] ?? null;
            if ($snapshot->backupType !== BackupType::PreRestore
                || ! in_array($snapshot->state, [BackupState::Ready, BackupState::Verified], true)
                || ! is_string($snapshotPath) || $snapshotPath === ''
                || ! is_int($snapshotSize) || $snapshotSize < 1
                || ! is_string($snapshotChecksum) || ! preg_match('/^[a-f0-9]{64}$/', $snapshotChecksum)) {
                throw new RestoreFailure('pre_restore_snapshot_not_verified');
            }

            $run = $this->transition($run, RestoreState::Restoring, [
                'pre_restore_snapshot_run_uuid' => $snapshot->runUuid,
                'pre_restore_snapshot_sha256' => $snapshotChecksum,
                'pre_restore_snapshot_size' => $snapshotSize,
            ]);
            $destructive = true;
            $this->database->restore($package.'/database.sql.gz');
            if ($request->mode === RestoreMode::Full) {
                $this->storage->restore($package.'/storage.tar.zst');
            }

            $run = $this->transition($run, RestoreState::Migrating);
            $this->migrations->run();

            $run = $this->transition($run, RestoreState::HealthChecking);
            $this->health->check();

            return $this->transition($run, RestoreState::Completed);
        } catch (Throwable $e) {
            $code = $e instanceof RestoreFailure ? $e->reasonCode : 'restore_execution_failed';
            if ($destructive && $snapshot instanceof BackupRunData) {
                try {
                    $this->rollback->rollback($snapshot, $request->mode);
                    return $this->transition($run, RestoreState::RolledBack, [], $code);
                } catch (Throwable) {
                    return $this->transition($run, RestoreState::Failed, [], 'rollback_failed');
                }
            }

            return $this->transition($run, RestoreState::Failed, [], $code);
        } finally {
            if (is_string($workspace)) {
                $this->removeWorkspace($workspace);
            }
        }
    }

    public function transition(RestoreRunData $run, RestoreState $state, array $metadata = [], ?string $errorCode = null): RestoreRunData
    {
        $this->states->transition($run->state, $state);

        return new RestoreRunData(
            $run->runUuid,
            $state,
            $run->mode,
            array_merge($run->metadata, $metadata),
            $errorCode,
        );
    }

    private function createWorkspace(string $runUuid): string
    {
        $configured = (string) config('backup.storage.workspace', 'storage/app/backups');
        $root = str_starts_with($configured, DIRECTORY_SEPARATOR) ? $configured : base_path($configured);
        if (is_link($root)) {
            throw new RestoreFailure('workspace_unavailable');
        }
        $this->makeDirectory($root);
        $realRoot = realpath($root);
        if ($realRoot === false || ! is_dir($realRoot)) {
            throw new RestoreFailure('workspace_unavailable');
        }

        $restoreRoot = $realRoot.'/restore';
        if (is_link($restoreRoot)) {
            throw new RestoreFailure('workspace_unavailable');
        }
        $this->makeDirectory($restoreRoot);
        $realRestoreRoot = realpath($restoreRoot);
        if ($realRestoreRoot === false || ! $this->within($realRestoreRoot, $realRoot)) {
            throw new RestoreFailure('workspace_unavailable');
        }

        $workspace = $realRestoreRoot.'/'.$runUuid;
        if (file_exists($workspace) || is_link($workspace)) {
            throw new RestoreFailure('workspace_collision');
        }
        $this->makeDirectory($workspace);
        $realWorkspace = realpath($workspace);
        if ($realWorkspace === false || ! $this->within($realWorkspace, $realRestoreRoot)) {
            throw new RestoreFailure('workspace_unavailable');
        }

        return $realWorkspace;
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RestoreFailure('workspace_unavailable');
        }
    }

    private function removeWorkspace(string $path): void
    {
        $configured = (string) config('backup.storage.workspace', 'storage/app/backups');
        $root = str_starts_with($configured, DIRECTORY_SEPARATOR) ? $configured : base_path($configured);
        $restoreRoot = realpath(rtrim($root, DIRECTORY_SEPARATOR).'/restore');
        $real = realpath($path);
        if ($restoreRoot === false || $real === false || $real === $restoreRoot || ! $this->within($real, $restoreRoot)) {
            return;
        }

        foreach (scandir($real) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }
            $child = $real.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectoryTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($real);
    }

    private function removeDirectoryTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectoryTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }

    private function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
