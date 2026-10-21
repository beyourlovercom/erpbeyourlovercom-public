<?php

namespace Tests\Feature\Kol;

use App\Modules\Kol\Infrastructure\AffiliateWp\HmacV1Signer;
use Tests\TestCase;

final class HmacV1SignerTest extends TestCase
{
    public function test_headers_contain_all_required_fields(): void
    {
        $headers = HmacV1Signer::headers(
            method: 'POST',
            pathWithQuery: '/wp-json/andy-core/v1/erp/affiliates/provision',
            connectionKey: 'beyourlover.com',
            keyId: 'key-001',
            sharedSecret: 'test-secret',
            body: '{"erp_kol_id":1}',
            idempotencyKey: 'idem-abc',
            timestamp: '1700000000',
            nonce: 'nonce-xyz',
        );

        $this->assertArrayHasKey('X-YBY-Key-Id', $headers);
        $this->assertArrayHasKey('X-YBY-Connection-Key', $headers);
        $this->assertArrayHasKey('X-YBY-Timestamp', $headers);
        $this->assertArrayHasKey('X-YBY-Nonce', $headers);
        $this->assertArrayHasKey('X-YBY-Idempotency-Key', $headers);
        $this->assertArrayHasKey('X-YBY-Signature-Version', $headers);
        $this->assertArrayHasKey('X-YBY-Signature', $headers);

        $this->assertSame('key-001', $headers['X-YBY-Key-Id']);
        $this->assertSame('beyourlover.com', $headers['X-YBY-Connection-Key']);
        $this->assertSame('1700000000', $headers['X-YBY-Timestamp']);
        $this->assertSame('nonce-xyz', $headers['X-YBY-Nonce']);
        $this->assertSame('idem-abc', $headers['X-YBY-Idempotency-Key']);
        $this->assertSame('v1', $headers['X-YBY-Signature-Version']);
    }

    public function test_idempotency_key_header_absent_when_null(): void
    {
        $headers = HmacV1Signer::headers(
            method: 'GET',
            pathWithQuery: '/wp-json/andy-core/v1/erp/health',
            connectionKey: 'beyourlover.com',
            keyId: 'key-001',
            sharedSecret: 'test-secret',
            body: '',
            idempotencyKey: null,
            timestamp: '1700000000',
            nonce: 'nonce-xyz',
        );

        $this->assertArrayNotHasKey('X-YBY-Idempotency-Key', $headers);
    }

    public function test_canonical_signature_is_deterministic(): void
    {
        $args = [
            'method' => 'POST',
            'pathWithQuery' => '/wp-json/andy-core/v1/erp/affiliates/provision',
            'connectionKey' => 'beyourlover.com',
            'keyId' => 'key-001',
            'sharedSecret' => 'secret-value',
            'body' => '{"erp_kol_id":42}',
            'idempotencyKey' => 'idem-001',
            'timestamp' => '1700000001',
            'nonce' => 'nonce-abc',
        ];

        $h1 = HmacV1Signer::headers(...$args);
        $h2 = HmacV1Signer::headers(...$args);

        $this->assertSame($h1['X-YBY-Signature'], $h2['X-YBY-Signature']);
    }

    public function test_signature_changes_when_body_changes(): void
    {
        $base = [
            'method' => 'POST',
            'pathWithQuery' => '/wp-json/andy-core/v1/erp/affiliates/provision',
            'connectionKey' => 'beyourlover.com',
            'keyId' => 'key-001',
            'sharedSecret' => 'secret-value',
            'body' => '{"erp_kol_id":1}',
            'idempotencyKey' => 'idem-001',
            'timestamp' => '1700000001',
            'nonce' => 'nonce-abc',
        ];

        $h1 = HmacV1Signer::headers(...$base);
        $h2 = HmacV1Signer::headers(...array_merge($base, ['body' => '{"erp_kol_id":2}']));

        $this->assertNotSame($h1['X-YBY-Signature'], $h2['X-YBY-Signature']);
    }

    public function test_signature_changes_when_secret_changes(): void
    {
        $base = [
            'method' => 'POST',
            'pathWithQuery' => '/wp-json/andy-core/v1/erp/affiliates/provision',
            'connectionKey' => 'beyourlover.com',
            'keyId' => 'key-001',
            'sharedSecret' => 'secret-a',
            'body' => '{}',
            'idempotencyKey' => 'idem-001',
            'timestamp' => '1700000001',
            'nonce' => 'nonce-abc',
        ];

        $h1 = HmacV1Signer::headers(...$base);
        $h2 = HmacV1Signer::headers(...array_merge($base, ['sharedSecret' => 'secret-b']));

        $this->assertNotSame($h1['X-YBY-Signature'], $h2['X-YBY-Signature']);
    }

    public function test_canonical_signature_matches_expected_hmac(): void
    {
        $body = '{"erp_kol_id":42}';
        $bodyHash = hash('sha256', $body);
        $canonical = implode("\n", ['POST', '/wp-json/andy-core/v1/erp/affiliates/provision', '1700000001', 'nonce-abc', 'beyourlover.com', 'idem-001', $bodyHash]);
        $expected = hash_hmac('sha256', $canonical, 'secret-value');

        $headers = HmacV1Signer::headers(
            method: 'POST',
            pathWithQuery: '/wp-json/andy-core/v1/erp/affiliates/provision',
            connectionKey: 'beyourlover.com',
            keyId: 'key-001',
            sharedSecret: 'secret-value',
            body: $body,
            idempotencyKey: 'idem-001',
            timestamp: '1700000001',
            nonce: 'nonce-abc',
        );

        $this->assertSame($expected, $headers['X-YBY-Signature']);
    }
}
