<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Infrastructure\HttpGithubReleaseDeletionGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpGithubReleaseDeletionGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'backup.github.token' => 'test-token',
            'backup.github.repository' => 'beyourlovercom/erp-backups',
            'backup.github.api_base' => 'https://api.github.com',
        ]);
        Http::preventStrayRequests();
    }

    public function test_private_release_and_tag_are_deleted_and_release_absence_is_verified(): void
    {
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://api.github.com/repos/beyourlovercom/erp-backups') {
                return Http::response(['private' => true], 200);
            }
            if ($request->method() === 'DELETE' && str_contains($request->url(), '/releases/77')) {
                return Http::response([], 204);
            }
            if ($request->method() === 'DELETE' && str_contains($request->url(), '/git/refs/tags/')) {
                return Http::response([], 204);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/releases/77')) {
                return Http::response([], 404);
            }
            return Http::response([], 500);
        });

        (new HttpGithubReleaseDeletionGateway())->delete('beyourlovercom/erp-backups', '77', 'byl-erp-backup-run-77');

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/releases/77'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/git/refs/tags/byl-erp-backup-run-77'));
        foreach (Http::recorded() as [$request]) {
            $this->assertNotEmpty($request->header('Authorization'));
            $this->assertSame('application/vnd.github+json', $request->header('Accept')[0]);
        }
    }

    public function test_missing_token_wrong_repository_and_invalid_release_id_fail_before_http(): void
    {
        Http::fake();
        config(['backup.github.token' => null]);
        $this->assertFails(fn () => (new HttpGithubReleaseDeletionGateway())->delete('beyourlovercom/erp-backups', '77', 'byl-erp-backup-run-77'));
        config(['backup.github.token' => 'test-token']);
        $this->assertFails(fn () => (new HttpGithubReleaseDeletionGateway())->delete('other/repo', '77', 'byl-erp-backup-run-77'));
        $this->assertFails(fn () => (new HttpGithubReleaseDeletionGateway())->delete('beyourlovercom/erp-backups', '../77', 'byl-erp-backup-run-77'));
        Http::assertNothingSent();
    }

    public function test_public_repository_or_delete_failure_is_fail_closed(): void
    {
        Http::fake(['*' => Http::response(['private' => false], 200)]);
        $this->assertFails(fn () => (new HttpGithubReleaseDeletionGateway())->delete('beyourlovercom/erp-backups', '77', 'byl-erp-backup-run-77'));
        Http::assertSentCount(1);

        Http::fake(function (Request $request) {
            if ($request->url() === 'https://api.github.com/repos/beyourlovercom/erp-backups') return Http::response(['private' => true], 200);
            return Http::response([], 500);
        });
        $this->assertFails(fn () => (new HttpGithubReleaseDeletionGateway())->delete('beyourlovercom/erp-backups', '77', 'byl-erp-backup-run-77'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/git/refs/tags/'));
    }

    public function test_already_absent_release_and_tag_are_idempotent(): void
    {
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://api.github.com/repos/beyourlovercom/erp-backups') return Http::response(['private' => true], 200);
            return Http::response([], 404);
        });

        (new HttpGithubReleaseDeletionGateway())->delete('beyourlovercom/erp-backups', '77', 'byl-erp-backup-run-77');
        Http::assertSentCount(4);
    }

    private function assertFails(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected fail-closed GitHub deletion.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }
}
