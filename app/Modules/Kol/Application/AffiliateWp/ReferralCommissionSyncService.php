<?php

namespace App\Modules\Kol\Application\AffiliateWp;

use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Application\KolScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

final class ReferralCommissionSyncService
{
    private const REFERRAL = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliateReferral';
    private const ACCOUNT = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliateAccount';
    private const ORDER_REFERENCE = 'App\\Modules\\Orders\\Infrastructure\\Models\\OrderExternalReference';

    public function __construct(private readonly AffiliateWpGateway $gateway) {}

    /** @return array{synced:int,marked_missing:int,affiliate_unresolved:int,order_unresolved:int,provider_references:list<string>} */
    public function sync(string $connectionKey, Authenticatable $actor): array
    {
        if (! $actor->can('kol.affiliates.manage')) {
            throw new AuthorizationException;
        }

        $items = $this->fetchCompleteSnapshot($connectionKey);
        $referralClass = self::REFERRAL;
        $accountClass = self::ACCOUNT;
        $orderReferenceClass = self::ORDER_REFERENCE;

        return DB::transaction(function () use ($items, $connectionKey, $referralClass, $accountClass, $orderReferenceClass): array {
            $now = now();
            $affiliateIds = array_values(array_unique(array_column($items, 'affiliate_id')));
            $accounts = $accountClass::query()
                ->where('connection_key', $connectionKey)
                ->whereIn('affiliate_id', $affiliateIds)
                ->get(['affiliate_id', 'kol_id'])
                ->keyBy('affiliate_id');

            $references = array_values(array_unique(array_filter(array_column($items, 'order_reference'), fn ($v): bool => $v !== '')));
            $orderReferences = collect();
            foreach (array_chunk($references, 500) as $chunk) {
                $orderReferences = $orderReferences->concat(
                    $orderReferenceClass::query()
                        ->where('provider', 'woocommerce')
                        ->where('connection_key', $connectionKey)
                        ->whereIn('reference', $chunk)
                        ->get(['reference', 'order_id'])
                );
            }
            $ordersByReference = $orderReferences->keyBy(fn ($row): string => (string) $row->reference);

            $existing = $referralClass::query()->where('connection_key', $connectionKey)->get()->keyBy('referral_id');
            $seen = [];
            $affiliateUnresolved = 0;
            $orderUnresolved = 0;

            foreach ($items as $fields) {
                $referralId = $fields['referral_id'];
                $seen[$referralId] = true;
                $account = $accounts->get($fields['affiliate_id']);
                $kolId = $account?->kol_id === null ? null : (int) $account->kol_id;
                $affiliateResolution = $account === null ? 'missing' : ($kolId === null ? 'unbound' : 'bound');
                if ($affiliateResolution !== 'bound') {
                    $affiliateUnresolved++;
                }

                $orderRef = $ordersByReference->get($fields['order_reference']);
                $orderId = $orderRef?->order_id === null ? null : (int) $orderRef->order_id;
                $orderResolution = $orderId === null ? 'unresolved' : 'resolved';
                if ($orderResolution === 'unresolved') {
                    $orderUnresolved++;
                }

                $provider = array_merge($fields, [
                    'kol_id' => $kolId,
                    'order_id' => $orderId,
                    'affiliate_resolution_status' => $affiliateResolution,
                    'order_resolution_status' => $orderResolution,
                    'reconciliation_status' => 'present',
                    'sync_status' => 'synced',
                    'last_synced_at' => $now,
                    'last_successful_sync_at' => $now,
                ]);

                $row = $existing->get($referralId);
                if ($row) {
                    $row->update($provider);
                } else {
                    $referralClass::create(array_merge(['connection_key' => $connectionKey], $provider));
                }
            }

            $missingIds = $existing->keys()->filter(fn ($id): bool => ! isset($seen[(int) $id]))->values()->all();
            $markedMissing = 0;
            foreach (array_chunk($missingIds, 500) as $chunk) {
                $markedMissing += $referralClass::query()
                    ->where('connection_key', $connectionKey)
                    ->whereIn('referral_id', $chunk)
                    ->update(['reconciliation_status' => 'missing_from_latest_snapshot', 'last_synced_at' => $now]);
            }

            return [
                'synced' => count($items),
                'marked_missing' => $markedMissing,
                'affiliate_unresolved' => $affiliateUnresolved,
                'order_unresolved' => $orderUnresolved,
                'provider_references' => array_map(static fn (array $item): string => (string) $item['referral_id'], $items),
            ];
        });
    }

