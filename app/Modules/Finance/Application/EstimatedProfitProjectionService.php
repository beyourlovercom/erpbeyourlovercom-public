<?php

namespace App\Modules\Finance\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Read-only progressive estimated-profit projection.
 *
 * Revenue-ready orders always receive an estimated profit:
 * strong actual -> posted estimate -> current candidate -> projection-only fallback.
 * Missing evidence lowers confidence/completeness but never nulls the estimate.
 * Final profit remains governed separately by the strict confirmed-cost contract.
 */
final class EstimatedProfitProjectionService
{
    private const REQUIRED_COST_TYPES = [
        'product_cost', 'first_mile_cost', 'overseas_handling_cost',
        'last_mile_cost', 'payment_fee', 'customs_tax',
    ];

    private const OPTIONAL_COST_TYPES = [
        'warehouse_cost', 'kol_commission',
        'refund_replacement_loss', 'other_variable_cost',
    ];

    private const LOGISTICS_COST_TYPES = [
        'warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost',
    ];

    private const OTHER_COST_TYPES = [
        'kol_commission', 'payment_fee', 'customs_tax', 'refund_replacement_loss', 'other_variable_cost',
    ];

    private const STRONG_PROVENANCE = ['actual', 'confirmed_manual', 'provider_import', 'allocated_actual'];

    public function __construct(
        private readonly OrderMaturityService $maturity,
        private readonly CostMaterializationService $materializer,
        private readonly EstimatedCostFallbackService $fallbacks,
    ) {}

    /** @return array<string,mixed> */
    public function project(Model $order, bool $useCurrentCandidates = true): array
    {
        $maturityData = $this->maturity->forOrder($order);
        $order->loadMissing(['financials', 'costEntries']);

        /** @var Collection<string, Model> $entries */
        $entries = $order->costEntries->keyBy('cost_type');
        $financials = $order->financials;
        $currency = strtoupper((string) ($financials?->currency ?? $order->currency ?? 'USD'));

        [$netRevenueCents, $revenueReady] = $this->netRevenueCents($financials);

        $candidates = collect();
        if ($useCurrentCandidates) {
            $preview = $this->materializer->previewExplanation($order);
            $candidates = collect($preview['candidates'])->keyBy('cost_type');

            // The explanation preview prioritizes actual carrier evidence. For estimated
            // profit, explicitly ask the dedicated P8.2 projection when Last Mile is absent.
            if ($entries->get('last_mile_cost') === null) {
                $lastMile = $this->materializer->previewP82LastMileEstimateGate($order)['candidates'][0] ?? null;
                if (is_array($lastMile) && ($lastMile['materialization_status'] ?? 'blocked') !== 'blocked') {
                    $candidates->put('last_mile_cost', $lastMile);
                }
            }
        }

        $estimatedCostCount = 0;
        $confirmedCostCount = 0;
        $fallbackEstimatedCostCount = 0;
        $missingRequiredCount = 0;
        $totalCostCents = 0;
        $costLines = [];

        foreach ([...self::REQUIRED_COST_TYPES, ...self::OPTIONAL_COST_TYPES] as $type) {
            $entry = $entries->get($type);
            $candidate = $candidates->get($type);
            $isOptional = in_array($type, self::OPTIONAL_COST_TYPES, true);

            [$state, $amountCents, $meta] = $this->resolveLine(
                $order,
                $type,
                $entry,
                is_array($candidate) ? $candidate : null,
                $currency,
                $isOptional,
                $netRevenueCents ?? 0,
            );

            match ($state) {
                'confirmed' => $confirmedCostCount++,
                'estimated' => $estimatedCostCount++,
                'fallback_estimated', 'provisional_zero' => $fallbackEstimatedCostCount++,
                default => null,
            };

            if (! $isOptional && in_array($state, ['fallback_estimated', 'provisional_zero'], true)) {
                $missingRequiredCount++;
            }

            $amountCents ??= 0;
            $totalCostCents += $amountCents;

            $costLines[] = [
                'cost_type' => $type,
                'state' => $state,
                'amount' => number_format($amountCents / 100, 2, '.', ''),
                'currency' => $currency,
                ...$meta,
            ];
        }

        $allCostTypes = count(self::REQUIRED_COST_TYPES) + count(self::OPTIONAL_COST_TYPES);
        $evidenceBackedCount = $confirmedCostCount + $estimatedCostCount;
        $costCompletionPercent = $allCostTypes > 0
            ? (int) round($evidenceBackedCount / $allCostTypes * 100)
            : 0;

        $estimatedReady = $revenueReady && $netRevenueCents !== null;
        $estimatedProfit = null;
        $estimatedMargin = null;
        if ($estimatedReady) {
            $profitCents = $netRevenueCents - $totalCostCents;
            $estimatedProfit = number_format($profitCents / 100, 2, '.', '');
            if ($netRevenueCents !== 0) {
                $estimatedMargin = bcdiv((string) $profitCents, (string) $netRevenueCents, 4);
            }
        }

        $confidence = $this->projectionConfidence($costLines, $fallbackEstimatedCostCount);
        $projectedByType = collect($costLines)->keyBy('cost_type');
        $estimatedBreakdown = [
            'product_cost' => $this->sumProjectedGroup(['product_cost'], $projectedByType),
            'logistics_cost' => $this->sumProjectedGroup(self::LOGISTICS_COST_TYPES, $projectedByType),
            'other_cost' => $this->sumProjectedGroup(self::OTHER_COST_TYPES, $projectedByType),
        ];

        return [
            'canonical_estimated_ready' => $estimatedReady,
            'canonical_estimated_total_cost' => number_format($totalCostCents / 100, 2, '.', ''),
            'canonical_estimated_cost_breakdown' => $estimatedBreakdown,
            'canonical_estimated_profit' => $estimatedProfit,
            'canonical_estimated_margin' => $estimatedMargin,
            'estimated_cost_count' => $estimatedCostCount + $fallbackEstimatedCostCount,
            'posted_estimated_cost_count' => $estimatedCostCount,
            'fallback_estimated_cost_count' => $fallbackEstimatedCostCount,
            'confirmed_cost_count' => $confirmedCostCount,
            'missing_required_cost_count' => $missingRequiredCount,
            'cost_completion_percent' => $costCompletionPercent,
            'projection_confidence' => $confidence,
            'projection_cost_lines' => $costLines,
            'age_days' => $maturityData['age_days'],
            'maturity_state' => $maturityData['maturity_state'],
            'reconciliation_due' => $maturityData['reconciliation_due'],
            'unresolved_estimate_count' => $estimatedCostCount + $fallbackEstimatedCostCount,
        ];
    }

