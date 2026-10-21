<?php

namespace App\Modules\Kol\Infrastructure\AffiliateWp;

use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use LogicException;

final class LocalUatAffiliateWpGateway implements AffiliateWpGateway
{
    public function descriptor(): array
    {
        return [
            'configured' => true,
            'url' => 'local-uat-fake://affiliatewp',
            'connection_key' => $this->connectionKey(),
            'key_id' => 'local-uat-fake',
        ];
    }

    public function health(): ConnectorResponse
    {
        return $this->response(['status' => 'ok', 'fixture' => 'local_uat_fake']);
    }

    public function snapshot(string $resource, ?string $cursor = null, ?string $updatedAfter = null): ConnectorResponse
    {
        if ($cursor !== null) {
            return $this->response(['items' => [], 'next_cursor' => null]);
        }

        if ($resource === 'email-templates') {
            return $this->response([
                'resource_contract_version' => 'email-os-published-v1',
                'items' => [
                    [
                        'template_key' => 'wordpress:reset_password',
                        'provider' => 'wordpress',
                        'label' => 'Reset Password',
                        'status' => 'published',
                        'runtime_enabled' => true,
                        'published_version' => 3,
                        'content_hash_sha256' => str_repeat('a', 64),
                        'published_at' => '2026-09-15 17:00:00',
                    ],
                    [
                        'template_key' => 'andy_core:inquiry_internal_notification',
                        'provider' => 'andy_core',
                        'label' => 'Inquiry Internal Notification',
                        'status' => 'published',
                        'runtime_enabled' => true,
                        'published_version' => 2,
                        'content_hash_sha256' => str_repeat('b', 64),
                        'published_at' => '2026-09-15 17:05:00',
                    ],
                ],
                'next_cursor' => null,
            ]);
        }
        if ($resource === 'affiliates') {
            return $this->response([
                'items' => [
                    [
                        'affiliate_id' => 810001,
                        'user_id' => 710001,
                        'user_email' => 'uat-external-pending@example.test',
                        'display_name' => 'UAT External Pending',
                        'email' => 'uat-external-pending@example.test',
                        'status' => 'pending',
                    ],
                    [
                        'affiliate_id' => 810002,
                        'user_id' => 710002,
                        'user_email' => 'uat-external-active@example.test',
                        'display_name' => 'UAT External Active',
                        'email' => 'uat-external-active@example.test',
                        'status' => 'active',
                    ],
                ],
                'next_cursor' => null,
            ]);
        }

        if ($resource === 'coupons') {
            return $this->response([
                'items' => [
                    [
                        'coupon_id' => 840001,
                        'code' => 'UAT-AFF-15',
                        'normalized_code' => 'uat-aff-15',
                        'discount_type' => 'percent',
                        'amount' => '15',
                        'status' => 'publish',
                        'date_expires' => null,
                        'linked_affiliate_id' => 830001,
                    ],
                    [
                        'coupon_id' => 840002,
                        'code' => 'UAT-WELCOME',
                        'normalized_code' => 'uat-welcome',
                        'discount_type' => 'fixed_cart',
                        'amount' => '10',
                        'status' => 'publish',
                        'date_expires' => null,
                        'linked_affiliate_id' => null,
                    ],
                ],
                'next_cursor' => null,
            ]);
        }

        if ($resource === 'referrals') {
            return $this->response([
                'items' => [
                    [
                        'referral_id' => 860001,
                        'affiliate_id' => 810002,
                        'reference' => '990001',
                        'description' => 'Local UAT WooCommerce referral',
                        'amount' => '12.50',
                        'currency' => 'USD',
                        'status' => 'paid',
                        'context' => 'woocommerce',
                        'date' => '2026-09-01T00:00:00+00:00',
                        'payout_id' => 870001,
                    ],
                    [
                        'referral_id' => 860002,
                        'affiliate_id' => 810002,
                        'reference' => '990001',
                        'description' => 'Second referral for same order',
                        'amount' => '5',
                        'currency' => 'USD',
                        'status' => 'unpaid',
                        'context' => 'woocommerce',
                        'date' => '2026-09-01T00:05:00+00:00',
                        'payout_id' => null,
                    ],
                    [
                        'referral_id' => 860003,
                        'affiliate_id' => 899999,
                        'reference' => '990099',
                        'description' => 'Unresolved historical referral',
                        'amount' => '3.25',
                        'currency' => 'USD',
                        'status' => 'pending',
                        'context' => 'woocommerce',
                        'date' => '2026-09-01T00:10:00+00:00',
                        'payout_id' => null,
                    ],
                ],
                'next_cursor' => null,
            ]);
        }

        throw new LogicException('Local UAT fake does not support snapshot resource: '.$resource);
    }

