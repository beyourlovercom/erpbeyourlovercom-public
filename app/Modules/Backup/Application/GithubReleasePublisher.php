<?php

namespace App\Modules\Backup\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Backup\Application\Contracts\BackupRepository;
use App\Modules\Backup\Application\Contracts\GithubReleaseGateway;
use App\Modules\Backup\Application\Exceptions\GithubReleasePublishException;
use Throwable;

final class GithubReleasePublisher
{
    public function __construct(private readonly BackupRepository $repository, private readonly GithubReleaseGateway $gateway, private readonly ?AuditRecorder $audit = null) {}

    public function publish(BackupRunData $run): BackupRunData
    {
        if ($run->state === BackupState::Verified && $this->isPublished($run)) return $run;
        if ($run->state !== BackupState::Ready) throw GithubReleasePublishException::failed();

        $artifact = $this->canonicalArtifact($run);
        $data = new GithubReleasePublishData((string) config('backup.github.repository'), 'byl-erp-backup-'.$run->runUuid, $run->runUuid, $run->backupType->value, basename($artifact->path), $artifact->path, $artifact->size, $artifact->checksum, (int) config('backup.format.version'));
        $publishing = $this->repository->updateRun($run->id, ['state' => BackupState::Publishing]);
        try {
            $published = $this->gateway->publish($data);
            $metadata = array_merge($publishing->metadata, ['github_publish_status' => 'published'], $published->metadata());
            $verified = $this->repository->updateRun($run->id, ['state' => BackupState::Verified, 'metadata' => $metadata]);
            $this->audit($verified, 'backup.github_published', ['github_publish_status' => 'published', 'repository' => $published->repository, 'release_id' => $published->releaseId, 'release_tag' => $published->tag, 'asset_id' => $published->assetId, 'asset_name' => $published->assetName, 'asset_size' => $published->assetSize, 'checksum' => $published->checksum]);
            return $verified;
        } catch (Throwable) {
            $ready = $this->repository->updateRun($run->id, ['state' => BackupState::Ready, 'metadata' => array_merge($publishing->metadata, ['github_publish_status' => 'failed', 'github_publish_error_code' => 'transport_failed'])]);
            $this->audit($ready, 'backup.github_publish_failed', ['github_publish_status' => 'failed', 'error_code' => 'transport_failed']);
            throw GithubReleasePublishException::failed();
        }
    }

    private function canonicalArtifact(BackupRunData $run): BackupArtifactData
    {
        $artifacts = $this->repository->artifactsForRun($run->id);
        if (count($artifacts) !== 1 || $artifacts[0]->kind !== 'encrypted') throw GithubReleasePublishException::failed();
        $a = $artifacts[0]; $workspace = (string) config('backup.storage.workspace', 'storage/app/backups'); $root = str_starts_with($workspace, DIRECTORY_SEPARATOR) ? $workspace : base_path($workspace); $root = realpath($root); $real = is_file($a->path) ? realpath($a->path) : false;
        if ($root === false || $real === false || ! str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) || ! str_ends_with($real, (string) config('backup.format.asset_suffix', '.tar.zst.enc')) || $a->encrypted !== true || $a->size <= 0 || filesize($real) !== $a->size || hash_file('sha256', $real) !== $a->checksum) throw GithubReleasePublishException::failed();
        $max = (int) config('backup.github.max_asset_bytes', 2147483647); if ($a->size > $max) throw GithubReleasePublishException::failed();
        return $a;
    }

    private function isPublished(BackupRunData $run): bool
    { $m = $run->metadata; return ($m['github_publish_status'] ?? null) === 'published' && isset($m['repository'], $m['release_id'], $m['release_tag'], $m['release_url'], $m['asset_id'], $m['asset_name'], $m['asset_url'], $m['asset_size'], $m['checksum'], $m['published_at']); }

    private function audit(BackupRunData $run, string $action, array $after): void
    { $this->audit?->record(AuditActor::system(AuditSource::InternalSystem), $action, 'backup_run', $run->id, null, $after); }
}
