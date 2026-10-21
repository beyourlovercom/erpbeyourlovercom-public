<?php

namespace App\Modules\Kol\Application\AffiliateWp;

use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class CouponProvisioningService
{
    private const COUPON = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliateCoupon';
    private const ACCOUNT = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliateAccount';
    private const KOL = 'App\\Modules\\Kol\\Infrastructure\\Models\\Kol';
    private const RETRYABLE = ['error', 'conflict', 'readback_mismatch', 'in_progress'];

    public function __construct(private readonly AffiliateWpGateway $gateway) {}

    /** @param array{kol_id:int,code:string,discount_type:string,amount:string,expiry:?string} $input */
    public function provision(string $connectionKey, array $input, Authenticatable $actor): Model
    {
        if (! $actor->can('kol.affiliates.manage')) {
            throw new AuthorizationException;
        }

        return $this->provisionInternal($connectionKey, $input, $actor);
    }

    /** @param array{kol_id:int,code:string,discount_type:string,amount:string,expiry:?string} $input */
    public function provisionForCreate(string $connectionKey, array $input, Authenticatable $actor): Model
    {
        if (! $actor->can('kol.operate')) {
            throw new AuthorizationException;
        }

        return $this->provisionInternal($connectionKey, $input, $actor);
    }

    /** @param array{kol_id:int,code:string,discount_type:string,amount:string,expiry:?string} $input */
    private function provisionInternal(string $connectionKey, array $input, Authenticatable $actor): Model
    {
        $kolClass = self::KOL;
        $kol = $kolClass::query()->whereKey($input['kol_id'])->whereNull('archived_at')->first();
        if (! $kol) {
            throw new DomainException('KOL must exist and be active.');
        }
        $accountClass = self::ACCOUNT;
        $account = $accountClass::query()->where('connection_key', $connectionKey)
            ->where('kol_id', $kol->getKey())->whereNotNull('affiliate_id')->where('reconciliation_status', 'present')->first();
        if (! $account) {
            throw new DomainException('KOL must have an authoritative Affiliate binding before Coupon provisioning.');
        }

        $code = trim($input['code']);
        $normalized = $this->normalizeCode($code);
        $discountType = $input['discount_type'];
        if (! in_array($discountType, ['percent', 'fixed_cart', 'fixed_product'], true)) {
            throw new DomainException('Unsupported Coupon discount type.');
        }
        $amount = $this->canonicalDecimal($input['amount']);
        if ($discountType === 'percent' && (float) $amount > 100) {
            throw new DomainException('Percent Coupon amount cannot exceed 100.');
        }
        $expiry = $input['expiry'] ?? null;

        $couponClass = self::COUPON;
        $existing = $couponClass::query()->where('connection_key', $connectionKey)->where('normalized_code', $normalized)->first();
        if ($existing && ! ($existing->origin === 'erp_provisioned' && $existing->coupon_id === null && in_array($existing->provisioning_status, self::RETRYABLE, true) && (int) $existing->kol_id === (int) $kol->getKey())) {
            throw new DomainException($this->localConflictMessage($existing));
        }
        if ($existing && ! $this->retryPayloadMatches($existing, (int) $account->affiliate_id, (int) $kol->getKey(), $code, $discountType, $amount, $expiry)) {
            throw new DomainException('Coupon retry must use the exact original payload because the persisted idempotency key is immutable.');
        }

        if (! $existing) {
            $check = $this->gateway->checkCoupon($code);
            if (! $check->ok || $check->connectionKey !== $connectionKey || ! is_array($check->data) || ! array_key_exists('available', $check->data) || ! is_bool($check->data['available'])) {
                throw new DomainException('Live Coupon conflict check failed validation.');
            }
            if ($check->data['available'] !== true) {
                throw new DomainException($this->liveConflictMessage($check->data));
            }
        }

        [$row, $key] = DB::transaction(function () use ($couponClass, $existing, $connectionKey, $kol, $account, $code, $normalized, $discountType, $amount, $expiry): array {
            if ($existing) {
                $locked = $couponClass::query()->whereKey($existing->getKey())->lockForUpdate()->firstOrFail();
                $key = $locked->provision_idempotency_key ?: Str::uuid()->toString();
                $locked->update([
                    'code' => $code, 'discount_type' => $discountType, 'amount' => $amount,
                    'date_expires' => $expiry, 'affiliate_id' => $account->affiliate_id, 'kol_id' => $kol->getKey(),
                    'provision_idempotency_key' => $key, 'provisioning_status' => 'in_progress',
                    'last_error_code' => null, 'last_error_message' => null,
                ]);
                return [$locked, $key];
            }
            $key = Str::uuid()->toString();
            $row = $couponClass::create([
                'connection_key' => $connectionKey, 'coupon_id' => null, 'code' => $code,
                'normalized_code' => $normalized, 'discount_type' => $discountType, 'amount' => $amount,
                'date_expires' => $expiry, 'affiliate_id' => $account->affiliate_id, 'kol_id' => $kol->getKey(),
                'origin' => 'erp_provisioned', 'sync_status' => 'never_synced', 'reconciliation_status' => 'present',
                'provisioning_status' => 'in_progress', 'provision_idempotency_key' => $key,
            ]);
            return [$row, $key];
        });

        $payload = [
            'erp_kol_id' => (int) $kol->getKey(),
            'affiliate_id' => (int) $account->affiliate_id,
            'code' => $code,
            'discount_type' => $discountType,
            'amount' => $amount,
            'expiry' => $expiry,
        ];

        try {
            $response = $this->gateway->provisionCoupon($payload, $key);
        } catch (RuntimeException $exception) {
            $errorCode = property_exists($exception, 'errorCode') && is_string($exception->errorCode) ? $exception->errorCode : 'CONNECTOR_ERROR';
            $status = $errorCode === 'COUPON_CONFLICT' ? 'conflict' : 'error';
            $row->update(['provisioning_status' => $status, 'last_error_code' => $errorCode, 'last_error_message' => mb_substr($exception->getMessage(), 0, 500)]);
            return $row->fresh();
        }

        return $this->finalize($row, $payload, $response->data);
    }

    private function finalize(Model $row, array $payload, mixed $data): Model
    {
        $ok = false;
        if (is_array($data)) {
            try {
                $ok = $this->positiveInt($data['coupon_id'] ?? null) !== null
                    && $this->normalizeCode((string) ($data['normalized_code'] ?? $data['code'] ?? '')) === $row->normalized_code
                    && (int) ($data['affiliate_id'] ?? 0) === (int) $payload['affiliate_id']
                    && (string) ($data['discount_type'] ?? '') === $payload['discount_type']
                    && $this->canonicalDecimal((string) ($data['amount'] ?? '')) === $payload['amount']
                    && $this->sameExpiry($payload['expiry'], $data['date_expires'] ?? null);
            } catch (DomainException) {
                $ok = false;
            }
        }

        if (! $ok) {
            $row->update(['provisioning_status' => 'readback_mismatch', 'last_error_code' => 'READBACK_MISMATCH', 'last_error_message' => 'Provider Coupon read-back did not match the exact request.']);
            return $row->fresh();
        }

        $row->update([
            'coupon_id' => (int) $data['coupon_id'],
            'code' => (string) ($data['code'] ?? $row->code),
            'affiliate_id' => (int) $data['affiliate_id'],
            'discount_type' => (string) $data['discount_type'],
            'amount' => $this->canonicalDecimal((string) $data['amount']),
            'date_expires' => $data['date_expires'] ?? null,
            'provisioning_status' => 'active',
            'reconciliation_status' => 'present',
            'last_error_code' => null,
            'last_error_message' => null,
        ]);
        return $row->fresh();
    }

    private function localConflictMessage(Model $coupon): string
    {
        $facts = [];
        if ($coupon->coupon_id !== null) {
            $facts[] = 'Coupon #'.(int) $coupon->coupon_id;
        }
        if ($coupon->affiliate_id !== null) {
            $facts[] = 'Affiliate #'.(int) $coupon->affiliate_id;
        }
        if ($coupon->kol_id !== null) {
            $facts[] = 'KOL #'.(int) $coupon->kol_id;
        }

        return 'Coupon code already exists in the ERP mirror'.($facts !== [] ? ' ('.implode(', ', $facts).').' : '.');
    }

    /** @param array<string,mixed> $data */
    private function liveConflictMessage(array $data): string
    {
        $facts = [];
        $couponId = $this->positiveInt($data['coupon_id'] ?? null);
        $affiliateId = $this->positiveInt($data['linked_affiliate_id'] ?? null);
        if ($couponId !== null) {
            $facts[] = 'Coupon #'.$couponId;
        }
        if ($affiliateId !== null) {
            $facts[] = 'Affiliate #'.$affiliateId;
        }

        return 'Coupon code already exists in WordPress/WooCommerce'.($facts !== [] ? ' ('.implode(', ', $facts).').' : '.');
    }

    private function retryPayloadMatches(Model $coupon, int $affiliateId, int $kolId, string $code, string $discountType, string $amount, ?string $expiry): bool
    {
        if ((int) $coupon->affiliate_id !== $affiliateId || (int) $coupon->kol_id !== $kolId || (string) $coupon->code !== $code
            || (string) $coupon->discount_type !== $discountType || (string) $coupon->amount !== $amount) {
            return false;
        }

        if ($expiry === null) {
            return $coupon->date_expires === null;
        }
        if ($coupon->date_expires === null) {
            return false;
        }
        try {
            return $coupon->date_expires->getTimestamp() === (new \DateTimeImmutable($expiry))->getTimestamp();
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeCode(string $code): string
    {
        $normalized = mb_strtolower(trim($code));
        if ($normalized === '' || mb_strlen($normalized) > 255) {
            throw new DomainException('Coupon code is required and must be at most 255 characters.');
        }
        return $normalized;
    }

    private function canonicalDecimal(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\\d*)(?:\\.\\d{1,6})?$/', $value)) {
            throw new DomainException('Coupon amount must be a non-negative decimal with up to 6 decimal places.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($fraction, '0');
        return $whole.($fraction !== '' ? '.'.$fraction : '');
    }

    private function positiveInt(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
    }

    private function sameExpiry(?string $requested, mixed $returned): bool
    {
        if ($requested === null) {
            return $returned === null || $returned === '';
        }
        if (! is_string($returned) || $returned === '') {
            return false;
        }
        try {
            return (new \DateTimeImmutable($requested))->getTimestamp() === (new \DateTimeImmutable($returned))->getTimestamp();
        } catch (\Throwable) {
            return false;
        }
    }
}