    /** @return array{rows:list<array<string,mixed>>,summary:array<string,mixed>} */
    public function workspace(string $connectionKey, Authenticatable $actor): array
    {
        $referralClass = self::REFERRAL;
        $query = $referralClass::query()
            ->where('connection_key', $connectionKey)
            ->with(['kol:id,display_name,owner_id', 'order:id,erp_order_id,internal_order_number,external_order_number']);

        if (! KolScope::isAllScope($actor)) {
            $ownerId = (int) $actor->getAuthIdentifier();
            $query->whereHas('kol', fn ($kol) => $kol->where('owner_id', $ownerId));
        }

        $models = $query->orderByDesc('referral_date')->orderByDesc('referral_id')->get();
        $byStatus = [];
        $total = 0.0;
        foreach ($models as $row) {
            $amount = (float) $row->commission_amount;
            $total += $amount;
            $status = (string) $row->provider_status;
            $byStatus[$status] ??= ['count' => 0, 'amount' => 0.0];
            $byStatus[$status]['count']++;
            $byStatus[$status]['amount'] += $amount;
        }
        foreach ($byStatus as &$bucket) {
            $bucket['amount'] = number_format($bucket['amount'], 2, '.', '');
        }
        unset($bucket);

        $rows = $models->map(fn ($row): array => [
            'id' => $row->id,
            'referral_id' => $row->referral_id,
            'affiliate_id' => $row->affiliate_id,
            'kol' => $row->kol ? ['id' => $row->kol->id, 'display_name' => $row->kol->display_name] : null,
            'order' => $row->order ? [
                'id' => $row->order->id,
                'erp_order_id' => $row->order->erp_order_id,
                'internal_order_number' => $row->order->internal_order_number,
                'external_order_number' => $row->order->external_order_number,
            ] : null,
            'order_reference' => $row->order_reference,
            'commission_amount' => (string) $row->commission_amount,
            'currency' => $row->currency,
            'provider_status' => $row->provider_status,
            'context' => $row->context,
            'referral_date' => $row->referral_date?->toIso8601String(),
            'payout_id' => $row->payout_id,
            'affiliate_resolution_status' => $row->affiliate_resolution_status,
            'order_resolution_status' => $row->order_resolution_status,
            'reconciliation_status' => $row->reconciliation_status,
        ])->all();

        $currencies = $models->pluck('currency')->filter()->unique()->values();
        return [
            'rows' => $rows,
            'summary' => [
                'referrals' => $models->count(),
                'commission_amount' => number_format($total, 2, '.', ''),
                'currency' => $currencies->count() === 1 ? $currencies->first() : ($currencies->isEmpty() ? null : 'MULTI'),
                'by_status' => $byStatus,
                'affiliate_unresolved' => $models->where('affiliate_resolution_status', '!=', 'bound')->count(),
                'order_unresolved' => $models->where('order_resolution_status', '!=', 'resolved')->count(),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function fetchCompleteSnapshot(string $connectionKey): array
    {
        $items = [];
        $cursor = null;
        $visited = [];
        do {
            if ($cursor !== null) {
                if (isset($visited[$cursor])) {
                    throw new UnexpectedValueException('Referral snapshot cursor loop detected.');
                }
                $visited[$cursor] = true;
            }
            $response = $this->gateway->snapshot('referrals', $cursor, null);
            if (! $response->ok || $response->connectionKey !== $connectionKey || ! is_array($response->data)) {
                throw new UnexpectedValueException('Referral snapshot response failed validation.');
            }
            $page = $response->data['items'] ?? null;
            if (! is_array($page)) {
                throw new UnexpectedValueException('Referral snapshot data.items is required.');
            }
            foreach ($page as $item) {
                $row = $this->normalizeItem($item);
                $id = $row['referral_id'];
                if (isset($items[$id]) && $items[$id] !== $row) {
                    throw new UnexpectedValueException('Duplicate Referral ID has conflicting provider facts.');
                }
                $items[$id] = $row;
            }
            $next = $response->data['next_cursor'] ?? null;
            if ($next !== null && $next !== '' && ! is_string($next)) {
                throw new UnexpectedValueException('Referral snapshot next_cursor must be string or null.');
            }
            $cursor = $next === '' ? null : $next;
        } while ($cursor !== null);

        return array_values($items);
    }

    /** @return array<string,mixed> */
    private function normalizeItem(mixed $item): array
    {
        if (! is_array($item)) {
            throw new UnexpectedValueException('Referral snapshot item must be an object.');
        }
        $date = $this->requiredString($item['referred_at'] ?? $item['date'] ?? null, 64, 'referred_at');
        try {
            $referralDate = CarbonImmutable::parse($date);
        } catch (Throwable) {
            throw new UnexpectedValueException('Referral snapshot date is invalid.');
        }
        $payout = $item['payout_id'] ?? null;
        if ($payout === 0 || $payout === '0' || $payout === '') {
            $payout = null;
        } elseif ($payout !== null) {
            $payout = $this->positiveInt($payout, 'payout_id');
        }

        return [
            'referral_id' => $this->positiveInt($item['referral_id'] ?? null, 'referral_id'),
            'affiliate_id' => $this->positiveInt($item['affiliate_id'] ?? null, 'affiliate_id'),
            'order_reference' => $this->scalarString($item['reference'] ?? null, 255, 'reference'),
            'description' => $this->scalarString($item['description'] ?? null, 65535, 'description'),
            'commission_amount' => $this->canonicalDecimal($item['amount'] ?? null),
            'currency' => $this->requiredString($item['currency'] ?? null, 8, 'currency'),
            'provider_status' => $this->requiredString($item['status'] ?? null, 32, 'status'),
            'context' => $this->requiredString($item['context'] ?? null, 64, 'context'),
            'referral_date' => $referralDate,
            'payout_id' => $payout,
        ];
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0) {
            return (int) $value;
        }
        throw new UnexpectedValueException("Referral snapshot {$field} must be a positive integer.");
    }

    private function scalarString(mixed $value, int $max, string $field): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            throw new UnexpectedValueException("Referral snapshot {$field} must be scalar text.");
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > $max) {
            throw new UnexpectedValueException("Referral snapshot {$field} is too long.");
        }
        return $value;
    }

    private function requiredString(mixed $value, int $max, string $field): string
    {
        $value = $this->scalarString($value, $max, $field);
        if ($value === '') {
            throw new UnexpectedValueException("Referral snapshot {$field} is required.");
        }
        return $value;
    }

    private function canonicalDecimal(mixed $value): string
    {
        $value = is_int($value) || is_float($value) || is_string($value) ? trim((string) $value) : '';
        if (! preg_match('/^-?(?:0|[1-9]\\d{0,11})(?:\\.\\d{1,6})?$/', $value)) {
            throw new UnexpectedValueException('Referral amount must be a signed decimal with at most 6 decimals.');
        }
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($fraction, '0');
        $normalized = $whole.($fraction !== '' ? '.'.$fraction : '');
        return $negative && $normalized !== '0' ? '-'.$normalized : $normalized;
    }
}
