<?php

namespace App\Modules\Kol\Application\AffiliateWp;

use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class CouponSnapshotSyncService
{
    private const COUPON = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliateCoupon';
    private const ACCOUNT = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliateAccount';

    public function __construct(private readonly AffiliateWpGateway $gateway) {}

    /** @return array{synced:int,marked_missing:int,errors:list<string>,provider_references:list<string>} */
    public function sync(string $connectionKey, Authenticatable $actor): array
    {
        if (! $actor->can('kol.affiliates.manage')) {
            throw new AuthorizationException;
        }

        $items = [];
        $codeIds = [];
        $cursor = null;
        $visited = [];
        do {
            if ($cursor !== null) {
                if (isset($visited[$cursor])) {
                    throw new UnexpectedValueException('Coupon snapshot cursor loop detected.');
                }
                $visited[$cursor] = true;
            }
            $response = $this->gateway->snapshot('coupons', $cursor, null);
            if (! $response->ok || $response->connectionKey !== $connectionKey || ! is_array($response->data)) {
                throw new UnexpectedValueException('Coupon snapshot response failed validation.');
            }
            $page = $response->data['items'] ?? null;
            if (! is_array($page)) {
                throw new UnexpectedValueException('Coupon snapshot data.items is required.');
            }
            foreach ($page as $item) {
                $row = $this->normalizeItem($item);
                if (isset($items[$row['coupon_id']]) && $items[$row['coupon_id']] !== $row) {
                    throw new UnexpectedValueException('Duplicate Coupon ID has conflicting provider facts.');
                }
                $items[$row['coupon_id']] = $row;
                if ($row['normalized_code'] !== '') {
                    $codeIds[$row['normalized_code']][$row['coupon_id']] = true;
                }
            }
            $next = $response->data['next_cursor'] ?? null;
            if ($next !== null && $next !== '' && ! is_string($next)) {
                throw new UnexpectedValueException('Coupon snapshot next_cursor must be string or null.');
            }
            $cursor = $next === '' ? null : $next;
        } while ($cursor !== null);

        $conflictCodes = array_keys(array_filter($codeIds, fn (array $ids): bool => count($ids) > 1));
        $couponClass = self::COUPON;
        $accountClass = self::ACCOUNT;
        $seen = array_keys($items);
        $markedMissing = DB::transaction(function () use ($items, $seen, $connectionKey, $couponClass, $accountClass, $conflictCodes): int {
            $now = now();
            $accountRows = $accountClass::query()
                ->where('connection_key', $connectionKey)
                ->whereNotNull('affiliate_id')
                ->get(['affiliate_id', 'kol_id']);
            $accountsByAffiliate = $accountRows->keyBy('affiliate_id');
            $accountsByKol = $accountRows->whereNotNull('kol_id')->keyBy('kol_id');

            foreach ($items as $couponId => $fields) {
                $account = $fields['affiliate_id'] ? $accountsByAffiliate->get($fields['affiliate_id']) : null;
                $existing = $couponClass::query()
                    ->where('connection_key', $connectionKey)
                    ->where('coupon_id', $couponId)
                    ->first();
                if (! $existing && $fields['normalized_code'] !== '' && ! in_array($fields['normalized_code'], $conflictCodes, true)) {
                    $existing = $couponClass::query()
                        ->where('connection_key', $connectionKey)
                        ->where('normalized_code', $fields['normalized_code'])
                        ->whereNull('coupon_id')
                        ->where('origin', 'erp_provisioned')
                        ->first();
                }

                $kolId = $account?->kol_id;
                $reconciliation = $fields['normalized_code'] === ''
                    ? 'provider_invalid_code'
                    : (in_array($fields['normalized_code'], $conflictCodes, true)
                        ? 'provider_code_conflict'
                        : (! in_array($fields['discount_type'], ['percent', 'fixed_cart', 'fixed_product'], true)
                            ? 'provider_unsupported_discount_type'
                            : 'present'));
                if ($existing && $existing->origin === 'erp_provisioned' && $existing->kol_id !== null) {
                    $kolId = (int) $existing->kol_id;
                    $expected = $accountsByKol->get($existing->kol_id);
                    $expectedAffiliateId = $expected?->affiliate_id === null ? null : (int) $expected->affiliate_id;
                    if ($reconciliation === 'present' && ($expectedAffiliateId === null || $fields['affiliate_id'] !== $expectedAffiliateId)) {
                        $reconciliation = 'provider_binding_mismatch';
                    }
                }

                $provider = array_merge($fields, [
                    'kol_id' => $kolId,
                    'sync_status' => 'synced',
                    'reconciliation_status' => $reconciliation,
                    'last_synced_at' => $now,
                    'last_successful_sync_at' => $now,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);
                if ($existing) {
                    $existing->update($provider);
                } else {
                    $couponClass::create(array_merge($provider, [
                        'connection_key' => $connectionKey,
                        'origin' => 'external_sync',
                        'provisioning_status' => 'not_requested',
                    ]));
                }
            }

            $missing = $couponClass::query()->where('connection_key', $connectionKey)->whereNotNull('coupon_id');
            if ($seen !== []) {
                $missing->whereNotIn('coupon_id', $seen);
            }
            return $missing->update(['reconciliation_status' => 'missing_from_latest_snapshot', 'last_synced_at' => $now]);
        });

        return ['synced' => count($seen), 'marked_missing' => $markedMissing, 'errors' => [], 'provider_references' => array_map(static fn (int $id): string => (string) $id, $seen)];
    }

    /** @return list<array<string,mixed>> */
    public function workspaceRows(string $connectionKey): array
    {
        $couponClass = self::COUPON;
        return $couponClass::query()->where('connection_key', $connectionKey)->with('kol:id,display_name')
            ->orderBy('normalized_code')->orderBy('coupon_id')->get()->map(fn ($row): array => [
                'id' => $row->id,
                'coupon_id' => $row->coupon_id,
                'code' => $row->code,
                'normalized_code' => $row->normalized_code,
                'discount_type' => $row->discount_type,
                'amount' => $row->amount,
                'provider_status' => $row->provider_status,
                'date_expires' => $row->date_expires?->toIso8601String(),
                'affiliate_id' => $row->affiliate_id,
                'kol' => $row->kol ? ['id' => $row->kol->id, 'display_name' => $row->kol->display_name] : null,
                'origin' => $row->origin,
                'sync_status' => $row->sync_status,
                'reconciliation_status' => $row->reconciliation_status,
                'provisioning_status' => $row->provisioning_status,
                'last_error_code' => $row->last_error_code,
                'last_error_message' => $row->last_error_message,
                'last_successful_sync_at' => $row->last_successful_sync_at?->toIso8601String(),
            ])->all();
    }

    /** @return array<string,mixed> */
    private function normalizeItem(mixed $item): array
    {
        if (! is_array($item)) {
            throw new UnexpectedValueException('Coupon snapshot item must be an object.');
        }
        $couponId = $this->positiveInt($item['coupon_id'] ?? null, 'coupon_id');
        $code = $this->providerString($item['code'] ?? null, 255, 'code');
        $normalized = $this->normalizeProviderCode($item['normalized_code'] ?? $code);
        $discountType = $this->requiredString($item['discount_type'] ?? null, 32, 'discount_type');
        $amount = $this->canonicalDecimal($item['amount'] ?? null);
        $status = $this->requiredString($item['status'] ?? null, 32, 'status');
        $affiliate = ($item['linked_affiliate_id'] ?? null) === null ? null : $this->positiveInt($item['linked_affiliate_id'], 'linked_affiliate_id');
        $expiry = $item['date_expires'] ?? null;
        if ($expiry !== null && ! is_string($expiry)) {
            throw new UnexpectedValueException('Coupon snapshot date_expires must be string or null.');
        }
        return [
            'coupon_id' => $couponId, 'code' => $code, 'normalized_code' => $normalized,
            'discount_type' => $discountType, 'amount' => $amount, 'provider_status' => $status,
            'date_expires' => $expiry ?: null, 'affiliate_id' => $affiliate,
        ];
    }


    private function normalizeProviderCode(mixed $value): string
    {
        return mb_strtolower(trim($this->providerString($value, 255, 'normalized_code')));
    }

    private function providerString(mixed $value, int $max, string $field): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            throw new UnexpectedValueException("Coupon snapshot {$field} must be scalar text.");
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > $max) {
            throw new UnexpectedValueException("Coupon snapshot {$field} is invalid.");
        }
        return $value;
    }

    private function normalizeCode(mixed $value): string
    {
        $code = mb_strtolower(trim($this->requiredString($value, 255, 'normalized_code')));
        if ($code === '') {
            throw new UnexpectedValueException('Coupon normalized_code is required.');
        }
        return $code;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0) {
            return (int) $value;
        }
        throw new UnexpectedValueException("Coupon snapshot {$field} must be a positive integer.");
    }

    private function requiredString(mixed $value, int $max, string $field): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            throw new UnexpectedValueException("Coupon snapshot {$field} must be scalar text.");
        }
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new UnexpectedValueException("Coupon snapshot {$field} is invalid.");
        }
        return $value;
    }

    private function canonicalDecimal(mixed $value): string
    {
        $value = is_int($value) || is_float($value) || is_string($value) ? (string) $value : '';
        if (! preg_match('/^(?:0|[1-9]\\d*)(?:\\.\\d{1,6})?$/', $value)) {
            throw new UnexpectedValueException('Coupon amount must be a non-negative decimal.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = rtrim($fraction, '0');
        return ltrim($whole, '0') === '' ? ('0'.($fraction !== '' ? '.'.$fraction : '')) : ltrim($whole, '0').($fraction !== '' ? '.'.$fraction : '');
    }
}