    /** @return array{string,int,array<string,mixed>} */
    private function resolveLine(
        Model $order,
        string $type,
        ?Model $entry,
        ?array $candidate,
        string $currency,
        bool $isOptional,
        int $netRevenueCents,
    ): array {
        if ($entry !== null) {
            $provenance = (string) ($entry->provenance ?? '');
            $status = (string) ($entry->status ?? '');
            $entryCurrency = strtoupper((string) $entry->currency);
            if ($entryCurrency === $currency) {
                if (in_array($provenance, self::STRONG_PROVENANCE, true) && in_array($status, ['confirmed', 'adjusted'], true)) {
                    return ['confirmed', $this->cents($entry->amount), [
                        'confidence' => 'high',
                        'source_reference' => $entry->source_reference,
                        'source_kind' => 'posted_confirmed',
                    ]];
                }
                if ($provenance === 'estimated_reference' || $status === 'estimated') {
                    return ['estimated', $this->cents($entry->amount), [
                        'confidence' => 'moderate',
                        'source_reference' => $entry->source_reference,
                        'source_kind' => 'posted_estimate',
                    ]];
                }
            }
        }

        if ($candidate !== null
            && ($candidate['materialization_status'] ?? 'blocked') !== 'blocked'
            && isset($candidate['amount'], $candidate['currency'])
            && strtoupper((string) $candidate['currency']) === $currency) {
            $state = ($candidate['provenance'] ?? null) === 'estimated_reference' || ($candidate['status'] ?? null) === 'estimated'
                ? 'estimated'
                : 'confirmed';

            return [$state, $this->cents($candidate['amount']), [
                'confidence' => $state === 'confirmed' ? 'high' : (string) ($candidate['p82_confidence'] ?? 'moderate'),
                'source_reference' => $candidate['source_reference'] ?? null,
                'source_kind' => 'current_candidate',
            ]];
        }

        // For these optional costs, absence itself is a valid provisional estimate of zero.
        if ($isOptional && in_array($type, ['kol_commission', 'refund_replacement_loss', 'other_variable_cost'], true)) {
            $fallback = $this->fallbacks->estimate($order, $type, $currency, $netRevenueCents);

            return ['provisional_zero', $this->cents($fallback['amount']), [
                'confidence' => $fallback['confidence'],
                'source_reference' => $fallback['source_reference'],
                'source_kind' => 'projection_fallback',
                'source_detail' => $fallback['source_detail'],
            ]];
        }

        $fallback = $this->fallbacks->estimate($order, $type, $currency, $netRevenueCents);

        return ['fallback_estimated', $this->cents($fallback['amount']), [
            'confidence' => $fallback['confidence'],
            'source_reference' => $fallback['source_reference'],
            'source_kind' => 'projection_fallback',
            'source_detail' => $fallback['source_detail'],
        ]];
    }

