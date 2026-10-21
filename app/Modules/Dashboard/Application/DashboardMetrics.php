<?php

namespace App\Modules\Dashboard\Application;

use App\Modules\Finance\Application\EstimatedProfitProjectionService;
use App\Modules\Finance\Application\OrderAnalysisService;
use App\Modules\Finance\Application\UsdAggregateConverter;
use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Logistics\Application\LogisticsStatusSummary;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\SyncOrchestrationService;
use App\Modules\Sync\Infrastructure\Models\ManualSync;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DashboardMetrics
{
    public function __construct(
        private readonly LogisticsStatusSummary $logistics,
        private readonly InventoryService $inventory,
        private readonly SyncOrchestrationService $sync,
        private readonly OrderAnalysisService $analysis,
        private readonly EstimatedProfitProjectionService $estimatedProfit,
        private readonly UsdAggregateConverter $usd,
    ) {}

    public function operations(): array
    {
        $status = collect($this->logistics->statusBar())->keyBy('key');

        return [
            'wait_stock' => [
                'key' => 'wait_stock',
                'label' => '待分仓',
                'url' => '/logistics/wait-stock',
                'count' => $this->logistics->waitStockCount(),
            ],
            'awaiting' => $status->get('awaiting'),
            'transit' => $status->get('transit'),
            'exception' => $status->get('exception'),
            'review' => $status->get('review'),
            'completed' => $status->get('completed'),
            'cancelled' => $status->get('cancelled'),
        ];
    }

    public function inventoryRisk(): array
    {
        $summary = $this->inventory->dashboard();

        return [
            'stock_alert_rows' => (int) $summary['stock_alert_rows'],
            'out_of_stock_rows' => (int) $summary['out_of_stock_rows'],
            'low_stock_rows' => (int) $summary['low_stock_rows'],
            'quantity_available' => (int) $summary['quantity_available'],
            'warehouse_data_gap_count' => (int) $summary['warehouse_data_gap_count'],
            'warehouse_total_count' => (int) $summary['warehouse_total_count'],
        ];
    }

    public function syncHealth(): array
    {
        $queue = $this->sync->queueSummary();

        return [
            'pending' => ManualSync::query()->where('website_sync_status', 'pending')->count(),
            'retry_due' => (int) $queue['retry_due'],
            'retry_waiting' => (int) $queue['retry_waiting'],
            'processing' => (int) $queue['processing'],
            'failed' => (int) $queue['failed'],
            'conflicts' => (int) $queue['conflicts'],
        ];
    }

    public function financeSnapshot(string $dateFrom, string $dateTo): array
    {
        $key = "dashboard:finance:v1:{$dateFrom}:{$dateTo}";

        return Cache::remember($key, now()->addMinutes(5), fn (): array => $this->buildFinanceSnapshot($dateFrom, $dateTo));
    }

    private function buildFinanceSnapshot(string $dateFrom, string $dateTo): array
    {
        $orders = $this->financeOrders($dateFrom, $dateTo)->get();
        $eligible = $orders->count();
        $revenueReady = 0;
        $estimatedReady = 0;
        $finalReady = 0;
        $fxExcluded = 0;
        $confidence = ['high' => 0, 'moderate' => 0, 'low' => 0, 'very_low' => 0];
        $daily = [];

        foreach ($orders as $order) {
            $summary = $this->analysis->summary($order);
            $projection = $this->estimatedProfit->project($order, useCurrentCandidates: false);

            if (! $projection['canonical_estimated_ready']) {
                continue;
            }

            $revenueReady++;
            $estimatedReady++;
            $confidenceKey = (string) ($projection['projection_confidence'] ?? 'low');
            if (array_key_exists($confidenceKey, $confidence)) {
                $confidence[$confidenceKey]++;
            }

            if (in_array($summary['finance_status'], ['CONFIRMED', 'RECONCILED'], true)
                && $summary['profit_status'] === 'confirmed') {
                $finalReady++;
            }

            if (! $summary['base_currency_aggregate_eligible']) {
                $fxExcluded++;

                continue;
            }

            $cost = $this->usd->toUsdCents((string) $projection['canonical_estimated_total_cost'], $order);
            $profit = $this->usd->toUsdCents((string) $projection['canonical_estimated_profit'], $order);
            $revenue = $cost + $profit;
            $date = $order->order_date?->format('Y-m-d') ?? $dateFrom;
            $daily[$date] ??= ['revenue' => 0, 'cost' => 0, 'profit' => 0, 'orders' => 0];
            $daily[$date]['revenue'] += $revenue;
            $daily[$date]['cost'] += $cost;
            $daily[$date]['profit'] += $profit;
            $daily[$date]['orders']++;
        }

        $trend = [];
        $cursor = CarbonImmutable::parse($dateFrom)->startOfDay();
        $end = CarbonImmutable::parse($dateTo)->startOfDay();
        $totals = ['revenue' => 0, 'cost' => 0, 'profit' => 0, 'orders' => 0];

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $row = $daily[$date] ?? ['revenue' => 0, 'cost' => 0, 'profit' => 0, 'orders' => 0];
            foreach ($totals as $key => $value) {
                $totals[$key] += $row[$key];
            }
            $trend[] = [
                'date' => $date,
                'revenue_usd' => $this->usd->money($row['revenue']),
                'estimated_cost_usd' => $this->usd->money($row['cost']),
                'estimated_profit_usd' => $this->usd->money($row['profit']),
                'aggregate_orders' => $row['orders'],
            ];
            $cursor = $cursor->addDay();
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'eligible_orders' => $eligible,
            'revenue_ready_orders' => $revenueReady,
            'estimated_profit_orders' => $estimatedReady,
            'estimated_profit_percent' => $revenueReady > 0 ? round($estimatedReady / $revenueReady * 100, 1) : 0,
            'final_profit_orders' => $finalReady,
            'final_profit_percent' => $revenueReady > 0 ? round($finalReady / $revenueReady * 100, 1) : 0,
            'fx_excluded_orders' => $fxExcluded,
            'aggregate_orders' => $totals['orders'],
            'estimated_revenue_usd' => $this->usd->money($totals['revenue']),
            'estimated_cost_usd' => $this->usd->money($totals['cost']),
            'estimated_profit_usd' => $this->usd->money($totals['profit']),
            'projection_confidence' => $confidence,
            'trend' => $trend,
        ];
    }

    public function procurementProgress(): array
    {
        $empty = [
            'active' => 0,
            'counts' => ['pending' => 0, 'purchasing' => 0, 'ordered' => 0, 'completed' => 0, 'cancelled' => 0],
            'recent' => [],
        ];
        if (! Schema::hasTable('procurement_orders')) {
            return $empty;
        }

        $counts = $empty['counts'];
        DB::table('procurement_orders')
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->get()
            ->each(function ($row) use (&$counts): void {
                if (array_key_exists($row->status, $counts)) {
                    $counts[$row->status] = (int) $row->aggregate;
                }
            });

        $recent = DB::table('procurement_orders')
            ->leftJoin('orders', 'orders.id', '=', 'procurement_orders.source_order_id')
            ->select([
                'procurement_orders.id', 'procurement_orders.procurement_number', 'procurement_orders.status',
                'procurement_orders.carrier', 'procurement_orders.tracking_number', 'procurement_orders.updated_at',
                'orders.erp_order_id as source_order_erp_id',
            ])
            ->orderByDesc('procurement_orders.updated_at')
            ->orderByDesc('procurement_orders.id')
            ->limit(6)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        return [
            'active' => $counts['pending'] + $counts['purchasing'] + $counts['ordered'],
            'counts' => $counts,
            'recent' => $recent,
        ];
    }

    private function financeOrders(string $dateFrom, string $dateTo): Builder
    {
        return Order::query()
            ->with(['financials', 'costEntries', 'items', 'shipment.warehouse'])
            ->where('order_kind', 'customer')
            ->whereDoesntHave('externalReferences', fn (Builder $references) => $references->where('provider', 'historical_logistics'))
            ->whereDate('order_date', '>=', $dateFrom)
            ->whereDate('order_date', '<=', $dateTo)
            ->orderBy('order_date')
            ->orderBy('id');
    }
}
