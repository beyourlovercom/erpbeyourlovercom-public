<?php

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Finance\Infrastructure\Models\OrderCostEntry;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;

final class PaymentFeeLegacyFxRepairService
{
    private const SOURCE_REFERENCE = 'owner-rule:payment-fee:v1:provider-order-total:3.5pct';

    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array<string,mixed> */
    public function preview(?int $orderId = null, int $limit = 10000): array
    {
        $orders = $this->eligibleOrders($orderId, $limit)->get();
        $candidates = [];
        foreach ($orders as $order) {
            $candidate = $this->candidate($order);
            if ($candidate !== null) $candidates[] = $candidate;
        }

        $currencies = [];
        foreach ($candidates as $candidate) {
            $currency = (string) $candidate['currency'];
            $currencies[$currency] = ($currencies[$currency] ?? 0) + 1;
        }
        ksort($currencies);

        return [
            'mode' => 'dry_run',
            'seen' => $orders->count(),
            'candidates' => count($candidates),
            'base_changes' => count(array_filter($candidates, fn (array $row): bool => $row['base_amount_changed'])),
            'currencies' => $currencies,
            'rows' => $candidates,
        ];
    }

    /** @return array<string,mixed> */
    public function apply(?int $orderId = null, int $limit = 10000): array
    {
        $preview = $this->preview($orderId, $limit);
        $applied = 0;
        $noop = 0;
        foreach ($preview['rows'] as $row) {
            DB::transaction(function () use ($row, &$applied, &$noop): void {
                $order = Order::query()->lockForUpdate()->findOrFail((int) $row['order_id']);
                $candidate = $this->candidate($order, lock: true);
                if ($candidate === null) {
                    $noop++;
                    return;
                }

                $entry = OrderCostEntry::query()->lockForUpdate()->findOrFail((int) $candidate['entry_id']);
                $before = $this->entryFx($entry);
                $entry->forceFill($candidate['after'])->save();
                $after = $this->entryFx($entry->fresh());

                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.payment_fee_fx_metadata.repaired',
                    $entry::class,
                    $entry->getKey(),
                    before: $before,
                    after: $after + ['order_id' => (int) $order->getKey()],
                    reason: 'payment_fee_fx_legacy_repair',
                );
                $applied++;
            });
        }

        return [
            'mode' => 'apply',
            'seen' => $preview['seen'],
            'candidates' => $preview['candidates'],
            'base_changes' => $preview['base_changes'],
            'applied' => $applied,
            'noop' => $noop,
            'currencies' => $preview['currencies'],
        ];
    }

    private function eligibleOrders(?int $orderId, int $limit)
    {
        $query = Order::query()
            ->where('order_kind', 'customer')
            ->whereDoesntHave('externalReferences', fn ($refs) => $refs->where('provider', 'historical_logistics'))
            ->whereHas('financials', fn ($q) => $q
                ->where('currency', '<>', 'USD')
                ->whereNotNull('fx_rate')->whereNotNull('fx_rate_date')->whereNotNull('fx_rate_source'))
            ->whereHas('costEntries', fn ($q) => $q
                ->where('cost_type', 'payment_fee')
                ->where('provenance', 'confirmed_manual')
                ->where('status', 'confirmed')
                ->where('source_reference', self::SOURCE_REFERENCE))
            ->orderBy('id');

        if ($orderId !== null) return $query->whereKey($orderId);
        return $query->limit(max(1, min(10000, $limit)));
    }

    /** @return array<string,mixed>|null */
    private function candidate(Order $order, bool $lock = false): ?array
    {
        $financialsQuery = $order->financials();
        $entryQuery = $order->costEntries()->where('cost_type', 'payment_fee');
        if ($lock) {
            $financialsQuery->lockForUpdate();
            $entryQuery->lockForUpdate();
        }
        $financials = $financialsQuery->first();
        $entry = $entryQuery->first();
        if ($financials === null || $entry === null) return null;
        if ((string) $entry->provenance !== 'confirmed_manual' || (string) $entry->status !== 'confirmed') return null;
        if ((string) $entry->source_reference !== self::SOURCE_REFERENCE) return null;
        if (strtoupper((string) $entry->currency) === 'USD') return null;
        if ($financials->fx_rate === null || $financials->fx_rate_date === null || trim((string) $financials->fx_rate_source) === '') return null;

        $rate = number_format((float) $financials->fx_rate, 8, '.', '');
        if (bccomp($rate, '0', 8) <= 0) return null;
        $date = $financials->fx_rate_date->toDateString();
        $source = (string) $financials->fx_rate_source;
        $baseAmount = DecimalMoney::round(DecimalMoney::multiply((string) $entry->amount, $rate, 8));
        $after = [
            'exchange_rate' => $rate,
            'exchange_rate_date' => $date,
            'exchange_rate_source' => $source,
            'base_amount' => $baseAmount,
            'base_currency' => 'USD',
        ];
        $before = $this->entryFx($entry);
        if ($this->matches($before, $after)) return null;

        return [
            'order_id' => (int) $order->getKey(),
            'entry_id' => (int) $entry->getKey(),
            'currency' => strtoupper((string) $entry->currency),
            'amount' => (string) $entry->amount,
            'base_amount_changed' => (string) ($entry->base_amount ?? '') !== $baseAmount,
            'before' => $before,
            'after' => $after,
        ];
    }

    /** @return array<string,mixed> */
    private function entryFx(OrderCostEntry $entry): array
    {
        return [
            'exchange_rate' => $entry->exchange_rate === null ? null : (string) $entry->exchange_rate,
            'exchange_rate_date' => $entry->exchange_rate_date?->toDateString(),
            'exchange_rate_source' => $entry->exchange_rate_source,
            'base_amount' => $entry->base_amount === null ? null : (string) $entry->base_amount,
            'base_currency' => $entry->base_currency,
        ];
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function matches(array $left, array $right): bool
    {
        foreach ($right as $key => $value) {
            if ((string) ($left[$key] ?? '') !== (string) $value) return false;
        }
        return true;
    }
}
