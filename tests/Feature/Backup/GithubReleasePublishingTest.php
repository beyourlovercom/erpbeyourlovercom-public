<?php

namespace Tests\Feature\Backup;

use App\Modules\Backup\Application\BackupArtifactData;
use App\Modules\Backup\Application\BackupRunData;
use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\BackupType;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Backup\Application\Contracts\BackupRepository;
use App\Modules\Backup\Application\Contracts\GithubReleaseGateway;
use App\Modules\Backup\Application\GithubReleasePublishData;
use App\Modules\Backup\Application\GithubReleasePublicationData;
use App\Modules\Backup\Application\GithubReleasePublisher;
use App\Modules\Backup\Application\Exceptions\GithubReleasePublishException;
use App\Modules\Backup\Infrastructure\HttpGithubReleaseGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class GithubReleasePublishingTest extends TestCase
{
    private string $assetPath;

    protected function setUp(): void
    {
        parent::setUp();
        config(['backup.github.token' => str_repeat('t', 20), 'backup.github.repository' => 'beyourlovercom/erp-backups', 'backup.storage.workspace' => '/private/tmp/byl-erp-backup-tests']);
        $root = '/private/tmp/byl-erp-backup-tests';
        if (! is_dir($root)) mkdir($root, 0700, true);
        $this->assetPath = $root.'/byl-erp-backup-test.tar.zst.enc';
        file_put_contents($this->assetPath, 'encrypted-artifact');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (is_file($this->assetPath)) unlink($this->assetPath);
        Mockery::close();
        parent::tearDown();
    }

    public function test_private_repository_creates_release_and_uploads_only_raw_artifact(): void
    {
        $size = filesize($this->assetPath); $checksum = hash_file('sha256', $this->assetPath);
        Http::fake([
            'https://api.github.com/repos/beyourlovercom/erp-backups' => Http::response(['private' => true], 200),
            'https://api.github.com/repos/beyourlovercom/erp-backups/releases/tags/*' => Http::response([], 404),
            'https://api.github.com/repos/beyourlovercom/erp-backups/releases' => Http::response(['id' => 7, 'tag_name' => 'byl-erp-backup-run-1', 'html_url' => 'https://github.com/r/7', 'upload_url' => 'https://uploads.github.com/repos/beyourlovercom/erp-backups/releases/7/assets{?name,label}', 'assets' => []], 201),
            'https://uploads.github.com/repos/beyourlovercom/erp-backups/releases/7/assets*' => Http::response(['id' => 8, 'name' => basename($this->assetPath), 'size' => $size, 'browser_download_url' => 'https://github.com/a/8'], 201),
        ]);
        $result = (new HttpGithubReleaseGateway())->publish(new GithubReleasePublishData('beyourlovercom/erp-backups', 'byl-erp-backup-run-1', 'run-1', 'full', basename($this->assetPath), $this->assetPath, $size, $checksum, 1));
        $this->assertSame('8', $result->assetId);
        $requests = Http::recorded();
        $uploadRequest = $requests[3][0];
        $this->assertSame('https://uploads.github.com/repos/beyourlovercom/erp-backups/releases/7/assets?name='.rawurlencode(basename($this->assetPath)), $uploadRequest->url());
        $this->assertFalse(str_contains($uploadRequest->url(), '/assets/?name='));
        $this->assertSame('2026-03-10', $uploadRequest->header('X-GitHub-Api-Version')[0]);
        $this->assertSame('application/vnd.github+json', $uploadRequest->header('Accept')[0]);
        $this->assertNotSame('', trim((string) $uploadRequest->header('User-Agent')[0]));
        $this->assertNotSame('', trim((string) $uploadRequest->header('Authorization')[0]));
        foreach ($requests as [$request]) {
            $this->assertSame('2026-03-10', $request->header('X-GitHub-Api-Version')[0]);
            $this->assertSame('application/vnd.github+json', $request->header('Accept')[0]);
            $this->assertNotSame('', trim((string) $request->header('User-Agent')[0]));
            $this->assertNotSame('', trim((string) $request->header('Authorization')[0]));
        }
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/manifest') || str_contains($request->url(), '/database'));
    }

    public function test_missing_repository_fails_closed_before_release_requests(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $this->expectException(GithubReleasePublishException::class);
        (new HttpGithubReleaseGateway())->publish($this->publication());
        Http::assertSentCount(1);
    }

    public function test_private_false_repository_fails_closed_before_release_requests(): void
    {
        Http::fake(['*' => Http::response(['private' => false], 200)]);
        $this->expectException(GithubReleasePublishException::class);
        (new HttpGithubReleaseGateway())->publish($this->publication());
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/releases'));
    }

    public function test_missing_runtime_token_fails_before_any_http_request(): void
    {
        config(['backup.github.token' => null]); Http::fake();
        $this->expectException(GithubReleasePublishException::class);
        (new HttpGithubReleaseGateway())->publish($this->publication());
        Http::assertNothingSent();
    }

    public function test_existing_matching_asset_with_matching_digest_is_idempotent(): void
    {
        $size = filesize($this->assetPath); $common = ['id' => 7, 'html_url' => 'https://github.com/r/7', 'assets' => [['id' => 8, 'name' => basename($this->assetPath), 'size' => $size, 'digest' => 'sha256:'.hash_file('sha256', $this->assetPath), 'browser_download_url' => 'https://github.com/a/8']]];
        Http::fake(function (Request $r) use ($common) {
            return Http::response($r->url() === 'https://api.github.com/repos/beyourlovercom/erp-backups' ? ['private' => true] : $common, 200);
        });
        $result = (new HttpGithubReleaseGateway())->publish($this->publication());
        $this->assertSame('8', $result->assetId); Http::assertSentCount(2);

    }

    public function test_existing_matching_asset_with_digest_mismatch_fails(): void
    {
        $size = filesize($this->assetPath);
        Http::fake(function (Request $r) use ($size) {
            if (str_contains($r->url(), '/releases/tags/')) return Http::response(['id' => 7, 'assets' => [['id' => 8, 'name' => basename($this->assetPath), 'size' => $size, 'digest' => 'sha256:'.str_repeat('0', 64)]]], 200);
            return Http::response(['private' => true], 200);
        });
        $this->expectException(GithubReleasePublishException::class);
        (new HttpGithubReleaseGateway())->publish($this->publication());
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_uploaded_asset_with_digest_mismatch_fails(): void
    {
        $size = filesize($this->assetPath);
        Http::fake([
            '*repos/beyourlovercom/erp-backups' => Http::response(['private' => true], 200),
            '*releases/tags/*' => Http::response([], 404),
            '*repos/beyourlovercom/erp-backups/releases' => Http::response(['id' => 7, 'upload_url' => 'https://uploads.github.com/repos/beyourlovercom/erp-backups/releases/7/assets{?name}', 'assets' => []], 201),
            '*uploads.github.com/*' => Http::response(['id' => 8, 'name' => basename($this->assetPath), 'size' => $size, 'digest' => 'sha256:'.str_repeat('0', 64), 'browser_download_url' => 'https://github.com/a/8'], 201),
        ]);
        $this->expectException(GithubReleasePublishException::class);
        (new HttpGithubReleaseGateway())->publish($this->publication());
    }

    public function test_same_name_asset_with_different_size_is_a_conflict(): void
    {
        $size = filesize($this->assetPath);
        Http::fake(function (Request $r) use ($size) {
            if (str_contains($r->url(), '/releases/tags/')) return Http::response(['id' => 7, 'assets' => [['id' => 8, 'name' => basename($this->assetPath), 'size' => $size + 1]]], 200);
            return Http::response(['private' => true], 200);
        });
        $this->expectException(GithubReleasePublishException::class);
        (new HttpGithubReleaseGateway())->publish($this->publication());
    }

    public function test_publisher_validates_canonical_encrypted_artifact_and_sanitizes_failure(): void
    {
        $repo = Mockery::mock(BackupRepository::class); $run = new BackupRunData(1, 'run-1', BackupType::Full, BackupState::Ready);
        $repo->shouldReceive('artifactsForRun')->once()->andReturn([new BackupArtifactData(1, 1, $this->assetPath, 'encrypted', filesize($this->assetPath), hash_file('sha256', $this->assetPath), true, 'key-1', 'package')]);
        $repo->shouldReceive('updateRun')->once()->with(1, ['state' => BackupState::Publishing])->andReturn($run->withState(BackupState::Publishing));
        $repo->shouldReceive('updateRun')->once()->withArgs(fn (int $id, array $a): bool => $id === 1 && $a['state'] === BackupState::Ready && $a['metadata']['github_publish_error_code'] === 'transport_failed' && ! isset($a['metadata']['token']))->andReturn($run);
        $gateway = Mockery::mock(GithubReleaseGateway::class); $gateway->shouldReceive('publish')->once()->andThrow(new \RuntimeException('provider response is sensitive'));
        $audit = Mockery::mock(AuditRecorder::class); $audit->shouldReceive('record')->once()->withArgs(fn (...$args): bool => $args[1] === 'backup.github_publish_failed' && $args[5]['github_publish_status'] === 'failed' && ! isset($args[5]['token']));
        $publisher = new GithubReleasePublisher($repo, $gateway, $audit);
        $this->expectException(GithubReleasePublishException::class); $publisher->publish($run);
    }

    public function test_publisher_rejects_checksum_mismatch_before_gateway(): void
    {
        $repo = Mockery::mock(BackupRepository::class); $run = new BackupRunData(1, 'run-1', BackupType::Full, BackupState::Ready);
        $repo->shouldReceive('artifactsForRun')->once()->andReturn([new BackupArtifactData(1, 1, $this->assetPath, 'encrypted', filesize($this->assetPath), str_repeat('0', 64), true)]);
        $gateway = Mockery::mock(GithubReleaseGateway::class); $gateway->shouldReceive('publish')->never();
        $this->expectException(GithubReleasePublishException::class); (new GithubReleasePublisher($repo, $gateway))->publish($run);
    }

    public function test_publisher_rejects_non_encrypted_artifact_before_gateway(): void
    { $this->assertCanonicalArtifactRejected(new BackupArtifactData(1, 1, $this->assetPath, 'encrypted', filesize($this->assetPath), hash_file('sha256', $this->assetPath), false)); }

    public function test_publisher_rejects_suffix_mismatch_before_gateway(): void
    { $path = dirname($this->assetPath).'/wrong.bin'; file_put_contents($path, 'encrypted-artifact'); try { $this->assertCanonicalArtifactRejected(new BackupArtifactData(1, 1, $path, 'encrypted', filesize($path), hash_file('sha256', $path), true)); } finally { unlink($path); } }

    public function test_publisher_rejects_artifact_outside_workspace_before_gateway(): void
    { $path = '/private/tmp/byl-outside-backup.tar.zst.enc'; file_put_contents($path, 'encrypted-artifact'); try { $this->assertCanonicalArtifactRejected(new BackupArtifactData(1, 1, $path, 'encrypted', filesize($path), hash_file('sha256', $path), true)); } finally { unlink($path); } }

    public function test_publisher_rejects_recorded_size_mismatch_before_gateway(): void
    { $this->assertCanonicalArtifactRejected(new BackupArtifactData(1, 1, $this->assetPath, 'encrypted', filesize($this->assetPath) + 1, hash_file('sha256', $this->assetPath), true)); }

    public function test_default_max_asset_bytes_and_local_limit_are_fail_closed(): void
    {
        $this->assertSame(2147483647, config('backup.github.max_asset_bytes'));
        config(['backup.github.max_asset_bytes' => 1]);
        $this->assertCanonicalArtifactRejected(new BackupArtifactData(1, 1, $this->assetPath, 'encrypted', filesize($this->assetPath), hash_file('sha256', $this->assetPath), true));
    }

    public function test_publisher_persists_non_secret_metadata_and_verified_state(): void
    {
        $run = new BackupRunData(1, 'run-1', BackupType::Full, BackupState::Ready); $repo = Mockery::mock(BackupRepository::class);
        $artifact = new BackupArtifactData(1, 1, $this->assetPath, 'encrypted', filesize($this->assetPath), hash_file('sha256', $this->assetPath), true, 'key-1', 'package');
        $repo->shouldReceive('artifactsForRun')->andReturn([$artifact]); $repo->shouldReceive('updateRun')->once()->andReturn($run->withState(BackupState::Publishing));
        $repo->shouldReceive('updateRun')->once()->withArgs(fn (int $id, array $a): bool => $a['state'] === BackupState::Verified && $a['metadata']['github_publish_status'] === 'published' && $a['metadata']['asset_name'] === basename($this->assetPath) && ! isset($a['metadata']['key_id'], $a['metadata']['token']))->andReturn($run->withState(BackupState::Verified));
        $gateway = Mockery::mock(GithubReleaseGateway::class); $gateway->shouldReceive('publish')->andReturn(new GithubReleasePublicationData('repo', '7', 'tag', 'url', '8', basename($this->assetPath), 'asset-url', filesize($this->assetPath), hash_file('sha256', $this->assetPath), now()->toIso8601String()));
        $this->assertSame(BackupState::Verified, (new GithubReleasePublisher($repo, $gateway))->publish($run)->state);
    }

    private function publication(): GithubReleasePublishData
    { return new GithubReleasePublishData('beyourlovercom/erp-backups', 'byl-erp-backup-run-1', 'run-1', 'full', basename($this->assetPath), $this->assetPath, filesize($this->assetPath), hash_file('sha256', $this->assetPath), 1); }

    private function assertCanonicalArtifactRejected(BackupArtifactData $artifact): void
    {
        $repo = Mockery::mock(BackupRepository::class);
        $repo->shouldReceive('artifactsForRun')->once()->andReturn([$artifact]);
        $gateway = Mockery::mock(GithubReleaseGateway::class);
        $gateway->shouldReceive('publish')->never();
        $this->expectException(GithubReleasePublishException::class);
        (new GithubReleasePublisher($repo, $gateway))->publish(new BackupRunData(1, 'run-1', BackupType::Full, BackupState::Ready));
    }
}
