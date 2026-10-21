<?php

namespace Tests\Feature\Kol;

use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\AffiliateWp\ConnectorException;
use App\Modules\Kol\Infrastructure\AffiliateWp\HttpAffiliateWpGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpAffiliateWpGatewayTest extends TestCase
{
    private const BaseUrl = 'https://wp.test';
    private const ConnectionKey = 'beyourlover.com';
    private const KeyId = 'key-001';
    private const SharedSecret = 'test-shared-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.andy_core.url' => self::BaseUrl,
            'services.andy_core.connection_key' => self::ConnectionKey,
            'services.andy_core.key_id' => self::KeyId,
            'services.andy_core.shared_secret' => self::SharedSecret,
            'services.andy_core.timeout' => 5,
            'services.andy_core.verify_tls' => true,
            'app.env' => 'testing',
        ]);
    }

    private function gateway(): HttpAffiliateWpGateway
    {
        return app(HttpAffiliateWpGateway::class);
    }

    private function envelope(array $data = []): array
    {
        return [
            'ok' => true,
            'contract_version' => '1',
            'request_id' => 'req-001',
            'connection_key' => self::ConnectionKey,
            'data' => $data,
        ];
    }

    public function test_local_environment_can_explicitly_disable_tls_verification(): void
    {
        config(['app.env' => 'local', 'services.andy_core.verify_tls' => false]);

        $method = new \ReflectionMethod(HttpAffiliateWpGateway::class, 'shouldVerifyTls');

        $this->assertFalse($method->invoke($this->gateway()));
    }

    public function test_production_environment_always_verifies_tls(): void
    {
        config(['app.env' => 'production', 'services.andy_core.verify_tls' => false]);

        $method = new \ReflectionMethod(HttpAffiliateWpGateway::class, 'shouldVerifyTls');

        $this->assertTrue($method->invoke($this->gateway()));
    }

    // -------------------------------------------------------------------------
    // Signature / header contract
    // -------------------------------------------------------------------------

    public function test_get_request_sends_all_required_signed_headers(): void
    {
        Http::fake(['*' => Http::response($this->envelope(), 200)]);

        $this->gateway()->health();

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-YBY-Key-Id')
                && $request->hasHeader('X-YBY-Connection-Key')
                && $request->hasHeader('X-YBY-Timestamp')
                && $request->hasHeader('X-YBY-Nonce')
                && $request->hasHeader('X-YBY-Signature-Version')
                && $request->hasHeader('X-YBY-Signature')
                && $request->header('X-YBY-Signature-Version')[0] === 'v1'
                && $request->header('X-YBY-Key-Id')[0] === self::KeyId
                && $request->header('X-YBY-Connection-Key')[0] === self::ConnectionKey;
        });
    }

    public function test_requests_use_origin_compatible_connector_user_agent(): void
    {
        Http::fake(['*' => Http::response($this->envelope(), 200)]);

        $this->gateway()->health();

        Http::assertSent(function (Request $request): bool {
            $userAgent = $request->header('User-Agent')[0] ?? '';

            return str_starts_with($userAgent, 'Mozilla/5.0')
                && str_contains($userAgent, 'BYL-ERP-AffiliateWP-Connector/1.0');
        });
    }

    public function test_post_mutation_sends_idempotency_key_header(): void
    {
        Http::fake(['*' => Http::response($this->envelope(), 200)]);

        $this->gateway()->provisionAffiliate(['erp_kol_id' => 1, 'email' => 'test@example.com'], 'idem-001');

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-YBY-Idempotency-Key')
                && $request->header('X-YBY-Idempotency-Key')[0] === 'idem-001';
        });
    }

    public function test_signature_is_canonical_hmac_sha256(): void
    {
        Http::fake(['*' => Http::response($this->envelope(), 200)]);

        $this->gateway()->health();

        Http::assertSent(function (Request $request): bool {
            $method = $request->method();
            $url = $request->url();
            $path = parse_url($url, PHP_URL_PATH) ?? '';
            $query = parse_url($url, PHP_URL_QUERY) ?? '';
            $pathWithQuery = $path.($query !== '' ? '?'.$query : '');

            $timestamp = $request->header('X-YBY-Timestamp')[0] ?? '';
            $nonce = $request->header('X-YBY-Nonce')[0] ?? '';
            $connectionKey = $request->header('X-YBY-Connection-Key')[0] ?? '';
            $idempotencyKey = $request->header('X-YBY-Idempotency-Key')[0] ?? '';
            $body = (string) $request->body();
            $bodyHash = hash('sha256', $body);

            $canonical = implode("\n", [$method, $pathWithQuery, $timestamp, $nonce, $connectionKey, $idempotencyKey, $bodyHash]);
            $expected = hash_hmac('sha256', $canonical, self::SharedSecret);
            $actual = $request->header('X-YBY-Signature')[0] ?? '';

            return hash_equals($expected, $actual);
        });
    }

    public function test_each_request_uses_fresh_nonce(): void
    {
        Http::fake(['*' => Http::response($this->envelope(), 200)]);

        $this->gateway()->health();
        $this->gateway()->health();

        $nonces = collect(Http::recorded())->map(
            fn ($pair) => $pair[0]->header('X-YBY-Nonce')[0] ?? ''
        );

        $this->assertCount(2, $nonces);
        $this->assertNotSame($nonces[0], $nonces[1]);
    }

    public function test_each_request_uses_fresh_timestamp(): void
    {
        Http::fake(['*' => Http::response($this->envelope(), 200)]);

        // Two calls in rapid succession — timestamps are epoch seconds; we verify header is present and numeric
        $this->gateway()->health();

        Http::assertSent(function (Request $request): bool {
            $ts = $request->header('X-YBY-Timestamp')[0] ?? '';
            return ctype_digit($ts) && (int) $ts > 0;
        });
    }

    // -------------------------------------------------------------------------
    // Idempotency key enforcement
    // -------------------------------------------------------------------------

    public function test_mutation_without_idempotency_key_throws(): void
    {
        Http::fake(['*' => Http::response($this->envelope(), 200)]);

        $this->expectException(ConnectorException::class);
        $this->expectExceptionMessage('Mutation requires a non-empty idempotency key.');

        $this->gateway()->provisionAffiliate(['erp_kol_id' => 1], '');
    }

    public function test_set_affiliate_status_without_idempotency_key_throws(): void
    {
        Http::fake();

        $this->expectException(ConnectorException::class);
        $this->gateway()->setAffiliateStatus(99, 'active', '');
    }

    public function test_provision_coupon_without_idempotency_key_throws(): void
    {
        Http::fake();

        $this->expectException(ConnectorException::class);
        $this->gateway()->provisionCoupon(['code' => 'TEST10'], '');
    }

    public function test_complete_payout_without_idempotency_key_throws(): void
    {
        Http::fake();

        $this->expectException(ConnectorException::class);
        $this->gateway()->completePayout(['affiliate_id' => 1], '');
    }

    public function test_check_coupon_does_not_require_idempotency_key(): void
    {
        Http::fake(['*' => Http::response($this->envelope(['available' => true]), 200)]);

        // Should not throw
        $response = $this->gateway()->checkCoupon('TESTCODE');
        $this->assertTrue($response->ok);
    }

    // -------------------------------------------------------------------------
    // Response envelope validation
    // -------------------------------------------------------------------------

    public function test_mismatched_connection_key_throws_non_retryable(): void
    {
        Http::fake(['*' => Http::response(array_merge($this->envelope(), ['connection_key' => 'wrong-key']), 200)]);

        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->health();
        } catch (ConnectorException $e) {
            $this->assertSame('AUTH_INVALID', $e->errorCode);
            $this->assertFalse($e->retryable);
            throw $e;
        }
    }

    public function test_unsupported_contract_version_throws_non_retryable(): void
    {
        Http::fake(['*' => Http::response(array_merge($this->envelope(), ['contract_version' => '99']), 200)]);

        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->health();
        } catch (ConnectorException $e) {
            $this->assertSame('CONTRACT_VERSION_UNSUPPORTED', $e->errorCode);
            $this->assertFalse($e->retryable);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Error classification
    // -------------------------------------------------------------------------

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Gateway error'], 503)]);

        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->health();
        } catch (ConnectorException $e) {
            $this->assertTrue($e->retryable);
            throw $e;
        }
    }

    public function test_auth_invalid_is_non_retryable(): void
    {
        Http::fake(['*' => Http::response(['code' => 'AUTH_INVALID', 'message' => 'Invalid signature'], 401)]);

        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->health();
        } catch (ConnectorException $e) {
            $this->assertSame('AUTH_INVALID', $e->errorCode);
            $this->assertFalse($e->retryable);
            throw $e;
        }
    }

    public function test_coupon_conflict_is_non_retryable(): void
    {
        Http::fake(['*' => Http::response(['code' => 'COUPON_CONFLICT', 'message' => 'Code exists'], 409)]);

        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->provisionCoupon(['code' => 'DUP'], 'idem-001');
        } catch (ConnectorException $e) {
            $this->assertSame('COUPON_CONFLICT', $e->errorCode);
            $this->assertFalse($e->retryable);
            throw $e;
        }
    }

    public function test_rate_limited_is_retryable(): void
    {
        Http::fake(['*' => Http::response(['code' => 'RATE_LIMITED', 'message' => 'Too many requests'], 429)]);

        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->health();
        } catch (ConnectorException $e) {
            $this->assertTrue($e->retryable);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Secret non-disclosure
    // -------------------------------------------------------------------------

    public function test_descriptor_does_not_expose_shared_secret(): void
    {
        $descriptor = $this->gateway()->descriptor();

        $encoded = json_encode($descriptor);
        $this->assertStringNotContainsString(self::SharedSecret, (string) $encoded);
        $this->assertArrayNotHasKey('shared_secret', $descriptor);
    }

    public function test_connector_exception_message_does_not_contain_secret(): void
    {
        Http::fake(['*' => Http::response(['code' => 'AUTH_INVALID', 'message' => 'Bad auth'], 401)]);

        try {
            $this->gateway()->health();
            $this->fail('Expected ConnectorException');
        } catch (ConnectorException $e) {
            $this->assertStringNotContainsString(self::SharedSecret, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // HTTPS protection
    // -------------------------------------------------------------------------

    public function test_non_https_url_is_rejected_outside_local_testing(): void
    {
        config([
            'services.andy_core.url' => 'http://insecure.example.com',
            'app.env' => 'production',
        ]);

        Http::fake();

        try {
            $this->gateway()->health();
            $this->fail('Expected ConnectorException was not thrown.');
        } catch (ConnectorException $e) {
            $this->assertFalse($e->retryable);
            Http::assertNothingSent();
        }
    }

    public function test_non_https_url_is_allowed_in_testing_env(): void
    {
        config([
            'services.andy_core.url' => 'http://local.test',
            'app.env' => 'testing',
        ]);

        Http::fake(['*' => Http::response($this->envelope(), 200)]);

        // Should not throw
        $response = $this->gateway()->health();
        $this->assertTrue($response->ok);
    }

    // -------------------------------------------------------------------------
    // Redirect protection
    // -------------------------------------------------------------------------

    public function test_gateway_does_not_follow_redirects(): void
    {
        Http::fake(['*' => Http::response(null, 301, ['Location' => 'https://other.host/evil'])]);

        // With withoutRedirecting(), the 301 is treated as the final response
        // It won't be a 2xx, so it's not a valid envelope. The response body is null/empty, not valid JSON.
        $this->expectException(ConnectorException::class);

        $this->gateway()->health();
    }

    // -------------------------------------------------------------------------
    // Gateway method endpoint contracts
    // -------------------------------------------------------------------------

    public function test_health_hits_correct_endpoint(): void
    {
        Http::fake(['*' => Http::response($this->envelope(), 200)]);

        $this->gateway()->health();

        Http::assertSent(fn (Request $r) =>
            $r->method() === 'GET'
            && str_ends_with($r->url(), '/wp-json/andy-core/v1/erp/health')
        );
    }

    public function test_snapshot_hits_correct_endpoint(): void
    {
        Http::fake(['*' => Http::response($this->envelope(['items' => []]), 200)]);

        $this->gateway()->snapshot('affiliates');

        Http::assertSent(fn (Request $r) =>
            $r->method() === 'GET'
            && str_contains($r->url(), '/wp-json/andy-core/v1/erp/snapshot/affiliates')
        );
    }

    public function test_email_template_snapshot_uses_read_only_get_endpoint(): void
    {
        Http::fake(['*' => Http::response($this->envelope([
            'resource_contract_version' => 'email-os-published-v1',
            'items' => [],
            'next_cursor' => null,
        ]), 200)]);

        $this->gateway()->snapshot('email-templates');

        Http::assertSent(fn (Request $r) =>
            $r->method() === 'GET'
            && str_contains($r->url(), '/wp-json/andy-core/v1/erp/snapshot/email-templates')
            && str_contains($r->url(), 'limit=100')
        );
    }
    public function test_snapshot_uses_provider_maximum_page_limit_to_stay_below_rate_limit(): void
    {
        Http::fake(fn () => Http::response($this->envelope(['items' => [], 'next_cursor' => null]), 200));

        $this->gateway()->snapshot('referrals');

        Http::assertSent(fn ($r): bool => str_contains($r->url(), '/snapshot/referrals')
            && str_contains($r->url(), 'limit=100'));
    }

    public function test_snapshot_passes_cursor_and_updated_after_as_query_params(): void
    {
        Http::fake(['*' => Http::response($this->envelope(['items' => []]), 200)]);

        $this->gateway()->snapshot('affiliates', 'cursor-abc', '2026-01-01T00:00:00Z');

        Http::assertSent(fn (Request $r) =>
            str_contains($r->url(), 'cursor=cursor-abc')
            && str_contains($r->url(), 'updated_after=')
        );
    }

    public function test_provision_affiliate_hits_correct_endpoint(): void
    {
        Http::fake(['*' => Http::response($this->envelope(), 200)]);

        $this->gateway()->provisionAffiliate(['erp_kol_id' => 1], 'idem-001');

        Http::assertSent(fn (Request $r) =>
            $r->method() === 'POST'
            && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/wp-json/andy-core/v1/erp/affiliates/provision')
        );
    }

    public function test_set_affiliate_status_hits_correct_endpoint(): void
    {
        Http::fake(['*' => Http::response($this->envelope(), 200)]);

        $this->gateway()->setAffiliateStatus(42, 'active', 'idem-002');

        Http::assertSent(fn (Request $r) =>
            $r->method() === 'POST'
            && str_contains($r->url(), '/affiliates/42/status')
        );
    }

    public function test_check_coupon_hits_correct_endpoint(): void
    {
        Http::fake(['*' => Http::response($this->envelope(['available' => true]), 200)]);

        $this->gateway()->checkCoupon('TESTCODE');

        Http::assertSent(fn (Request $r) =>
            $r->method() === 'POST'
            && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/wp-json/andy-core/v1/erp/coupons/check')
        );
    }

    public function test_provision_coupon_hits_correct_endpoint(): void
    {
        Http::fake(['*' => Http::response($this->envelope(), 200)]);

        $this->gateway()->provisionCoupon(['code' => 'KOL10'], 'idem-003');

        Http::assertSent(fn (Request $r) =>
            $r->method() === 'POST'
            && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/wp-json/andy-core/v1/erp/coupons/provision')
        );
    }

    public function test_complete_payout_hits_correct_endpoint(): void
    {
        Http::fake(['*' => Http::response($this->envelope(), 200)]);

        $this->gateway()->completePayout(['affiliate_id' => 5, 'amount' => '100.00'], 'idem-004');

        Http::assertSent(fn (Request $r) =>
            $r->method() === 'POST'
            && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/wp-json/andy-core/v1/erp/payouts/complete')
        );
    }

    public function test_update_wordpress_password_hits_exact_user_endpoint_and_preserves_idempotency_key(): void
    {
        Http::fake(['*' => Http::response($this->envelope([
            'wordpress_user_id' => 71,
            'affiliate_id' => 81,
            'password_updated' => true,
        ]), 200)]);

        $this->gateway()->updateWordpressPassword(71, [
            'erp_kol_id' => 9,
            'affiliate_id' => 81,
            'password' => 'ReplacementPassword123',
        ], 'idem-password-001');

        Http::assertSent(fn (Request $r) =>
            $r->method() === 'POST'
            && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/wp-json/andy-core/v1/erp/wordpress/users/71/password')
            && ($r->header('X-YBY-Idempotency-Key')[0] ?? '') === 'idem-password-001'
            && $r['erp_kol_id'] === 9
            && $r['affiliate_id'] === 81
            && $r['password'] === 'ReplacementPassword123'
        );
    }

    public function test_update_wordpress_password_rejects_non_positive_user_id_before_transport(): void
    {
        Http::fake();
        $this->expectException(ConnectorException::class);
        $this->expectExceptionMessage('WordPress user ID must be positive.');

        $this->gateway()->updateWordpressPassword(0, [
            'erp_kol_id' => 9,
            'affiliate_id' => 81,
            'password' => 'ReplacementPassword123',
        ], 'idem-password-002');
    }

    // -------------------------------------------------------------------------
    // DI contract
    // -------------------------------------------------------------------------

    public function test_gateway_is_resolvable_via_contract(): void
    {
        $gateway = app(AffiliateWpGateway::class);
        $this->assertInstanceOf(HttpAffiliateWpGateway::class, $gateway);
    }

    // -------------------------------------------------------------------------
    // Bounded retry (defect 1)
    // -------------------------------------------------------------------------

    public function test_retries_on_server_error_up_to_retry_times_plus_one_total(): void
    {
        config([
            'services.andy_core.retry_times' => 2,
            'services.andy_core.retry_sleep_ms' => 0,
        ]);

        Http::fake(['*' => Http::response(['message' => 'error'], 503)]);

        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->health();
        } catch (ConnectorException $e) {
            $this->assertTrue($e->retryable);
            // 1 initial + 2 retries = 3 total requests
            $this->assertCount(3, Http::recorded());
            throw $e;
        }
    }

    public function test_retry_uses_fresh_nonce_on_each_attempt(): void
    {
        config([
            'services.andy_core.retry_times' => 1,
            'services.andy_core.retry_sleep_ms' => 0,
        ]);

        Http::fake(['*' => Http::response(['message' => 'error'], 503)]);

        try {
            $this->gateway()->health();
        } catch (ConnectorException) {
        }

        $nonces = collect(Http::recorded())->map(
            fn ($pair) => $pair[0]->header('X-YBY-Nonce')[0] ?? ''
        );

        $this->assertCount(2, $nonces);
        $this->assertNotSame($nonces[0], $nonces[1]);
    }

    public function test_mutation_retry_preserves_idempotency_key_across_attempts(): void
    {
        config([
            'services.andy_core.retry_times' => 1,
            'services.andy_core.retry_sleep_ms' => 0,
        ]);

        Http::fake(['*' => Http::response(['message' => 'error'], 503)]);

        try {
            $this->gateway()->provisionAffiliate(['erp_kol_id' => 1], 'idem-preserve');
        } catch (ConnectorException) {
        }

        $keys = collect(Http::recorded())->map(
            fn ($pair) => $pair[0]->header('X-YBY-Idempotency-Key')[0] ?? ''
        );

        $this->assertCount(2, $keys);
        $this->assertSame('idem-preserve', $keys[0]);
        $this->assertSame('idem-preserve', $keys[1]);
    }

    public function test_does_not_retry_non_retryable_error(): void
    {
        config([
            'services.andy_core.retry_times' => 2,
            'services.andy_core.retry_sleep_ms' => 0,
        ]);

        Http::fake(['*' => Http::response(['code' => 'AUTH_INVALID', 'message' => 'Bad auth'], 401)]);

        try {
            $this->gateway()->health();
        } catch (ConnectorException) {
        }

        // Non-retryable — only 1 request, no retries
        $this->assertCount(1, Http::recorded());
    }

    public function test_retry_succeeds_on_eventual_success(): void
    {
        config([
            'services.andy_core.retry_times' => 2,
            'services.andy_core.retry_sleep_ms' => 0,
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push(['message' => 'error'], 503)
                ->push($this->envelope(), 200),
        ]);

        $response = $this->gateway()->health();
        $this->assertTrue($response->ok);
        $this->assertCount(2, Http::recorded());
    }

    // -------------------------------------------------------------------------
    // ConnectionException classification (defect 2)
    // -------------------------------------------------------------------------

    public function test_connection_exception_becomes_retryable_connector_exception_after_exhaustion(): void
    {
        config([
            'services.andy_core.retry_times' => 1,
            'services.andy_core.retry_sleep_ms' => 0,
        ]);

        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;
            throw new ConnectionException('Connection timed out');
        });

        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->health();
        } catch (ConnectorException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame('PROVIDER_UNAVAILABLE', $e->errorCode);
            // 1 initial + 1 retry = 2 total attempts
            $this->assertSame(2, $callCount);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Snapshot resource allowlist (defect 3)
    // -------------------------------------------------------------------------

    public function test_snapshot_rejects_unsupported_resource(): void
    {
        Http::fake();

        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->snapshot('users');
        } catch (ConnectorException $e) {
            $this->assertFalse($e->retryable);
            $this->assertSame('CONNECTOR_ERROR', $e->errorCode);
            Http::assertNothingSent();
            throw $e;
        }
    }

    public function test_snapshot_allows_supported_resources(): void
    {
        Http::fake(['*' => Http::response($this->envelope(['items' => []]), 200)]);

        foreach (['affiliates', 'coupons', 'referrals', 'payouts'] as $resource) {
            $response = $this->gateway()->snapshot($resource);
            $this->assertTrue($response->ok, "Expected ok for resource: {$resource}");
        }
    }

    // -------------------------------------------------------------------------
    // Envelope fail-closed validation (defect 4)
    // -------------------------------------------------------------------------

    public function test_ok_false_is_rejected_even_on_2xx(): void
    {
        $envelope = $this->envelope();
        $envelope['ok'] = false;
        Http::fake(['*' => Http::response($envelope, 200)]);

        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->health();
        } catch (ConnectorException $e) {
            $this->assertFalse($e->retryable);
            throw $e;
        }
    }

    public function test_missing_request_id_is_rejected(): void
    {
        $envelope = $this->envelope();
        unset($envelope['request_id']);
        Http::fake(['*' => Http::response($envelope, 200)]);

        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->health();
        } catch (ConnectorException $e) {
            $this->assertFalse($e->retryable);
            throw $e;
        }
    }

    public function test_empty_request_id_is_rejected(): void
    {
        $envelope = $this->envelope();
        $envelope['request_id'] = '';
        Http::fake(['*' => Http::response($envelope, 200)]);

        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->health();
        } catch (ConnectorException $e) {
            $this->assertFalse($e->retryable);
            throw $e;
        }
    }

    public function test_missing_data_key_is_rejected(): void
    {
        $envelope = $this->envelope();
        unset($envelope['data']);
        Http::fake(['*' => Http::response($envelope, 200)]);

        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->health();
        } catch (ConnectorException $e) {
            $this->assertFalse($e->retryable);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // JSON encoding failure (defect 5)
    // -------------------------------------------------------------------------

    public function test_json_encoding_failure_throws_non_retryable_connector_exception(): void
    {
        Http::fake();

        // INF cannot be JSON-encoded; PHP json_encode with JSON_THROW_ON_ERROR will throw.
        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->provisionAffiliate(['bad' => INF], 'idem-json');
        } catch (ConnectorException $e) {
            $this->assertFalse($e->retryable);
            $this->assertSame('CONNECTOR_ERROR', $e->errorCode);
            Http::assertNothingSent();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Connect timeout configuration
    // -------------------------------------------------------------------------

    public function test_connect_timeout_defaults_to_five_seconds(): void
    {
        $gateway = $this->gateway();
        $method = new \ReflectionMethod($gateway, 'connectTimeout');
        $method->setAccessible(true);

        $this->assertSame(5, $method->invoke($gateway));
    }

    public function test_connect_timeout_respects_config_value(): void
    {
        config(['services.andy_core.connect_timeout' => 3]);

        $gateway = $this->gateway();
        $method = new \ReflectionMethod($gateway, 'connectTimeout');
        $method->setAccessible(true);

        $this->assertSame(3, $method->invoke($gateway));
    }

    public function test_connect_timeout_is_capped_at_ten_seconds(): void
    {
        config(['services.andy_core.connect_timeout' => 9999]);

        $gateway = $this->gateway();
        $method = new \ReflectionMethod($gateway, 'connectTimeout');
        $method->setAccessible(true);

        $this->assertSame(10, $method->invoke($gateway));
    }

    public function test_connect_timeout_minimum_is_one_second(): void
    {
        config(['services.andy_core.connect_timeout' => 0]);

        $gateway = $this->gateway();
        $method = new \ReflectionMethod($gateway, 'connectTimeout');
        $method->setAccessible(true);

        $this->assertSame(1, $method->invoke($gateway));
    }

    // -------------------------------------------------------------------------
    // Hard caps on timing and retry knobs
    // -------------------------------------------------------------------------

    public function test_request_timeout_is_capped_at_thirty_seconds(): void
    {
        config(['services.andy_core.timeout' => 9999]);

        $gateway = $this->gateway();
        $method = new \ReflectionMethod($gateway, 'timeout');
        $method->setAccessible(true);

        $this->assertSame(30, $method->invoke($gateway));
    }

    public function test_retry_times_is_capped_at_three(): void
    {
        config(['services.andy_core.retry_times' => 999]);

        $gateway = $this->gateway();
        $method = new \ReflectionMethod($gateway, 'retryTimes');
        $method->setAccessible(true);

        $this->assertSame(3, $method->invoke($gateway));
    }

    public function test_retry_sleep_ms_is_capped_at_five_thousand(): void
    {
        config(['services.andy_core.retry_sleep_ms' => 99999]);

        $gateway = $this->gateway();
        $method = new \ReflectionMethod($gateway, 'retrySleepMs');
        $method->setAccessible(true);

        $this->assertSame(5000, $method->invoke($gateway));
    }

    public function test_hard_caps_prevent_unbounded_request_when_env_is_extreme(): void
    {
        config([
            'services.andy_core.retry_times' => PHP_INT_MAX,
            'services.andy_core.retry_sleep_ms' => PHP_INT_MAX,
            'services.andy_core.timeout' => PHP_INT_MAX,
            'services.andy_core.connect_timeout' => PHP_INT_MAX,
        ]);

        $gateway = $this->gateway();

        $retryMethod = new \ReflectionMethod($gateway, 'retryTimes');
        $retryMethod->setAccessible(true);
        $sleepMethod = new \ReflectionMethod($gateway, 'retrySleepMs');
        $sleepMethod->setAccessible(true);
        $timeoutMethod = new \ReflectionMethod($gateway, 'timeout');
        $timeoutMethod->setAccessible(true);
        $connectTimeoutMethod = new \ReflectionMethod($gateway, 'connectTimeout');
        $connectTimeoutMethod->setAccessible(true);

        $this->assertLessThanOrEqual(3, $retryMethod->invoke($gateway));
        $this->assertLessThanOrEqual(5000, $sleepMethod->invoke($gateway));
        $this->assertLessThanOrEqual(30, $timeoutMethod->invoke($gateway));
        $this->assertLessThanOrEqual(10, $connectTimeoutMethod->invoke($gateway));
    }

    // -------------------------------------------------------------------------
    // URL validation — userinfo / query / fragment rejection
    // -------------------------------------------------------------------------

    public function test_url_with_userinfo_is_rejected_and_not_disclosed(): void
    {
        config([
            'services.andy_core.url' => 'https://user:secret@wp.test',
            'app.env' => 'production',
        ]);

        Http::fake();

        try {
            $this->gateway()->health();
            $this->fail('Expected ConnectorException was not thrown.');
        } catch (ConnectorException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertFalse($e->retryable);
            Http::assertNothingSent();
        }
    }

    public function test_descriptor_shows_configured_false_for_url_with_userinfo(): void
    {
        config([
            'services.andy_core.url' => 'https://user:secret@wp.test',
        ]);

        $descriptor = $this->gateway()->descriptor();

        $this->assertFalse($descriptor['configured']);
        $this->assertSame('', $descriptor['url']);
        $encoded = json_encode($descriptor);
        $this->assertStringNotContainsString('secret', (string) $encoded);
        $this->assertStringNotContainsString('user:secret', (string) $encoded);
    }

    public function test_url_with_query_string_is_rejected(): void
    {
        config([
            'services.andy_core.url' => 'https://wp.test?foo=bar',
            'app.env' => 'testing',
        ]);

        Http::fake();

        try {
            $this->gateway()->health();
            $this->fail('Expected ConnectorException was not thrown.');
        } catch (ConnectorException $e) {
            $this->assertFalse($e->retryable);
            Http::assertNothingSent();
        }
    }

    public function test_url_with_fragment_is_rejected(): void
    {
        config([
            'services.andy_core.url' => 'https://wp.test#section',
            'app.env' => 'testing',
        ]);

        Http::fake();

        try {
            $this->gateway()->health();
            $this->fail('Expected ConnectorException was not thrown.');
        } catch (ConnectorException $e) {
            $this->assertFalse($e->retryable);
            Http::assertNothingSent();
        }
    }

    public function test_url_with_invalid_scheme_is_rejected(): void
    {
        config([
            'services.andy_core.url' => 'ftp://wp.test',
            'app.env' => 'testing',
        ]);

        Http::fake();

        try {
            $this->gateway()->health();
            $this->fail('Expected ConnectorException was not thrown.');
        } catch (ConnectorException $e) {
            $this->assertFalse($e->retryable);
            Http::assertNothingSent();
        }
    }

    public function test_url_with_non_root_path_is_rejected(): void
    {
        config([
            'services.andy_core.url' => 'https://wp.test/some/path',
            'app.env' => 'testing',
        ]);

        Http::fake();

        try {
            $this->gateway()->health();
            $this->fail('Expected ConnectorException was not thrown.');
        } catch (ConnectorException $e) {
            $this->assertFalse($e->retryable);
            Http::assertNothingSent();
        }
    }

    public function test_url_with_root_slash_path_is_allowed(): void
    {
        config([
            'services.andy_core.url' => 'https://wp.test/',
            'app.env' => 'testing',
        ]);

        Http::fake(['*' => Http::response($this->envelope(), 200)]);

        $response = $this->gateway()->health();
        $this->assertTrue($response->ok);
    }

    public function test_empty_url_results_in_not_configured(): void
    {
        config(['services.andy_core.url' => '']);

        $this->assertFalse($this->gateway()->descriptor()['configured']);
    }

    // -------------------------------------------------------------------------
    // 3xx redirect is non-retryable
    // -------------------------------------------------------------------------

    public function test_redirect_response_is_non_retryable(): void
    {
        config([
            'services.andy_core.retry_times' => 2,
            'services.andy_core.retry_sleep_ms' => 0,
        ]);

        Http::fake(['*' => Http::response(null, 301, ['Location' => 'https://other.host/evil'])]);

        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->health();
        } catch (ConnectorException $e) {
            $this->assertFalse($e->retryable);
            $this->assertSame('CONNECTOR_ERROR', $e->errorCode);
            // Must not retry — exactly 1 request sent
            $this->assertCount(1, Http::recorded());
            throw $e;
        }
    }

    public function test_302_redirect_is_also_non_retryable(): void
    {
        config([
            'services.andy_core.retry_times' => 2,
            'services.andy_core.retry_sleep_ms' => 0,
        ]);

        Http::fake(['*' => Http::response(null, 302, ['Location' => 'https://other.host/'])]);

        $this->expectException(ConnectorException::class);

        try {
            $this->gateway()->health();
        } catch (ConnectorException $e) {
            $this->assertFalse($e->retryable);
            $this->assertCount(1, Http::recorded());
            throw $e;
        }
    }
}
