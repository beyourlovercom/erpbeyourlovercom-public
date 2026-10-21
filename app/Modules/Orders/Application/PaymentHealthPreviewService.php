<?php

namespace App\Modules\Orders\Application;

use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\SyncRunStatus;
use App\Modules\Sync\Infrastructure\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PaymentHealthPreviewService
{
    private const WOO_SYNC_RUN_TYPE = 'woocommerce_order_incremental';

    public function __construct(
        private readonly PaymentAuthorityService $paymentAuthority,
        private readonly StalePaymentReconciliationPreviewService $stalePayments,
    ) {}

    /** @return array<string,mixed> */
    public function preview(CarbonImmutable $asOf, int $days = 1, int $detailLimit = 50): array
    {
        $days = max(1, min($days, 30));
        $maxDetails = max(1, (int) config('payment_observability.max_anomaly_details', 200));
        $detailLimit = max(1, min($detailLimit, $maxDetails));

        $windowEnd = $asOf->utc()->endOfDay();
        $windowStart = $windowEnd->startOfDay()->subDays($days - 1);

        $cohort = $this->cohort($windowStart, $windowEnd);
        $stale = $this->stalePayments->preview($asOf, $detailLimit);
        $mismatches = $this->fulfillmentPaymentMismatches($detailLimit);
        $evidenceGaps = $this->paymentEvidenceGaps($detailLimit);
        $duplicates = $this->duplicateTransactionIdentity();
        $sync = $this->syncHealth($asOf);

        $backlogCounts = [
            'recent_pending' => (int) ($stale['classification_counts']['recent_pending'] ?? 0),
            'stale_unpaid' => (int) ($stale['classification_counts']['stale_unpaid'] ?? 0),
            'pending_evidence_conflict' => (int) ($stale['classification_counts']['pending_evidence_conflict'] ?? 0),
            'hold_review' => (int) ($stale['classification_counts']['hold_review'] ?? 0),
            'missing_timestamp_review' => (int) ($stale['classification_counts']['missing_timestamp_review'] ?? 0),
        ];

        $health = $this->overallHealth(
            mismatchCount: (int) $mismatches['count'],
            duplicateGroupCount: (int) $duplicates['group_count'],
            backlogCounts: $backlogCounts,
            evidenceGapCount: (int) $evidenceGaps['count'],
            syncState: (string) $sync['state'],
        );

        return [
            'as_of' => $asOf->utc()->toIso8601String(),
            'health' => $health,
            'coverage' => [
                'raw_provider_attempts' => 'unavailable',
                'webhook_event_store' => 'unavailable',
                'payment_orders_are_not_raw_attempts' => true,
            ],
            'window' => [
                'days' => $days,
                'start_date' => $windowStart->toDateString(),
                'end_date' => $windowEnd->toDateString(),
            ],
            'cohort' => $cohort,
            'current_backlog' => [
                ...$backlogCounts,
                'candidate_count' => (int) $stale['candidate_count'],
                'scan_truncated' => (bool) $stale['scan_truncated'],
            ],
            'sync' => $sync,
            'anomalies' => [
                'fulfillment_payment_mismatch_count' => (int) $mismatches['count'],
                'fulfillment_payment_mismatches' => $mismatches['details'],
                'payment_evidence_gap_count' => (int) $evidenceGaps['count'],
                'payment_evidence_gaps' => $evidenceGaps['details'],
                'duplicate_transaction_identity_group_count' => (int) $duplicates['group_count'],
                'duplicate_transaction_identity_extra_rows' => (int) $duplicates['extra_rows'],
                'duplicate_transaction_identity_methods' => $duplicates['methods'],
            ],
            'detail_limit' => $detailLimit,
        ];
    }

    /** @return array<string,mixed> */
    private function cohort(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $maxScan = max(1, (int) config('payment_observability.max_cohort_scan', 10000));
        $orders = $this->wooCustomerQuery()
            ->with(['financials', 'externalReferences'])
            ->whereDate('order_date', '>=', $start->toDateString())
            ->whereDate('order_date', '<=', $end->toDateString())
            ->orderBy('id')
            ->limit($maxScan + 1)
            ->get();

        $truncated = $orders->count() > $maxScan;
        if ($truncated) {
            $orders = $orders->take($maxScan);
        }

        $rows = $orders->map(fn (Order $order): array => $this->cohortRow($order));
        $totals = $this->aggregateCohort($rows);

        return [
            ...$totals,
            'scan_limit' => $maxScan,
            'scan_truncated' => $truncated,
            'by_payment_method' => $rows
                ->groupBy('payment_method')
                ->map(fn (Collection $methodRows): array => $this->aggregateCohort($methodRows))
                ->sortKeys()
                ->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function cohortRow(Order $order): array
    {
        $financials = $order->financials;
        $providerStatus = strtolower(trim((string) ($financials?->provider_order_status ?? '')));
        $paymentMethod = strtolower(trim((string) ($financials?->payment_method ?? '')));
        $authority = $this->paymentAuthority->state($order);

        return [
            'payment_method' => $paymentMethod !== '' ? $paymentMethod : 'unknown',
            'provider_status' => $providerStatus,
            'authority' => $authority->value,
        ];
    }

    /** @param Collection<int,array<string,mixed>> $rows */
    private function aggregateCohort(Collection $rows): array
    {
        $total = $rows->count();
        $paid = $rows->where('authority', PaymentAuthorityState::Paid->value)->count();

        return [
            'payment_orders' => $total,
            'paid_orders' => $paid,
            'hard_failed_orders' => $rows->where('provider_status', 'failed')->count(),
            'pending_orders' => $rows->where('provider_status', 'pending')->count(),
            'hold_orders' => $rows->where('provider_status', 'on-hold')->count(),
            'cancelled_orders' => $rows->filter(fn (array $row): bool => in_array($row['provider_status'], ['cancelled', 'canceled', 'trash'], true))->count(),
            'refunded_orders' => $rows->where('provider_status', 'refunded')->count(),
            'unconfirmed_orders' => $rows->where('authority', PaymentAuthorityState::Unconfirmed->value)->count(),
            'current_paid_rate' => $total === 0 ? 0.0 : round($paid / $total, 4),
        ];
    }

    /** @return array{count:int,details:list<array<string,mixed>>} */
    private function fulfillmentPaymentMismatches(int $limit): array
    {
        $query = $this->wooCustomerQuery()
            ->with(['financials', 'externalReferences', 'fulfillmentAllocation', 'shipment'])
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('order_status', [
                        OrderStatus::Shipped->value,
                        OrderStatus::Completed->value,
                        OrderStatus::CompletedDelivered->value,
                    ])
                    ->orWhereHas('fulfillmentAllocation')
                    ->orWhereHas('shipment')
                    ->orWhereExists(function ($reservation): void {
                        $reservation->selectRaw('1')
                            ->from('inventory_reservations')
                            ->whereColumn('inventory_reservations.order_id', 'orders.id')
                            ->whereIn('inventory_reservations.status', ['reserved', 'committed']);
                    });
            })
            ->orderBy('id');

        $count = 0;
        $rows = [];
        foreach ($query->cursor() as $order) {
            $state = $this->paymentAuthority->state($order);
            if (in_array($state, [PaymentAuthorityState::Paid, PaymentAuthorityState::NotRequired], true)) {
                continue;
            }

            $count++;
            if (count($rows) < $limit) {
                $rows[] = $this->safeOrderAnomaly($order, $state, [
                    'has_fulfillment_allocation' => $order->fulfillmentAllocation !== null,
                    'has_shipment' => $order->shipment !== null,
                    'has_active_reservation' => InventoryReservation::query()
                        ->where('order_id', $order->getKey())
                        ->whereIn('status', ['reserved', 'committed'])
                        ->exists(),
                ]);
            }
        }

        return ['count' => $count, 'details' => $rows];
    }

    /** @return array{count:int,details:list<array<string,mixed>>} */
    private function paymentEvidenceGaps(int $limit): array
    {
        $query = $this->wooCustomerQuery()
            ->with(['financials', 'externalReferences'])
            ->whereHas('financials', fn ($financials) => $financials
                ->whereIn('provider_order_status', ['processing', 'completed'])
                ->whereNull('paid_at'))
            ->orderBy('id');

        $count = (clone $query)->count();
        $details = $query
            ->limit($limit)
            ->get()
            ->map(fn (Order $order): array => $this->safeOrderAnomaly(
                $order,
                $this->paymentAuthority->state($order),
            ))
            ->values()
            ->all();

        return ['count' => $count, 'details' => $details];
    }

    /** @return array{group_count:int,extra_rows:int,methods:array<string,int>} */
    private function duplicateTransactionIdentity(): array
    {
        $groups = DB::table('order_financials')
            ->selectRaw('payment_method, COUNT(*) AS duplicate_count')
            ->whereNotNull('payment_transaction_id')
            ->where('payment_transaction_id', '<>', '')
            ->groupBy('payment_method', 'payment_transaction_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return [
            'group_count' => $groups->count(),
            'extra_rows' => $groups->sum(fn ($row): int => max(0, ((int) $row->duplicate_count) - 1)),
            'methods' => $groups
                ->groupBy(fn ($row): string => trim((string) $row->payment_method) !== '' ? strtolower((string) $row->payment_method) : 'unknown')
                ->map(fn (Collection $rows): int => $rows->count())
                ->sortKeys()
                ->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function syncHealth(CarbonImmutable $asOf): array
    {
        $run = SyncRun::query()
            ->where('run_type', self::WOO_SYNC_RUN_TYPE)
            ->where('provider_key', 'woocommerce')
            ->latest('id')
            ->first();

        if ($run === null) {
            return [
                'state' => 'missing',
                'run_id' => null,
                'run_status' => null,
                'age_hours' => null,
                'started_at' => null,
                'completed_at' => null,
                'failed_items' => 0,
                'conflict_items' => 0,
            ];
        }

        $status = $run->status instanceof \BackedEnum ? $run->status->value : (string) $run->status;
        $anchor = $run->completed_at ?? $run->started_at ?? $run->created_at;
        $ageHours = $anchor === null
            ? null
            : max(0.0, ($asOf->utc()->getTimestamp() - CarbonImmutable::instance($anchor)->utc()->getTimestamp()) / 3600);
        $staleAfter = max(0.5, (float) config('payment_observability.sync_stale_after_hours', 2.5));

        $state = match (true) {
            $status === SyncRunStatus::Failed->value => 'failed',
            $ageHours === null || $ageHours > $staleAfter => 'stale',
            $status === SyncRunStatus::Partial->value => 'partial',
            default => 'healthy',
        };

        return [
            'state' => $state,
            'run_id' => (int) $run->getKey(),
            'run_status' => $status,
            'age_hours' => $ageHours === null ? null : round($ageHours, 2),
            'started_at' => $run->started_at?->utc()->toIso8601String(),
            'completed_at' => $run->completed_at?->utc()->toIso8601String(),
            'failed_items' => (int) $run->failed_items,
            'conflict_items' => (int) $run->conflict_items,
        ];
    }

    /** @return array<string,mixed> */
    private function safeOrderAnomaly(Order $order, PaymentAuthorityState $state, array $extra = []): array
    {
        $financials = $order->financials;

        return [
            'order_id' => (int) $order->getKey(),
            'erp_order_id' => (string) ($order->erp_order_id ?? ''),
            'provider_reference' => (string) ($financials?->provider_reference ?? ''),
            'payment_method' => strtolower(trim((string) ($financials?->payment_method ?? ''))) ?: 'unknown',
            'provider_status' => strtolower(trim((string) ($financials?->provider_order_status ?? ''))),
            'payment_authority' => $state->value,
            'erp_fulfillment_status' => $order->order_status instanceof \BackedEnum
                ? $order->order_status->value
                : (string) $order->order_status,
            'has_paid_at' => $financials?->paid_at !== null,
            'has_transaction_id' => trim((string) ($financials?->payment_transaction_id ?? '')) !== '',
            ...$extra,
        ];
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

    private function overallHealth(
        int $mismatchCount,
        int $duplicateGroupCount,
        array $backlogCounts,
        int $evidenceGapCount,
        string $syncState,
    ): string {
        if ($mismatchCount > 0
            || $duplicateGroupCount > 0
            || in_array($syncState, ['failed', 'stale', 'missing'], true)) {
            return 'critical';
        }

        if ($evidenceGapCount > 0
            || (int) ($backlogCounts['pending_evidence_conflict'] ?? 0) > 0
            || (int) ($backlogCounts['hold_review'] ?? 0) > 0
            || (int) ($backlogCounts['missing_timestamp_review'] ?? 0) > 0
            || $syncState === 'partial') {
            return 'warning';
        }

        return 'ok';
    }
}