    public function checkAffiliateIdentity(string $email): ConnectorResponse
    {
        $normalized = mb_strtolower(trim($email));
        if (str_contains($normalized, 'existing')) {
            return $this->response([
                'available' => false,
                'wordpress_user_id' => 719999,
                'affiliate_id' => str_contains($normalized, 'affiliate') ? 819999 : null,
            ]);
        }

        return $this->response([
            'available' => true,
            'wordpress_user_id' => null,
            'affiliate_id' => null,
        ]);
    }

    public function provisionAffiliate(array $payload, string $idempotencyKey): ConnectorResponse
    {
        $kolId = (int) ($payload['erp_kol_id'] ?? 0);
        $email = (string) ($payload['email'] ?? '');

        if (str_contains($email, 'provider-error')) {
            throw ConnectorException::retryable('Synthetic local UAT provider timeout.', 'PROVIDER_UNAVAILABLE');
        }
        if (str_contains($email, 'conflict')) {
            throw ConnectorException::nonRetryable('Synthetic local UAT Affiliate ID conflict.', 'AFFILIATE_ID_CONFLICT');
        }

        $status = str_contains($email, 'readback') ? 'pending' : 'active';

        return $this->response([
            'affiliate_id' => 820000 + $kolId,
            'user_id' => 720000 + $kolId,
            'user_email' => $email !== '' ? $email : null,
            'status' => $status,
            'idempotency_key_echo' => $idempotencyKey,
        ]);
    }

    public function setAffiliateStatus(int $affiliateId, string $status, string $idempotencyKey): ConnectorResponse
    {
        return $this->response(['affiliate_id' => $affiliateId, 'status' => $status]);
    }

    public function updateWordpressPassword(int $wordpressUserId, array $payload, string $idempotencyKey): ConnectorResponse
    {
        $password = (string) ($payload['password'] ?? '');
        if (str_contains($password, 'provider-error')) {
            throw ConnectorException::retryable('Synthetic local UAT password provider timeout.', 'PROVIDER_UNAVAILABLE');
        }

        return $this->response([
            'wordpress_user_id' => $wordpressUserId,
            'affiliate_id' => (int) ($payload['affiliate_id'] ?? 0),
            'password_updated' => true,
        ]);
    }

    public function checkCoupon(string $code): ConnectorResponse
    {
        $normalized = mb_strtolower(trim($code));
        if ($normalized === 'uat-existing') {
            return $this->response([
                'available' => false,
                'coupon_id' => 840099,
                'code' => 'UAT-EXISTING',
                'linked_affiliate_id' => 830002,
                'normalized_code' => 'uat-existing',
            ]);
        }

        return $this->response(['available' => true, 'normalized_code' => $normalized]);
    }

    public function provisionCoupon(array $payload, string $idempotencyKey): ConnectorResponse
    {
        $code = (string) ($payload['code'] ?? '');
        $normalized = mb_strtolower(trim($code));
        if (str_contains($normalized, 'provider-error')) {
            throw ConnectorException::retryable('Synthetic local UAT Coupon provider timeout.', 'PROVIDER_UNAVAILABLE');
        }
        if (str_contains($normalized, 'conflict')) {
            throw ConnectorException::nonRetryable('Synthetic local UAT Coupon conflict.', 'COUPON_CONFLICT', 409);
        }

        $affiliateId = (int) ($payload['affiliate_id'] ?? 0);
        if (str_contains($normalized, 'readback')) {
            $affiliateId += 1;
        }

        return $this->response([
            'coupon_id' => 850000 + (int) ($payload['erp_kol_id'] ?? 0),
            'code' => $code,
            'normalized_code' => $normalized,
            'affiliate_id' => $affiliateId,
            'discount_type' => (string) ($payload['discount_type'] ?? ''),
            'amount' => (string) ($payload['amount'] ?? ''),
            'date_expires' => $payload['expiry'] ?? null,
        ]);
    }

    public function completePayout(array $payload, string $idempotencyKey): ConnectorResponse
    {
        throw new LogicException('Payout is outside M1.4B local UAT scope.');
    }

    private function response(mixed $data): ConnectorResponse
    {
        return ConnectorResponse::fromArray([
            'ok' => true,
            'contract_version' => '1',
            'request_id' => 'local-uat-fixture',
            'connection_key' => $this->connectionKey(),
            'data' => $data,
        ]);
    }

    private function connectionKey(): string
    {
        return (string) config('services.andy_core.connection_key', 'beyourlover.com');
    }
}
