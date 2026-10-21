<?php

namespace Tests\Feature\Marketing;

use App\Modules\Marketing\Infrastructure\AndyCore\HttpWordPressSubscriberGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpWordPressSubscriberGatewayTest extends TestCase
{
    private const BaseUrl = 'https://wp.test';
    private const ConnectionKey = 'beyourlover.com';
    private const KeyId = 'key-001';
    private const SharedSecret = 'test-shared-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.env' => 'testing',
            'services.andy_core.verify_tls' => true,
            'services.andy_core.url' => self::BaseUrl,
            'services.andy_core.connection_key' => self::ConnectionKey,
            'services.andy_core.key_id' => self::KeyId,
            'services.andy_core.shared_secret' => self::SharedSecret,
        ]);
    }

    public function test_tls_verification_is_enabled_by_default(): void
    {
        $this->assertTrue((bool) config('services.andy_core.verify_tls'));
        $this->fakeSuccessfulSnapshot();

        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers();
        $this->assertTrue($this->tlsVerificationDecision());
    }

    public function test_local_explicit_false_can_disable_tls_verification(): void
    {
        config(['app.env' => 'local', 'services.andy_core.verify_tls' => false]);
        $this->fakeSuccessfulSnapshot();

        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers();
        $this->assertFalse($this->tlsVerificationDecision());
    }

    public function test_production_cannot_disable_tls_verification(): void
    {
        config(['app.env' => 'production', 'services.andy_core.verify_tls' => false]);
        $this->fakeSuccessfulSnapshot();

        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers();
        $this->assertTrue($this->tlsVerificationDecision());
    }

    public function test_subscriber_snapshot_uses_exact_signed_path_and_query_contract(): void
    {
        Http::fake(['*' => Http::response($this->envelope([
            'items' => [], 'next_cursor' => null, 'source_site' => self::ConnectionKey,
        ]), 200)]);

        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers('cursor-abc', '2026-08-29T00:00:00Z', 50);

        Http::assertSent(function (Request $request): bool {
            $parts = parse_url($request->url());
            parse_str($parts['query'] ?? '', $query);
            $pathWithQuery = ($parts['path'] ?? '').(($parts['query'] ?? '') !== '' ? '?'.$parts['query'] : '');
            $timestamp = $request->header('X-YBY-Timestamp')[0] ?? '';
            $nonce = $request->header('X-YBY-Nonce')[0] ?? '';
            $connectionKey = $request->header('X-YBY-Connection-Key')[0] ?? '';
            $canonical = implode("\n", ['GET', $pathWithQuery, $timestamp, $nonce, $connectionKey, '', hash('sha256', '')]);
            $expected = hash_hmac('sha256', $canonical, self::SharedSecret);

            return ($parts['path'] ?? '') === '/wp-json/andy-core/v1/erp/snapshot/subscribers'
                && $query === ['cursor' => 'cursor-abc', 'updated_after' => '2026-08-29T00:00:00Z', 'limit' => '50']
                && $request->header('X-YBY-Key-Id')[0] === self::KeyId
                && $request->header('X-YBY-Signature-Version')[0] === 'v1'
                && ! $request->hasHeader('X-YBY-Idempotency-Key')
                && hash_equals($expected, $request->header('X-YBY-Signature')[0] ?? '');
        });
    }

    public function test_subscriber_snapshot_uses_origin_compatible_connector_user_agent(): void
    {
        $this->fakeSuccessfulSnapshot();

        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers();

        Http::assertSent(function (Request $request): bool {
            $userAgent = $request->header('User-Agent')[0] ?? '';

            return str_starts_with($userAgent, 'Mozilla/5.0')
                && str_contains($userAgent, 'BYL-ERP-Marketing-WordPress-Connector/1.0');
        });
    }

    public function test_snapshot_rejects_connection_key_mismatch(): void
    {
        Http::fake(['*' => Http::response([
            'ok' => true, 'contract_version' => '1', 'request_id' => 'req-1',
            'connection_key' => 'wrong.example', 'data' => ['items' => []],
        ], 200)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid response envelope');
        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers();
    }


    public function test_explicit_native_source_is_included_in_signed_path_before_hmac(): void
    {
        $this->fakeSuccessfulSnapshot();
        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers('native-token', '2026-10-10T01:00:00Z', 25, 'andy_core_newsletter');
        Http::assertSent(function (Request $request): bool {
            $parts = parse_url($request->url());
            $signedPath = ($parts['path'] ?? '').'?'.($parts['query'] ?? '');
            parse_str($parts['query'] ?? '', $query);
            $canonical = implode("\n", ['GET', $signedPath,
                $request->header('X-YBY-Timestamp')[0] ?? '',
                $request->header('X-YBY-Nonce')[0] ?? '',
                self::ConnectionKey, '', hash('sha256', '')]);
            return $query === ['source' => 'andy_core_newsletter', 'cursor' => 'native-token',
                    'updated_after' => '2026-10-10T01:00:00Z', 'limit' => '25']
                && hash_equals(hash_hmac('sha256', $canonical, self::SharedSecret),
                    $request->header('X-YBY-Signature')[0] ?? '');
        });
    }

    public function test_unknown_source_is_rejected_before_network_call(): void
    {
        Http::fake();
        try {
            app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers(null, null, 100, 'wordpress_users');
            $this->fail('Unsupported source must not be accepted');
        } catch (\InvalidArgumentException $expected) {
            Http::assertNothingSent();
        }
    }

    public function test_native_malformed_source_site_and_oversized_page_are_rejected(): void
    {
        Http::fake(['*' => Http::response($this->envelope([
            'items' => [], 'source_site' => 'https://unexpected.example', 'next_cursor' => null,
        ]), 200)]);
        $this->expectException(\RuntimeException::class);
        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers(null, null, 100, 'andy_core_newsletter');
    }

    public function test_native_page_limit_is_clamped_to_one_hundred_before_signing(): void
    {
        $this->fakeSuccessfulSnapshot();
        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers(null, null, 1000, 'andy_core_newsletter');
        Http::assertSent(function (Request $request): bool {
            $parts = parse_url($request->url());
            parse_str($parts['query'] ?? '', $query);
            return $query === ['source' => 'andy_core_newsletter', 'limit' => '100'];
        });
    }

    public function test_native_snapshot_http_503_is_a_provider_failure_not_an_empty_success(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');
        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers(null, null, 100, 'andy_core_newsletter');
    }

    private function envelope(array $data): array
    {
        return [
            'ok' => true,
            'contract_version' => '1',
            'request_id' => 'req-001',
            'connection_key' => self::ConnectionKey,
            'data' => $data,
        ];
    }

    private function fakeSuccessfulSnapshot(): void
    {
        Http::fake(['*' => Http::response($this->envelope([
            'items' => [], 'next_cursor' => null, 'source_site' => self::ConnectionKey,
        ]), 200)]);
    }

    private function tlsVerificationDecision(): bool
    {
        $method = new \ReflectionMethod(HttpWordPressSubscriberGateway::class, 'shouldVerifyTls');
        $method->setAccessible(true);

        return $method->invoke(app(HttpWordPressSubscriberGateway::class));
    }
}
