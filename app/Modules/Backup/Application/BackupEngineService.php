<?php

namespace App\Modules\Backup\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\AuditService;
use App\Modules\Backup\Application\Contracts\BackupRepository;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class BackupEngineService
{
    public function __construct(private readonly DatabaseDumpAdapter $dumper, private readonly StorageArchiveAdapter $archiver, private readonly StreamingAesGcmEncryptor $encryptor, private readonly ManifestBuilder $manifests, private readonly ChecksumService $checksums, private readonly PackageBuilder $packages, private readonly BackupArtifactCleanupService $cleanup, private readonly BackupRepository $repository, private readonly ?AuditService $audit = null) {}

    public function create(BackupType $type, ?int $requestedBy = null): BackupRunData
    {
        $run = $this->repository->createRun((string) Str::uuid(), $type, $requestedBy);
        $this->audit($run, 'backup.created');
        return $run;
    }

    public function execute(BackupRunData $run): void
    {
        $root = $this->configuredWorkspace(); $workspace = $root.DIRECTORY_SEPARATOR.$run->runUuid; if (is_link($workspace) || (is_dir($workspace) && (($realWorkspace = realpath($workspace)) === false || ! $this->within($realWorkspace, $root)))) throw new \RuntimeException('Backup workspace is outside the configured root.'); if (! is_dir($workspace) && ! mkdir($workspace, 0700, true) && ! is_dir($workspace)) throw new \RuntimeException('Unable to create backup workspace.'); $asset = null;
        $assetTracked = false;
        try {
            $run = $this->transition($run, BackupState::Dumping); $db = $workspace.'/database.sql.gz'; $this->dumper->dump($db);
            $storage = $workspace.'/storage.tar.zst'; if ($run->backupType !== BackupType::Database) $this->archiver->archive($storage); else file_put_contents($storage, '');
            $run = $this->transition($run, BackupState::Packaging); $manifest = $this->manifests->build($run->backupType, now()->toIso8601String(), filesize($db) ?: 0, filesize($storage) ?: 0); if (file_put_contents($workspace.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n") === false) throw new \RuntimeException('Unable to write backup manifest.');
            $run = $this->transition($run, BackupState::Checksumming); $this->checksums->write($workspace.'/checksums.sha256', ['database.sql.gz' => $db, 'storage.tar.zst' => $storage, 'manifest.json' => $workspace.'/manifest.json']);
            $package = $workspace.'/'.config('backup.format.name').'.tar.zst'; $this->packages->build($workspace, $package); $run = $this->transition($run, BackupState::Encrypting);
            $asset = $this->uniqueAssetPath($root, $run->backupType);
            $this->encryptor->encrypt($package, $asset);
            $encryptedChecksum = hash_file('sha256', $asset);
            $packageChecksum = hash_file('sha256', $package);
            if ($encryptedChecksum === false || $packageChecksum === false) throw new \RuntimeException('Unable to checksum backup artifact.');
            $this->repository->createArtifact($run->id, ['kind' => 'encrypted', 'path' => $asset, 'size' => filesize($asset) ?: 0, 'checksum' => $encryptedChecksum, 'package_checksum' => $packageChecksum, 'encrypted' => true, 'key_id' => config('backup.encryption.key_id')]);
            $assetTracked = true;
            $run = $this->repository->updateRun($run->id, ['state' => BackupState::Ready, 'completed_at' => now()]);
            $this->audit($run, 'backup.ready');
        } catch (Throwable $e) {
            if ($asset !== null && ! $assetTracked) {
                try { $this->cleanup->deleteUntrackedFinalAsset($asset); } catch (Throwable) { /* preserve the engine failure */ }
            }
            $this->fail($run, $e);
            throw $e;
        } finally {
            try { $this->cleanup->cleanupWorkspace($workspace); } catch (Throwable) { /* cleanup must not mask execution */ }
        }
    }

    public function transition(BackupRunData $run, BackupState $state): BackupRunData
    {
        if ($run->state === $state) return $run;
        if (in_array($run->state, [BackupState::Deleted, BackupState::Failed, BackupState::Verified], true)) throw new LogicException('Backup is in a terminal state.');
        if (! self::canTransition($run->state, $state)) throw new LogicException('Invalid backup state transition.');
        $attributes = ['state' => $state];
        if ($run->startedAt === null) $attributes['started_at'] = now();
        return $this->repository->updateRun($run->id, $attributes);
    }

    public static function canTransition(BackupState $from, BackupState $to): bool
    {
        $allowed = [
            BackupState::Queued->value => [BackupState::Dumping], BackupState::Dumping->value => [BackupState::Packaging],
            BackupState::Packaging->value => [BackupState::Checksumming], BackupState::Checksumming->value => [BackupState::Encrypting],
            BackupState::Encrypting->value => [BackupState::Ready], BackupState::Ready->value => [BackupState::Publishing, BackupState::Verified],
            BackupState::Publishing->value => [BackupState::Ready, BackupState::Verified],
        ];
        return in_array($to, $allowed[$from->value] ?? [], true);
    }

    private function fail(BackupRunData $run, Throwable $e): void { $run = $this->repository->updateRun($run->id, ['state' => BackupState::Failed, 'error_code' => 'backup_failed', 'error_message' => 'Backup execution failed.', 'completed_at' => now()]); $this->audit($run, 'backup.failed'); }
    private function configuredWorkspace(): string { $configured = (string) config('backup.storage.workspace', 'storage/app/backups'); $root = str_starts_with($configured, DIRECTORY_SEPARATOR) ? $configured : base_path($configured); $root = realpath($root) ?: $root; return rtrim($root, DIRECTORY_SEPARATOR); }
    private function within(string $path, string $root): bool { return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR); }
    private function uniqueAssetPath(string $root, BackupType $type): string { $stamp = now()->format('Ymd-His'); $base = $root.'/byl-erp-'.$type->value.'-'.$stamp; $path = $base.'.tar.zst.enc'; for ($n = 2; file_exists($path); $n++) $path = $base.'-'.$n.'.tar.zst.enc'; return $path; }
    private function audit(BackupRunData $run, string $action): void { $this->audit?->record(AuditActor::system(AuditSource::InternalSystem), $action, 'backup_run', $run->id, null, ['backup_type' => $run->backupType->value, 'state' => $run->state->value]); }
}
