<?php

namespace App\Modules\Kol\Application\AffiliateWp;

use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

final class PayoutSnapshotSyncService
{
    private const PAYOUT = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliatePayout';
    private const ACCOUNT = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliateAccount';

    public function __construct(private readonly AffiliateWpGateway $gateway) {}

    /** @return array{synced:int,marked_missing:int,kol_unresolved:int,provider_references:list<string>} */
    public function sync(string $connectionKey, Authenticatable $actor): array
    {
        if (! $actor->can('kol.payouts.manage')) {
            throw new AuthorizationException;
        }
        $items = $this->fetchCompleteSnapshot();
        $payoutClass = self::PAYOUT;
        $accountClass = self::ACCOUNT;

        return DB::transaction(function () use ($items, $connectionKey, $payoutClass, $accountClass): array {
            $now = now();
            $affiliateIds = array_values(array_unique(array_column($items, 'affiliate_id')));
            $accounts = $accountClass::query()->where('connection_key', $connectionKey)->whereIn('affiliate_id', $affiliateIds)->get(['affiliate_id','kol_id'])->keyBy('affiliate_id');
            $existing = $payoutClass::query()->where('connection_key', $connectionKey)->get()->keyBy('payout_id');
            $seen = []; $unresolved = 0;
            foreach ($items as $fields) {
                $seen[$fields['payout_id']] = true;
                $kolId = $accounts->get($fields['affiliate_id'])?->kol_id;
                if ($kolId === null) { $unresolved++; }
                $provider = array_merge($fields, [
                    'kol_id' => $kolId === null ? null : (int) $kolId,
                    'reconciliation_status' => 'present',
                    'sync_status' => 'synced',
                    'last_synced_at' => $now,
                    'last_successful_sync_at' => $now,
                ]);
                $row = $existing->get($fields['payout_id']);
                $row ? $row->update($provider) : $payoutClass::create(array_merge(['connection_key'=>$connectionKey], $provider));
            }
            $missingIds = $existing->keys()->filter(fn ($id): bool => ! isset($seen[(int) $id]))->values()->all();
            $marked = 0;
            foreach (array_chunk($missingIds, 500) as $chunk) {
                $marked += $payoutClass::query()->where('connection_key',$connectionKey)->whereIn('payout_id',$chunk)->update(['reconciliation_status'=>'missing_from_latest_snapshot','last_synced_at'=>$now]);
            }
            return ['synced'=>count($items),'marked_missing'=>$marked,'kol_unresolved'=>$unresolved,'provider_references'=>array_map(static fn ($id): string => (string) $id, array_keys($seen))];
        });
    }

    /** @return list<array<string,mixed>> */
    private function fetchCompleteSnapshot(): array
    {
        $cursor = null; $items = [];
        do {
            $response = $this->gateway->snapshot('payouts', $cursor, null);
            $page = $response->data['items'] ?? null;
            if (! is_array($page)) { throw new UnexpectedValueException('Payout snapshot items must be an array.'); }
            foreach ($page as $item) {
                $row = $this->normalizeItem($item); $id = $row['payout_id'];
                if (isset($items[$id]) && $items[$id] !== $row) { throw new UnexpectedValueException('Duplicate Payout ID has conflicting provider facts.'); }
                $items[$id] = $row;
            }
            $next = $response->data['next_cursor'] ?? null;
            if ($next !== null && $next !== '' && ! is_string($next)) { throw new UnexpectedValueException('Payout snapshot next_cursor must be string or null.'); }
            $cursor = $next === '' ? null : $next;
        } while ($cursor !== null);
        return array_values($items);
    }

    /** @return array<string,mixed> */
    private function normalizeItem(mixed $item): array
    {
        if (! is_array($item)) { throw new UnexpectedValueException('Payout snapshot item must be an object.'); }
        $date = $this->requiredString($item['date'] ?? null, 64, 'date');
        try { $payoutDate = CarbonImmutable::parse($date); } catch (Throwable) { throw new UnexpectedValueException('Payout snapshot date is invalid.'); }
        $referrals = $item['referral_ids'] ?? null;
        if (! is_array($referrals)) { throw new UnexpectedValueException('Payout snapshot referral_ids must be an array.'); }
        $ids = [];
        foreach ($referrals as $id) { $ids[] = $this->positiveInt($id, 'referral_id'); }
        $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC);
        return [
            'payout_id'=>$this->positiveInt($item['payout_id']??null,'payout_id'),
            'affiliate_id'=>$this->positiveInt($item['affiliate_id']??null,'affiliate_id'),
            'referral_ids'=>$ids,
            'amount'=>$this->canonicalDecimal($item['amount']??null),
            'currency'=>strtoupper($this->requiredString($item['currency']??null,8,'currency')),
            'payout_method'=>$this->requiredString($item['payout_method']??null,32,'payout_method'),
            'provider_status'=>$this->requiredString($item['status']??null,32,'status'),
            'payout_date'=>$payoutDate,
        ];
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int)$value > 0) { return (int)$value; }
        throw new UnexpectedValueException("Payout snapshot {$field} must be a positive integer.");
    }

    private function requiredString(mixed $value, int $max, string $field): string
    {
        if (! is_string($value) && ! is_numeric($value)) { throw new UnexpectedValueException("Payout snapshot {$field} must be scalar text."); }
        $value = trim((string)$value);
        if ($value === '' || mb_strlen($value) > $max) { throw new UnexpectedValueException("Payout snapshot {$field} is invalid."); }
        return $value;
    }

    private function canonicalDecimal(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) { throw new UnexpectedValueException('Payout amount must be decimal text.'); }
        $value = trim((string)$value);
        if (! preg_match('/^(?:0|[1-9]\\d*)(?:\\.\\d{1,6})?$/', $value)) { throw new UnexpectedValueException('Payout amount is invalid.'); }
        [$whole,$fraction] = array_pad(explode('.',$value,2),2,''); $fraction = rtrim($fraction,'0');
        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }
}
