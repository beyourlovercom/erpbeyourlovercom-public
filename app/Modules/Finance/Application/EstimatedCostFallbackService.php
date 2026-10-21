<?php

namespace App\Modules\Finance\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Projection-only fallback estimator.
 *
 * Nothing returned by this service is persisted. Its purpose is to keep
 * estimated profit available while real cost evidence is still incomplete.
 * Strong/posted facts always win before this service is consulted.
 */
final class EstimatedCostFallbackService
{
    private const STRONG_PROVENANCE = ['actual', 'confirmed_manual', 'provider_import', 'allocated_actual'];

    /** @var array<string,array{ratio:string,sample_count:int,confidence:string,method:string}|null> */
    private array $ratioCache = [];

    /**
     * @return array{
     *   amount:string,
     *   currency:string,
     *   confidence:string,
     *   source_reference:string,
     *   source_detail:array<string,mixed>
     * }
     */
    public function estimate(Model $order, string $costType, string $currency, int $netRevenueCents): array
    {
        if ($costType === 'customs_tax') {
            return $this->result('0.00', $currency, 'high', 'projection-owner-rule:customs-tax:v1:none', [
                'method' => 'owner_rule',
                'rule' => 'no_customs_tax',
            ]);
        }

        if ($costType === 'payment_fee') {
            $financials = $order->financials;
            $basis = $financials?->provider_order_total ?? $financials?->amount_paid;
            if ($basis !== null && is_numeric((string) $basis) && (float) $basis >= 0) {
                return $this->result(
                    number_format(((float) $basis) * 0.035, 2, '.', ''),
                    $currency,
                    'high',
                    'projection-owner-rule:payment-fee:v1:3.5pct',
                    ['method' => 'owner_rule', 'rate' => '0.035', 'basis' => $financials?->provider_order_total !== null ? 'provider_order_total' : 'amount_paid'],
                );
            }
        }

        if (in_array($costType, ['kol_commission', 'refund_replacement_loss', 'other_variable_cost'], true)) {
            return $this->result('0.00', $currency, 'low', 'projection-provisional-zero:v1:'.$costType, [
                'method' => 'provisional_zero',
                'reason' => 'no_order_specific_evidence',
            ]);
        }

        $historical = $this->historicalRatio($costType);
        if ($historical !== null && $netRevenueCents >= 0) {
            $amount = ($netRevenueCents / 100) * (float) $historical['ratio'];

            return $this->result(
                number_format(max(0, $amount), 2, '.', ''),
                $currency,
                $historical['confidence'],
                'projection-historical-cost-ratio:v1:'.$costType,
                [
                    'method' => 'historical_confirmed_cost_to_revenue_'.$historical['method'],
                    'ratio' => $historical['ratio'],
                    'ratio_method' => $historical['method'],
                    'sample_count' => $historical['sample_count'],
                ],
            );
        }

        return $this->result('0.00', $currency, 'very_low', 'projection-no-history-zero:v1:'.$costType, [
            'method' => 'no_history_provisional_zero',
            'reason' => 'no_eligible_confirmed_history',
        ]);
    }

    /** @return array{ratio:string,sample_count:int,confidence:string,method:string}|null */
    private function historicalRatio(string $costType): ?array
    {
        if (array_key_exists($costType, $this->ratioCache)) {
            return $this->ratioCache[$costType];
        }

        $rows = DB::table('order_cost_entries as oce')
            ->join('orders as o', 'o.id', '=', 'oce.order_id')
            ->join('order_financials as f', 'f.order_id', '=', 'o.id')
            ->where('o.order_kind', 'customer')
            ->where('oce.cost_type', $costType)
            ->whereIn('oce.provenance', self::STRONG_PROVENANCE)
            ->whereIn('oce.status', ['confirmed', 'adjusted'])
            ->whereColumn('oce.currency', 'f.currency')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('order_external_references as er')
                    ->whereColumn('er.order_id', 'o.id')
                    ->where('er.provider', 'historical_logistics');
            })
            ->orderByDesc('oce.effective_at')
            ->orderByDesc('oce.id')
            ->limit(2000)
            ->get([
                'oce.amount',
                'f.merchandise_list_amount',
                'f.discount_amount',
                'f.shipping_revenue',
                'f.amount_paid',
                'f.refund_amount',
            ]);

        $ratios = [];
        foreach ($rows as $row) {
            $net = $this->historicalNetRevenue($row);
            $amount = is_numeric((string) $row->amount) ? (float) $row->amount : null;
            if ($net === null || $net <= 0 || $amount === null || $amount < 0) {
                continue;
            }
            $ratios[] = $amount / $net;
        }

        sort($ratios, SORT_NUMERIC);
        $count = count($ratios);
        if ($count === 0) {
            return $this->ratioCache[$costType] = null;
        }

        $middle = intdiv($count, 2);
        $median = $count % 2 === 0
            ? ($ratios[$middle - 1] + $ratios[$middle]) / 2
            : $ratios[$middle];
        $mean = array_sum($ratios) / $count;

        // First-mile / handling are a mixed route population with legitimate zero-cost
        // China-direct orders. Their median collapses to zero when direct fulfillment is
        // the majority, so use the historical expected ratio (mean) when route is unknown.
        // Product / warehouse / last-mile remain median-based to resist invoice outliers.
        $useExpectedMean = in_array($costType, ['first_mile_cost', 'overseas_handling_cost'], true);
        $ratio = $useExpectedMean ? $mean : $median;

        $confidence = match (true) {
            $count >= 100 => 'high',
            $count >= 20 => 'moderate',
            $count >= 5 => 'low',
            default => 'very_low',
        };

        return $this->ratioCache[$costType] = [
            'ratio' => number_format($ratio, 8, '.', ''),
            'sample_count' => $count,
            'confidence' => $confidence,
            'method' => $useExpectedMean ? 'mean' : 'median',
        ];
    }

    private function historicalNetRevenue(object $row): ?float
    {
        if ($row->merchandise_list_amount !== null
            && $row->discount_amount !== null
            && $row->shipping_revenue !== null
            && $row->refund_amount !== null) {
            return (float) $row->merchandise_list_amount
                - (float) $row->discount_amount
                + (float) $row->shipping_revenue
                - (float) $row->refund_amount;
        }

        if ($row->amount_paid !== null && $row->refund_amount !== null) {
            return (float) $row->amount_paid - (float) $row->refund_amount;
        }

        return null;
    }

    /** @return array{amount:string,currency:string,confidence:string,source_reference:string,source_detail:array<string,mixed>} */
    private function result(string $amount, string $currency, string $confidence, string $sourceReference, array $sourceDetail): array
    {
        return [
            'amount' => $amount,
            'currency' => $currency,
            'confidence' => $confidence,
            'source_reference' => $sourceReference,
            'source_detail' => $sourceDetail,
        ];
    }
}
