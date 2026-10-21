<?php

namespace App\Modules\Finance\Experience\Console\Commands;

use App\Modules\Finance\Application\EstimatedProfitProjectionService;
use App\Modules\Finance\Application\LastMileHistoricalProjectionService;
use App\Modules\Finance\Application\OrderMaturityService;
use Illuminate\Console\Command;

/**
 * P8.2 read-only dry-run command.
 * Scans orders and reports estimated vs actual reconciliation status.
 * NEVER writes to the database. Safe to run in Production.
 */
final class P82EstimatedReconciliationCommand extends Command
{
    protected $signature = 'finance:p8-2-estimated-reconciliation
        {--order= : Inspect a single order ID}
        {--limit=500 : Maximum orders to inspect (bounded, max 5000)}';

    protected $description = '[P8.2 read-only] Report estimated→actual reconciliation status per order. No writes.';

    public function handle(
        EstimatedProfitProjectionService $estimatedProfit,
        LastMileHistoricalProjectionService $lastMileProjection,
        OrderMaturityService $maturity,
    ): int {
        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';
        $query = $orderClass::query()->orderBy('id');

        if ($this->option('order') !== null) {
            $query->whereKey($this->option('order'));
        } else {
            $limit = max(1, min(5000, (int) $this->option('limit')));
            $query->where('order_kind', 'customer')
                ->whereDoesntHave('externalReferences', fn ($refs) => $refs->where('provider', 'historical_logistics'))
                ->limit($limit);
        }

        $orders = $query->with(['financials', 'costEntries', 'address', 'items', 'shipment.warehouse'])->get();

        $summary = $this->emptySummary();
        $summary['plan_sha'] = substr(hash('sha256', $query->toSql().serialize($query->getBindings())), 0, 16);

        $ageBuckets = ['0_7' => 0, '8_30' => 0, '31_45' => 0, '46_plus' => 0, 'no_date' => 0];
        $tierCounts = [];
        $confidenceCounts = [];

        foreach ($orders as $order) {
            $summary['seen']++;

            $projection = $estimatedProfit->project($order);
            $lastMile = $lastMileProjection->project($order);

            $maturityData = $maturity->forOrder($order);
            $ageDays = $maturityData['age_days'];

            match (true) {
                $ageDays === null => $ageBuckets['no_date']++,
                $ageDays <= 7    => $ageBuckets['0_7']++,
                $ageDays <= 30   => $ageBuckets['8_30']++,
                $ageDays <= 45   => $ageBuckets['31_45']++,
                default          => $ageBuckets['46_plus']++,
            };

            $financeStatus = strtoupper((string) ($order->financials?->completeness_status ?? 'UNPOSTED'));

            if (in_array($financeStatus, ['CONFIRMED', 'RECONCILED'], true) && $projection['missing_required_cost_count'] === 0 && $projection['estimated_cost_count'] === 0) {
                $summary['final_ready']++;
            } elseif ($projection['canonical_estimated_ready']) {
                $summary['estimated_ready']++;
            } else {
                $summary['review_or_missing']++;
            }

            if ($projection['estimated_cost_count'] > 0) {
                $summary['orders_with_estimates']++;
            }
            if (($ageDays ?? 0) > 30 && $projection['estimated_cost_count'] > 0) {
                $summary['30_plus_estimated']++;
            }
            if (($ageDays ?? 0) > 45 && $projection['estimated_cost_count'] > 0) {
                $summary['45_plus_estimated']++;
            }

            $summary['total_confirmed_costs'] += $projection['confirmed_cost_count'];
            $summary['total_estimated_costs'] += $projection['estimated_cost_count'];
            $summary['total_missing_required'] += $projection['missing_required_cost_count'];

            $tier = (string) ($lastMile['tier'] ?? 'unknown');
            $tierCounts[$tier] = ($tierCounts[$tier] ?? 0) + 1;

            $conf = (string) ($lastMile['confidence'] ?? 'unknown');
            $confidenceCounts[$conf] = ($confidenceCounts[$conf] ?? 0) + 1;
        }

        $summary['age_buckets'] = $ageBuckets;
        $summary['last_mile_tier_counts'] = $tierCounts;
        $summary['last_mile_confidence_counts'] = $confidenceCounts;

        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function emptySummary(): array
    {
        return [
            'mode' => 'dry_run',
            'plan_sha' => null,
            'seen' => 0,
            'final_ready' => 0,
            'estimated_ready' => 0,
            'review_or_missing' => 0,
            'orders_with_estimates' => 0,
            '30_plus_estimated' => 0,
            '45_plus_estimated' => 0,
            'total_confirmed_costs' => 0,
            'total_estimated_costs' => 0,
            'total_missing_required' => 0,
            'age_buckets' => [],
            'last_mile_tier_counts' => [],
            'last_mile_confidence_counts' => [],
        ];
    }
}