    /** @param list<string> $types */
    private function sumProjectedGroup(array $types, Collection $projectedByType): string
    {
        $cents = 0;
        foreach ($types as $type) {
            $line = $projectedByType->get($type);
            if (is_array($line)) {
                $cents += $this->cents($line['amount'] ?? '0.00');
            }
        }

        return number_format($cents / 100, 2, '.', '');
    }

    /** @param list<array<string,mixed>> $costLines */
    private function projectionConfidence(array $costLines, int $fallbackCount): string
    {
        if ($fallbackCount === 0) {
            return 'high';
        }

        $confidences = collect($costLines)
            ->whereIn('state', ['fallback_estimated', 'provisional_zero'])
            ->pluck('confidence')
            ->all();

        if (in_array('very_low', $confidences, true)) {
            return 'very_low';
        }
        if (in_array('low', $confidences, true)) {
            return 'low';
        }

        return 'moderate';
    }

    /** @return array{int|null,bool} */
    private function netRevenueCents(?Model $financials): array
    {
        if ($financials === null) {
            return [null, false];
        }

        $provenance = (string) ($financials->revenue_provenance ?? '');
        $componentsReady = $financials->merchandise_list_amount !== null
            && $financials->discount_amount !== null
            && $financials->shipping_revenue !== null
            && $financials->refund_amount !== null;

        if ($provenance === 'provider_import') {
            $providerReady = $componentsReady
                && $financials->provider_order_total !== null
                && $financials->tax_collected !== null
                && $financials->paid_at !== null
                && in_array((string) $financials->provider_order_status, ['processing', 'completed', 'refunded'], true)
                && (string) $financials->reconciliation_status === 'matched';

            if (! $providerReady) {
                return [null, false];
            }

            return [
                $this->cents($financials->merchandise_list_amount)
                    - $this->cents($financials->discount_amount)
                    + $this->cents($financials->shipping_revenue)
                    - $this->cents($financials->refund_amount),
                true,
            ];
        }

        if ($provenance === 'confirmed_manual') {
            if ($componentsReady) {
                return [
                    $this->cents($financials->merchandise_list_amount)
                        - $this->cents($financials->discount_amount)
                        + $this->cents($financials->shipping_revenue)
                        - $this->cents($financials->refund_amount),
                    true,
                ];
            }
            if ($financials->amount_paid !== null && $financials->refund_amount !== null) {
                return [$this->cents($financials->amount_paid) - $this->cents($financials->refund_amount), true];
            }

            return [null, false];
        }

        if ($provenance === ''
            && $financials->merchandise_list_amount === null
            && $financials->amount_paid !== null
            && $financials->refund_amount !== null) {
            return [$this->cents($financials->amount_paid) - $this->cents($financials->refund_amount), true];
        }

        return [null, false];
    }

    private function cents(mixed $amount): int
    {
        $value = trim((string) $amount);
        if (! preg_match('/^(?<sign>-)?(?<whole>\d+)(?:\.(?<fraction>\d{1,2}))?$/D', $value, $matches)) {
            return 0;
        }
        $cents = ((int) $matches['whole'] * 100) + (int) str_pad($matches['fraction'] ?? '', 2, '0');

        return ($matches['sign'] ?? '') === '-' ? -$cents : $cents;
    }
}
