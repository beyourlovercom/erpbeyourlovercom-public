<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\RestoreFailure;
use App\Modules\Backup\Infrastructure\HttpGithubRestoreSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpGithubRestoreSourceTest extends TestCase
{
    private string $root;
    private string $base = 'https://api.github.test';
    private string $content = 'encrypted fixture';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = '/private/tmp/byl-github-restore-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/restore/run', 0700, true);
        config(['backup.storage.workspace' => $this->root, 'backup.github.api_base' => $this->base, 'backup.github.repository' => 'owner/backups', 'backup.github.token' => 'test-token', 'backup.github.max_asset_bytes' => 100]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_private_release_asset_is_downloaded_with_exact_metadata_and_confined_destination(): void
    {
        $sha = hash('sha256', $this->content);
        $url = $this->base.'/repos/owner/backups/releases/assets/42';
        Http::fake([
            $this->base.'/repos/owner/backups' => Http::response(['private' => true]),
            $this->base.'/repos/owner/backups/releases/tags/v1' => Http::response(['assets' => [['name' => 'backup.tar.zst.enc', 'size' => strlen($this->content), 'digest' => 'sha256:'.$sha, 'url' => $url]]]),
            $url => Http::response($this->content),
        ]);
        $destination = $this->root.'/restore/run/source.tar.zst.enc';

        (new HttpGithubRestoreSource())->materialize('github://owner/backups/v1/backup.tar.zst.enc', $destination, $sha);

        self::assertSame($this->content, file_get_contents($destination));
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->url() === $url && $request->hasHeader('Accept', 'application/octet-stream'));
    }

    public function test_missing_repository_is_rejected(): void
    {
        Http::fake([$this->base.'/repos/owner/backups' => Http::response([], 404)]);
        $this->assertReason('github_repository_unavailable');
    }

    public function test_public_repository_is_rejected(): void
    {
        Http::fake([$this->base.'/repos/owner/backups' => Http::response(['private' => false])]);
        $this->assertReason('github_repository_not_private');
    }

    public function test_missing_release_is_rejected(): void
    {
        Http::fake([
            $this->base.'/repos/owner/backups' => Http::response(['private' => true]),
            $this->base.'/repos/owner/backups/releases/tags/v1' => Http::response([], 404),
        ]);
        $this->assertReason('github_release_unavailable');
    }

    public function test_missing_asset_is_rejected(): void
    {
        Http::fake([
            $this->base.'/repos/owner/backups' => Http::response(['private' => true]),
            $this->base.'/repos/owner/backups/releases/tags/v1' => Http::response(['assets' => []]),
        ]);
        $this->assertReason('github_asset_unavailable');
    }

    public function test_digest_mismatch_is_rejected(): void
    {
        $common = $this->asset();
        $this->fakeRelease($common + ['digest' => 'sha256:'.str_repeat('0', 64)]);
        $this->assertReason('github_asset_digest_mismatch');
    }

    public function test_off_host_asset_url_is_rejected_before_download(): void
    {
        $this->fakeRelease(array_replace($this->asset(), ['digest' => null, 'url' => 'https://evil.test/asset']));
        $this->assertReason('github_asset_metadata_invalid');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.test'));
    }

    public function test_oversized_asset_is_rejected(): void
    {
        $this->fakeRelease(array_replace($this->asset(), ['digest' => null, 'size' => 101]));
        $this->assertReason('github_asset_metadata_invalid');
    }

    public function test_download_checksum_mismatch_is_rejected(): void
    {
        config(['backup.github.max_asset_bytes' => 1000]);
        $asset = $this->asset() + ['digest' => null];
        Http::fake([
            $this->base.'/repos/owner/backups' => Http::response(['private' => true]),
            $this->base.'/repos/owner/backups/releases/tags/v1' => Http::response(['assets' => [$asset]]),
            $asset['url'] => Http::response('tampered'),
        ]);
        $this->assertReason('github_download_verification_failed');
    }

    public function test_invalid_source_and_missing_token_make_no_requests(): void
    {
        $this->assertReason('github_source_invalid', 'not-github');
        config(['backup.github.token' => null]);
        $this->assertReason('github_token_unavailable');
        Http::assertNothingSent();
    }

    private function asset(): array
    {
        return [
            'name' => 'backup.tar.zst.enc',
            'size' => strlen($this->content),
            'url' => $this->base.'/repos/owner/backups/releases/assets/42',
        ];
    }

    private function fakeRelease(array $asset): void
    {
        Http::fake([
            $this->base.'/repos/owner/backups' => Http::response(['private' => true]),
            $this->base.'/repos/owner/backups/releases/tags/v1' => Http::response(['assets' => [$asset]]),
            '*' => Http::response($this->content),
        ]);
    }

    private function assertReason(string $reason, string $source = 'github://owner/backups/v1/backup.tar.zst.enc'): void
    {
        try {
            (new HttpGithubRestoreSource())->materialize($source, $this->root.'/restore/run/source.tar.zst.enc', hash('sha256', $this->content));
            self::fail("Expected restore failure [{$reason}].");
        } catch (RestoreFailure $e) {
            self::assertSame($reason, $e->reasonCode);
        }
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path) || is_link($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) { if (in_array($entry, ['.', '..'], true)) continue; $child = $path.'/'.$entry; if (is_dir($child) && ! is_link($child)) $this->removeTree($child); else @unlink($child); }
        @rmdir($path);
    }
}
