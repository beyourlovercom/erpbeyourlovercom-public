<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Application\Contracts\ConfirmedLossReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ProfitDashboardService
{
    private const UNKNOWN_COUNTRY = '未知国家';
    private const UNKNOWN_PRODUCT = '未归属产品';

    public function __construct(
        private readonly OrderAnalysisService $analysis,
        private readonly ConfirmedLossReader $confirmedLosses,
        private readonly UsdAggregateConverter $usd,
    ) {}

    public function filters(Request $request): array
    {
        return [
            'date_from' => $request->input('date_from') ?: CarbonImmutable::today()->subDays(29)->toDateString(),
            'date_to' => $request->input('date_to') ?: CarbonImmutable::today()->toDateString(),
            'country' => strtoupper(trim((string) $request->input('country', ''))),
            'source_type' => trim((string) $request->input('source_type', '')),
            'product' => trim((string) $request->input('product', $request->input('sku', ''))),
        ];
    }

    public function build(Request $request, Collection $orders): array
    {
        $filters = $this->filters($request);
        $rows = $orders->map(function (Model $order): array {
            $order->loadMissing(['address', 'financials', 'costEntries', 'items']);
            $summary = $this->analysis->summary($order);
            $confirmed = in_array($summary['finance_status'], ['CONFIRMED', 'RECONCILED'], true)
                && $summary['profit_status'] === 'confirmed';
            $fxEligible = (bool) $summary['base_currency_aggregate_eligible'];
            $aggregateEligible = $confirmed && $fxEligible
                && $summary['net_revenue'] !== null
                && $summary['total_cost'] !== null
                && $summary['contribution_profit'] !== null;

            return [
                'order' => $order,
                'summary' => $summary,
                'confirmed' => $confirmed,
                'fx_eligible' => $fxEligible,
                'aggregate_eligible' => $aggregateEligible,
                'usd' => $aggregateEligible ? $this->usdAmounts($summary, $order) : null,
            ];
        });

        $confirmedRows = $rows->filter(fn (array $row): bool => $row['confirmed']);
        $aggregateRows = $rows->filter(fn (array $row): bool => $row['aggregate_eligible']);
        $eligible = $rows->count();
        $confirmedCount = $confirmedRows->count();
        $fxPending = $rows->filter(fn (array $row): bool => ! $row['fx_eligible'] && $this->usd->transactionCurrency($row['order']) !== 'USD')->count();
        $totals = $this->sumRows($aggregateRows);

        return [
            'filters' => $filters,
            'kpis' => [
                'confirmed_revenue' => $this->money($totals['revenue']),
                'confirmed_cost' => $this->money($totals['cost']),
                'confirmed_profit' => $this->money($totals['profit']),
                'margin' => $totals['revenue'] !== 0 ? round($totals['profit'] / $totals['revenue'], 4) : null,
                'confirmed_loss_orders' => $confirmedRows->filter(fn (array $row): bool => $this->confirmedLosses->isConfirmedLoss($row['order']))->count(),
                'profit_completeness_percent' => $eligible > 0 ? round($confirmedCount / $eligible * 100, 1) : 0,
            ],
            'data_health' => [
                'eligible_operational_orders' => $eligible,
                'confirmed_profit_orders' => $confirmedCount,
                'incomplete_orders' => max(0, $eligible - $confirmedCount),
                'fx_pending_orders' => $fxPending,
            ],
            'trend' => $this->trend($aggregateRows),
            'tables' => [
                'country' => $this->countryAggregate($aggregateRows, $filters),
                'product' => $this->productAggregate($aggregateRows, $filters),
                'source' => $this->sourceAggregate($aggregateRows, $filters),
            ],
            'allocation_rule' => '仅分摊已确认且可进入 USD 汇总的订单级金额。优先按订单行实际 line_revenue 权重分摊；全部行收入缺失或为零时按订单行等分。分摊按整数美分守恒并确定性分配余数，不把缺失事实补成 0。',
        ];
    }

    private function sumRows(Collection $rows): array
    {
        $revenue = $rows->sum(fn (array $row): int => $row['usd']['revenue']);
        $cost = $rows->sum(fn (array $row): int => $row['usd']['cost']);

        return ['revenue' => $revenue, 'cost' => $cost, 'profit' => $revenue - $cost];
    }

    private function countryAggregate(iterable $rows, array $filters): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $country = strtoupper(trim((string) ($row['order']->address?->country ?? '')));
            $label = $country !== '' ? $country : self::UNKNOWN_COUNTRY;
            $drilldown = $country !== '' ? ['country' => $country] : ['country_missing' => '1'];
            $this->addGroup($groups, 'country:'.$label, $label, $drilldown, $row['order'], $row['usd']);
        }

        return $this->finish($groups, $filters);
    }

    private function sourceAggregate(iterable $rows, array $filters): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $label = $row['order']->source_type->value;
            $this->addGroup($groups, 'source:'.$label, $label, ['source_type' => $label], $row['order'], $row['usd']);
        }

        return $this->finish($groups, $filters);
    }

    private function productAggregate(iterable $rows, array $filters): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $items = $row['order']->items->values();
            if ($items->isEmpty()) {
                if ($filters['product'] === '') {
                    $this->addGroup($groups, 'product:__missing__', self::UNKNOWN_PRODUCT, ['product_missing' => '1'], $row['order'], $row['usd']);
                }
                continue;
            }

            $weights = $items->map(function ($item): int {
                if ($item->line_revenue === null) {
                    return 0;
                }
                return max(0, $this->decimalToCents((string) $item->line_revenue));
            })->all();
            if (array_sum($weights) <= 0) {
                $weights = array_fill(0, $items->count(), 1);
            }
            $revenueAllocations = $this->allocateCents($row['usd']['revenue'], $weights);
            $costAllocations = $this->allocateCents($row['usd']['cost'], $weights);

            foreach ($items as $index => $item) {
                $sku = trim((string) ($item->sku ?? ''));
                $name = trim((string) ($item->product_summary ?? ''));
                $label = $sku !== '' ? $sku : ($name !== '' ? $name : self::UNKNOWN_PRODUCT);
                if ($filters['product'] !== '' && ! $this->productMatches($sku, $name, $filters['product'])) {
                    continue;
                }
                $drilldown = $label === self::UNKNOWN_PRODUCT ? ['product_missing' => '1'] : ['product' => $label];
                $revenue = $revenueAllocations[$index];
                $cost = $costAllocations[$index];
                $this->addGroup(
                    $groups,
                    'product:'.$label,
                    $label,
                    $drilldown,
                    $row['order'],
                    ['revenue' => $revenue, 'cost' => $cost, 'profit' => $revenue - $cost],
                );
            }
        }

        return $this->finish($groups, $filters);
    }

    private function productMatches(string $sku, string $name, string $filter): bool
    {
        $needle = strtolower($filter);
        return str_contains(strtolower($sku), $needle) || str_contains(strtolower($name), $needle);
    }

    private function addGroup(array &$groups, string $key, string $label, array $drilldown, Model $order, array $usd): void
    {
        $groups[$key] ??= [
            'label' => $label,
            'drilldown' => $drilldown,
            'order_ids' => [],
            'revenue' => 0,
            'cost' => 0,
            'profit' => 0,
        ];
        $groups[$key]['order_ids'][$order->getKey()] = true;
        $groups[$key]['revenue'] += $usd['revenue'];
        $groups[$key]['cost'] += $usd['cost'];
        $groups[$key]['profit'] += $usd['profit'];
    }

    private function finish(array $groups, array $filters): array
    {
        $rows = array_values(array_map(function (array $group) use ($filters): array {
            $query = array_filter([
                'date_from' => $filters['date_from'],
                'date_to' => $filters['date_to'],
                'country' => $filters['country'],
                'source_type' => $filters['source_type'],
                'product' => $filters['product'],
                'profit_status' => 'confirmed',
                'fx_status' => 'aggregate_ready',
            ], fn (mixed $value): bool => $value !== null && $value !== '');
            foreach (['country', 'source_type', 'product', 'country_missing', 'product_missing'] as $key) {
                if (array_key_exists($key, $group['drilldown'])) {
                    if (str_ends_with($key, '_missing')) {
                        unset($query[str_replace('_missing', '', $key)]);
                    }
                    $query[$key] = $group['drilldown'][$key];
                }
            }
            $revenue = $group['revenue'];
            $profit = $group['profit'];

            return [
                'label' => $group['label'],
                'confirmed_order_count' => count($group['order_ids']),
                'revenue_usd' => $this->money($revenue),
                'cost_usd' => $this->money($group['cost']),
                'profit_usd' => $this->money($profit),
                'margin' => $revenue !== 0 ? round($profit / $revenue, 4) : null,
                'drilldown_url' => '/accounting/order-costs?'.http_build_query($query),
            ];
        }, $groups));

        usort($rows, fn (array $a, array $b): int => bccomp($b['revenue_usd'], $a['revenue_usd'], 2) ?: strcmp($a['label'], $b['label']));
        return $rows;
    }

    private function trend(iterable $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $date = $row['order']->order_date?->format('Y-m-d') ?? '未知日期';
            $groups[$date] ??= ['date' => $date, 'revenue' => 0, 'cost' => 0, 'profit' => 0];
            $groups[$date]['revenue'] += $row['usd']['revenue'];
            $groups[$date]['cost'] += $row['usd']['cost'];
            $groups[$date]['profit'] += $row['usd']['profit'];
        }
        ksort($groups);

        return array_values(array_map(fn (array $row): array => [
            'date' => $row['date'],
            'revenue_usd' => $this->money($row['revenue']),
            'cost_usd' => $this->money($row['cost']),
            'profit_usd' => $this->money($row['profit']),
        ], $groups));
    }

    private function usdAmounts(array $summary, Model $order): array
    {
        $revenue = $this->usd->toUsdCents((string) $summary['net_revenue'], $order);
        $cost = $this->usd->toUsdCents((string) $summary['total_cost'], $order);

        return ['revenue' => $revenue, 'cost' => $cost, 'profit' => $revenue - $cost];
    }


    private function decimalToCents(string $value, bool $round = false): int
    {
        $value = trim($value);
        if (! preg_match('/^(?<sign>-)?(?<whole>\d+)(?:\.(?<fraction>\d+))?$/D', $value, $matches)) {
            throw new \UnexpectedValueException('Financial amounts must be decimal values.');
        }
        $fraction = str_pad($matches['fraction'] ?? '', 3, '0');
        $cents = ((int) $matches['whole'] * 100) + (int) substr($fraction, 0, 2);
        if ($round && (int) $fraction[2] >= 5) {
            $cents++;
        }
        return ($matches['sign'] ?? '') === '-' ? -$cents : $cents;
    }

    private function allocateCents(int $total, array $weights): array
    {
        if ($weights === []) {
            return [];
        }
        $weightTotal = array_sum($weights);
        if ($weightTotal <= 0) {
            $weights = array_fill(0, count($weights), 1);
            $weightTotal = count($weights);
        }
        $sign = $total < 0 ? -1 : 1;
        $absolute = abs($total);
        $allocated = [];
        $remainders = [];
        $baseSum = 0;
        foreach ($weights as $index => $weight) {
            $numerator = $absolute * $weight;
            $base = intdiv($numerator, $weightTotal);
            $allocated[$index] = $base;
            $remainders[$index] = $numerator % $weightTotal;
            $baseSum += $base;
        }
        $remaining = $absolute - $baseSum;
        $indexes = array_keys($weights);
        usort($indexes, fn (int $a, int $b): int => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b));
        for ($i = 0; $i < $remaining; $i++) {
            $allocated[$indexes[$i % count($indexes)]]++;
        }
        ksort($allocated);
        return array_map(fn (int $value): int => $value * $sign, array_values($allocated));
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
