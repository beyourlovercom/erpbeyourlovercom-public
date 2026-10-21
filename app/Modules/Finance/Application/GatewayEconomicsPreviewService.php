<?php

namespace App\Modules\Finance\Application;

use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class GatewayEconomicsPreviewService
{
    /** @return array<string,mixed> */
    public function preview(CarbonImmutable $asOf, int $days = 30): array
    {
        $days = max(1, min($days, 365));
        $windowEnd = $asOf->utc()->endOfDay();
        $windowStart = $windowEnd->startOfDay()->subDays($days - 1);
        $maxScan = max(1, (int) config('gateway_economics.max_scan', 50000));

        $orders = $this->wooCustomerQuery()
            ->with('financials')
            ->whereDate('order_date', '>=', $windowStart->toDateString())
            ->whereDate('order_date', '<=', $windowEnd->toDateString())
            ->orderBy('id')
            ->limit($maxScan + 1)
            ->get();

        $truncated = $orders->count() > $maxScan;
        if ($truncated) {
            $orders = $orders->take($maxScan);
        }

        $rows = $orders
            ->groupBy(fn (Order $order): string => $this->groupKey($order))
            ->map(fn (Collection $group): array => $this->aggregate($group))
            ->sortBy(fn (array $row): string => $row['payment_method'].'|'.$row['currency'])
            ->values()
            ->all();

        return [
            'as_of' => $asOf->utc()->toIso8601String(),
            'window' => [
                'days' => $days,
                'start_date' => $windowStart->toDateString(),
                'end_date' => $windowEnd->toDateString(),
            ],
            'coverage' => [
                'raw_provider_attempts' => 'unavailable',
                'webhook_event_store' => 'unavailable',
                'dispute_chargeback_cost' => 'unavailable',
                'airwallex_actual_processor_fee' => 'unavailable',
                'payment_orders_are_not_raw_attempts' => true,
                'accounting_3_5pct_is_actual_gateway_fee' => false,
                'cross_currency_amount_total' => 'prohibited',
            ],
            'scan_limit' => $maxScan,
            'scan_truncated' => $truncated,
            'rows' => $rows,
        ];
    }

    /** @param Collection<int,Order> $orders @return array<string,mixed> */
    private function aggregate(Collection $orders): array
    {
        /** @var Order $first */
        $first = $orders->first();
        $financials = $first->financials;
        $paymentMethod = strtolower(trim((string) ($financials?->payment_method ?? ''))) ?: 'unknown';
        $currency = strtoupper(trim((string) ($financials?->currency ?? $first->currency ?? 'USD'))) ?: 'USD';

        $paymentOrders = $orders->count();
        $capturedOrders = 0;
        $grossEvidenceOrders = 0;
        $refundEvidenceOrders = 0;
        $grossCents = 0;
        $refundCents = 0;
        $actualFeeObservedOrders = 0;
        $actualFeeEvidenceGrossCents = 0;
        $actualFeeCents = 0;
        $feeStatuses = [];

        foreach ($orders as $order) {
            $f = $order->financials;
            if ($f === null || $f->paid_at === null) {
                continue;
            }

            $capturedOrders++;

            $gross = $this->nullableMoneyCents($f->provider_order_total);
            if ($gross !== null) {
                $grossEvidenceOrders++;
                $grossCents += $gross;
            }

            $refund = $this->nullableMoneyCents($f->refund_amount);
            if ($refund !== null) {
                $refundEvidenceOrders++;
                $refundCents += $refund;
            }

            $status = trim((string) ($f->actual_payment_fee_status ?? ''));
            $status = $status !== '' ? $status : 'not_imported_yet';
            $feeStatuses[$status] = ($feeStatuses[$status] ?? 0) + 1;

            $actualFee = $this->nullableMoneyCents($f->actual_payment_fee);
            if ($status === 'observed' && $actualFee !== null) {
                $actualFeeObservedOrders++;
                $actualFeeCents += $actualFee;
                if ($gross !== null) {
                    $actualFeeEvidenceGrossCents += $gross;
                }
            }
        }

        ksort($feeStatuses);
        $actualFeeCoverage = $capturedOrders === 0 ? 0.0 : $actualFeeObservedOrders / $capturedOrders;
        $grossCoverage = $capturedOrders === 0 ? 0.0 : $grossEvidenceOrders / $capturedOrders;
        $refundCoverage = $capturedOrders === 0 ? 0.0 : $refundEvidenceOrders / $capturedOrders;
        $completeEvidence = $capturedOrders > 0
            && $actualFeeObservedOrders === $capturedOrders
            && $grossEvidenceOrders === $capturedOrders
            && $refundEvidenceOrders === $capturedOrders;

        $accountingFeeEstimateCents = $this->rateCents(
            $grossCents,
            (string) config('gateway_economics.accounting_payment_fee_rate', '0.03500000'),
        );

        return [
            'payment_method' => $paymentMethod,
            'currency' => $currency,
            'payment_orders' => $paymentOrders,
            'captured_orders' => $capturedOrders,
            'created_to_captured_rate' => $paymentOrders === 0 ? 0.0 : round($capturedOrders / $paymentOrders, 4),
            'gross_evidence_orders' => $grossEvidenceOrders,
            'gross_evidence_coverage' => round($grossCoverage, 4),
            'captured_gross' => $this->formatCents($grossCents),
            'refund_evidence_orders' => $refundEvidenceOrders,
            'refund_evidence_coverage' => round($refundCoverage, 4),
            'refund_amount' => $this->formatCents($refundCents),
            'refund_amount_rate' => $grossCents <= 0 ? 0.0 : round($refundCents / $grossCents, 4),
            'actual_fee_observed_orders' => $actualFeeObservedOrders,
            'actual_fee_coverage' => round($actualFeeCoverage, 4),
            'actual_fee_evidence_gross' => $this->formatCents($actualFeeEvidenceGrossCents),
            'actual_fee_total' => $this->formatCents($actualFeeCents),
            'actual_fee_effective_rate' => $actualFeeEvidenceGrossCents <= 0
                ? null
                : round($actualFeeCents / $actualFeeEvidenceGrossCents, 4),
            'accounting_fee_estimate_3_5pct' => $this->formatCents($accountingFeeEstimateCents),
            'net_after_refund_before_fee' => $this->formatCents($grossCents - $refundCents),
            'net_after_refund_and_actual_fee' => $completeEvidence
                ? $this->formatCents($grossCents - $refundCents - $actualFeeCents)
                : null,
            'complete_actual_net_evidence' => $completeEvidence,
            'fee_evidence_status_counts' => $feeStatuses,
        ];
    }

    private function groupKey(Order $order): string
    {
        $financials = $order->financials;
        $method = strtolower(trim((string) ($financials?->payment_method ?? ''))) ?: 'unknown';
        $currency = strtoupper(trim((string) ($financials?->currency ?? $order->currency ?? 'USD'))) ?: 'USD';

        return $method.'|'.$currency;
    }

    /** @return Builder<Order> */
    private function wooCustomerQuery(): Builder
    {
        return Order::query()
            ->where('order_kind', OrderKind::Customer->value)
            ->where(function (Builder $query): void {
                $query->where('source_type', OrderSourceType::WooCommerce->value)
                    ->orWhereHas('externalReferences', fn ($references) => $references->where('provider', 'woocommerce'));
            });
    }

    private function nullableMoneyCents(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->moneyCents($value);
    }

    private function moneyCents(mixed $value): int
    {
        $value = trim((string) $value);
        if (! preg_match('/^(?<sign>-)?(?<whole>\d{1,16})(?:\.(?<fraction>\d{1,6}))?$/D', $value, $matches)) {
            throw new InvalidArgumentException('Gateway economics money value is not a supported decimal.');
        }

        $fraction = str_pad($matches['fraction'] ?? '', 3, '0');
        $cents = ((int) $matches['whole'] * 100) + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return ($matches['sign'] ?? '') === '-' ? -$cents : $cents;
    }

    private function rateCents(int $basisCents, string $rate): int
    {
        if (! preg_match('/^0\.0*35(?:0*)$/D', $rate) && $rate !== '0.03500000') {
            $rate = '0.03500000';
        }

        return (int) round($basisCents * 0.035, 0, PHP_ROUND_HALF_UP);
    }

    private function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
