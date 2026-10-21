<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class StalePaymentReconciliationPreviewService
{
    /** @return array<string,mixed> */
    public function preview(CarbonImmutable $asOf, int $detailLimit = 100): array
    {
        $maxScan = max(1, (int) config('payment_reconciliation.max_scan', 10000));
        $maxDetail = max(1, (int) config('payment_reconciliation.max_detail_limit', 1000));
        $detailLimit = max(1, min($detailLimit, $maxDetail));

        $orders = $this->candidateQuery()
            ->orderBy('id')
            ->limit($maxScan + 1)
            ->get();

        $truncated = $orders->count() > $maxScan;
        if ($truncated) {
            $orders = $orders->take($maxScan);
        }

        $rows = $orders
            ->map(fn (Order $order): array => $this->classify($order, $asOf))
            ->values();

        return [
            'as_of' => $asOf->utc()->toIso8601String(),
            'candidate_count' => $rows->count(),
            'scan_limit' => $maxScan,
            'scan_truncated' => $truncated,
            'classification_counts' => $rows
                ->countBy('classification')
                ->sortKeys()
                ->all(),
            'payment_method_counts' => $this->paymentMethodCounts($rows),
            'details' => $rows->take($detailLimit)->all(),
            'detail_limit' => $detailLimit,
        ];
    }

    /** @return array<string,mixed> */
    public function classify(Order $order, CarbonImmutable $asOf): array
    {
        $order->loadMissing(['financials', 'externalReferences']);
        $financials = $order->financials;
        $status = strtolower(trim((string) ($financials?->provider_order_status ?? '')));
        $paymentMethod = strtolower(trim((string) ($financials?->payment_method ?? '')));

        $classification = StalePaymentClassification::NotCandidate;
        $ageHours = null;
        $thresholdHours = $this->thresholdHours($paymentMethod);

        if ($this->isWooCustomerOrder($order) && in_array($status, ['pending', 'on-hold'], true)) {
            if ($financials?->provider_modified_at === null) {
                $classification = StalePaymentClassification::MissingTimestampReview;
            } else {
                $modifiedAt = CarbonImmutable::instance($financials->provider_modified_at)->utc();
                $ageHours = max(0.0, ($asOf->utc()->getTimestamp() - $modifiedAt->getTimestamp()) / 3600);

                if ($status === 'on-hold') {
                    $classification = StalePaymentClassification::HoldReview;
                } elseif ($financials->paid_at !== null || $this->hasTransactionIdentity($financials->payment_transaction_id)) {
                    $classification = StalePaymentClassification::PendingEvidenceConflict;
                } elseif ($ageHours >= $thresholdHours) {
                    $classification = StalePaymentClassification::StaleUnpaid;
                } else {
                    $classification = StalePaymentClassification::RecentPending;
                }
            }
        }

        return [
            'order_id' => (int) $order->getKey(),
            'erp_order_id' => (string) ($order->erp_order_id ?? ''),
            'provider_reference' => (string) ($financials?->provider_reference ?? ''),
            'payment_method' => $paymentMethod !== '' ? $paymentMethod : 'unknown',
            'provider_status' => $status,
            'classification' => $classification->value,
            'provider_modified_at' => $financials?->provider_modified_at?->utc()->toIso8601String(),
            'age_hours' => $ageHours === null ? null : round($ageHours, 2),
            'threshold_hours' => $thresholdHours,
            'has_paid_at' => $financials?->paid_at !== null,
            'has_transaction_id' => $this->hasTransactionIdentity($financials?->payment_transaction_id),
        ];
    }

    private function candidateQuery()
    {
        return Order::query()
            ->with(['financials', 'externalReferences'])
            ->where('order_kind', OrderKind::Customer->value)
            ->where(function ($query): void {
                $query->where('source_type', OrderSourceType::WooCommerce->value)
                    ->orWhereHas('externalReferences', fn ($references) => $references->where('provider', 'woocommerce'));
            })
            ->whereHas('financials', fn ($financials) => $financials->whereIn('provider_order_status', ['pending', 'on-hold']));
    }

    /** @param Collection<int,array<string,mixed>> $rows */
    private function paymentMethodCounts(Collection $rows): array
    {
        return $rows
            ->groupBy('payment_method')
            ->map(fn (Collection $methodRows): array => [
                'total' => $methodRows->count(),
                'classifications' => $methodRows->countBy('classification')->sortKeys()->all(),
            ])
            ->sortKeys()
            ->all();
    }

    private function thresholdHours(string $paymentMethod): int
    {
        $thresholds = (array) config('payment_reconciliation.pending_threshold_hours', []);
        $default = max(1, (int) ($thresholds['default'] ?? 24));

        return max(1, (int) ($thresholds[$paymentMethod] ?? $default));
    }

    private function hasTransactionIdentity(?string $transactionId): bool
    {
        return trim((string) $transactionId) !== '';
    }

    private function isWooCustomerOrder(Order $order): bool
    {
        $kind = $order->order_kind instanceof \BackedEnum
            ? $order->order_kind->value
            : (string) $order->order_kind;
        if ($kind !== OrderKind::Customer->value) {
            return false;
        }

        $source = $order->source_type instanceof \BackedEnum
            ? $order->source_type->value
            : (string) $order->source_type;

        return $source === OrderSourceType::WooCommerce->value
            || $order->externalReferences->contains(
                fn ($reference): bool => (string) $reference->provider === 'woocommerce',
            );
    }
}
