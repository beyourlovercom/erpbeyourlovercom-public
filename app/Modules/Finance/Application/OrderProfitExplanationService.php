<?php

namespace App\Modules\Finance\Application;

use Illuminate\Database\Eloquent\Model;

final class OrderProfitExplanationService
{
    private const COST_TYPES = [
        'product_cost', 'warehouse_cost', 'first_mile_cost',
        'overseas_handling_cost', 'last_mile_cost', 'payment_fee',
        'kol_commission', 'refund_replacement_loss', 'customs_tax',
        'other_variable_cost',
    ];

    public function __construct(
        private readonly CostMaterializationService $materializer,
        private readonly OrderAnalysisService $analysis,
        private readonly OrderMaturityService $maturity,
        private readonly EstimatedProfitProjectionService $estimatedProfit,
    ) {}

    /** @return array<string,mixed> */
    public function explain(Model $order): array
    {
        $preview = $this->materializer->previewExplanation($order);
        $visibility = $this->analysis->visibility($order);
        $candidates = collect($preview['candidates'])->keyBy('cost_type');
        $posted = collect($visibility['cost_lines'])->keyBy('cost_type');
        $finalCostLines = collect(self::COST_TYPES)->map(
            fn (string $type): array => $this->costLine($type, $candidates->get($type), $posted->get($type))
        )->values()->all();
        $estimatedProjection = $this->estimatedProfit->project($order);
        $projectionLines = collect($estimatedProjection['projection_cost_lines'])->keyBy('cost_type');
        $costLines = collect($finalCostLines)->map(function (array $line) use ($projectionLines): array {
            if (($line['state'] ?? null) === 'confirmed' && ($line['amount'] ?? null) !== null) {
                return $line;
            }
            $projected = $projectionLines->get($line['cost_type']);
            if (! is_array($projected)) {
                return $line;
            }
            return array_merge($line, [
                'amount' => $projected['amount'] ?? $line['amount'],
                'currency' => $projected['currency'] ?? $line['currency'],
                'state' => 'estimated',
                'provenance' => 'estimated_reference',
                'status' => 'estimated',
                'source_reference' => $projected['source_reference'] ?? $line['source_reference'],
                'blocker' => null,
                'projection_confidence' => $projected['confidence'] ?? null,
                'projection_source_kind' => $projected['source_kind'] ?? null,
            ]);
        })->values()->all();

        $summary = $visibility['summary'];
        $currency = strtoupper((string) ($order->financials?->currency ?? $order->currency ?? 'USD'));
        $known = '0';
        foreach ($finalCostLines as $line) {
            if ($line['amount'] !== null && $line['currency'] === $currency) {
                $known = DecimalMoney::add($known, $line['amount'], 8);
            }
        }
        $known = DecimalMoney::round($known);
        $complete = ($summary['profit_status'] ?? null) === 'confirmed'
            && collect($finalCostLines)->every(fn (array $line): bool => $line['amount'] !== null && $line['state'] === 'confirmed');
        $net = $summary['net_revenue'];
        $profit = $net !== null && $complete ? DecimalMoney::round(bcsub($net, $known, 8)) : null;
        $margin = $profit !== null && bccomp($net, '0', 8) !== 0 ? bcdiv($profit, $net, 4) : null;

        $shipments = $this->shipmentSection($candidates);
        $maturityData = $this->maturity->forOrder($order);

        return [
            'order' => $this->orderHeader($order, $preview),
            'summary' => array_merge($summary, [
                'canonical_known_cost' => $known,
                'canonical_final_ready' => $complete,
                'canonical_contribution_profit' => $profit,
                'canonical_margin' => $margin,
                'canonical_estimated_ready' => $estimatedProjection['canonical_estimated_ready'],
                'canonical_estimated_total_cost' => $estimatedProjection['canonical_estimated_total_cost'],
                'canonical_estimated_profit' => $estimatedProjection['canonical_estimated_profit'],
                'canonical_estimated_margin' => $estimatedProjection['canonical_estimated_margin'],
                'estimated_cost_count' => $estimatedProjection['estimated_cost_count'],
                'posted_estimated_cost_count' => $estimatedProjection['posted_estimated_cost_count'],
                'fallback_estimated_cost_count' => $estimatedProjection['fallback_estimated_cost_count'],
                'projection_confidence' => $estimatedProjection['projection_confidence'],
                'confirmed_cost_count' => $estimatedProjection['confirmed_cost_count'],
                'missing_required_cost_count' => $estimatedProjection['missing_required_cost_count'],
                'cost_completion_percent' => $estimatedProjection['cost_completion_percent'],
                'unresolved_estimate_count' => $estimatedProjection['unresolved_estimate_count'],
                'age_days' => $maturityData['age_days'],
                'maturity_state' => $maturityData['maturity_state'],
                'reconciliation_due' => $maturityData['reconciliation_due'],
                'estimate_allowed' => $maturityData['estimate_allowed'],
            ]),
            'product' => $this->productSection($candidates->get('product_cost'), collect($costLines)->firstWhere('cost_type', 'product_cost')),
            'shipments' => $shipments,
            'logistics' => $this->logisticsSection($shipments),
            'replacement_loss' => $this->replacementLossSection($candidates->get('refund_replacement_loss')),
            'other_costs' => array_values(array_filter($costLines, fn (array $line): bool => ! in_array($line['cost_type'], ['product_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost'], true))),
            'formula' => $costLines,
        ];
    }

    /** @return array<string,mixed> */
    private function orderHeader(Model $order, array $preview): array
    {
        $order->loadMissing(['items', 'address', 'financials', 'originalFulfillmentChildren.shipment', 'replacementFulfillments.shipment']);
        $originalShipmentCount = $order->originalFulfillmentChildren->isNotEmpty()
            ? $order->originalFulfillmentChildren->filter(fn ($child) => $child->shipment !== null)->count()
            : ($order->shipment ? 1 : 0);
        $replacementShipmentCount = $order->replacementFulfillments->filter(fn ($child) => $child->shipment !== null)->count();
        $shipmentCount = max((int) ($preview['shipment_count'] ?? 0), $originalShipmentCount) + $replacementShipmentCount;
        return [
            'id' => (int) $order->getKey(),
            'erp_order_id' => $order->erp_order_id,
            'external_order_number' => $order->external_order_number,
            'order_date' => $order->order_date?->format('Y-m-d'),
            'source_type' => $order->source_type instanceof \BackedEnum ? $order->source_type->value : (string) $order->source_type,
            'currency' => strtoupper((string) ($order->financials?->currency ?? $order->currency ?? 'USD')),
            'shipping_country' => $order->address?->country,
            'product_count' => $order->items->count(),
            'shipment_count' => $shipmentCount,
            'original_shipment_count' => $originalShipmentCount,
            'replacement_shipment_count' => $replacementShipmentCount,
            'multi_shipment' => $shipmentCount > 1,
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function productSection(?array $candidate, ?array $projectedLine = null): array
    {
        if ($candidate === null || (($candidate['amount'] ?? null) === null && $projectedLine !== null)) {
            return [
                'state' => $projectedLine['state'] ?? 'missing',
                'amount' => $projectedLine['amount'] ?? null,
                'currency' => $projectedLine['currency'] ?? null,
                'provenance' => $projectedLine['provenance'] ?? null,
                'source_reference' => $projectedLine['source_reference'] ?? null,
                'source_at' => null,
                'blocker' => null,
                'lines' => [],
            ];
        }
        return [
            'state' => $this->candidateState($candidate),
            'amount' => $candidate['amount'] ?? null,
            'currency' => $candidate['currency'] ?? null,
            'provenance' => $candidate['provenance'] ?? null,
            'source_reference' => $candidate['source_reference'] ?? null,
            'source_at' => $candidate['source_at'] ?? null,
            'blocker' => $candidate['blocker'] ?? null,
            'lines' => $candidate['line_breakdown'] ?? [],
        ];
    }

    /** @param \Illuminate\Support\Collection<string,array<string,mixed>> $candidates @return list<array<string,mixed>> */
    private function shipmentSection($candidates): array
    {
        $rows = [];
        foreach (['first_mile_cost', 'overseas_handling_cost', 'last_mile_cost'] as $type) {
            $candidate = $candidates->get($type);
            if (! is_array($candidate)) continue;
            foreach (($candidate['shipment_breakdown'] ?? []) as $leg) {
                $id = (int) ($leg['shipment_id'] ?? 0);
                if ($id < 1) continue;
                $rows[$id] ??= [
                    'shipment_id' => $id,
                    'fulfillment_order_id' => $leg['fulfillment_order_id'] ?? null,
                    'fulfillment_suffix' => $leg['fulfillment_suffix'] ?? null,
                    'warehouse_slug' => $leg['warehouse_slug'] ?? null,
                    'warehouse_code' => $leg['warehouse_code'] ?? null,
                    'warehouse_name' => $leg['warehouse_name'] ?? null,
                    'tracking_number' => $leg['tracking_number'] ?? null,
                    'carrier' => $leg['carrier'] ?? null,
                    'shipped_units' => $leg['shipped_units'] ?? null,
                    'items' => $leg['items'] ?? [],
                    'first_mile' => null, 'handling' => null, 'last_mile' => null,
                ];
                if ($type === 'first_mile_cost') {
                    $rows[$id]['first_mile'] = $this->legAmount($leg, $candidate);
                } elseif ($type === 'overseas_handling_cost') {
                    $rows[$id]['handling'] = $this->legAmount($leg, $candidate);
                } else {
                    $rows[$id]['last_mile'] = [
                        'amount' => $leg['posted_amount'] ?? null,
                        'currency' => $leg['posted_currency'] ?? ($candidate['currency'] ?? null),
                        'source_amount' => $leg['source_amount'] ?? null,
                        'source_currency' => $leg['source_currency'] ?? null,
                        'billing_id' => $leg['billing_id'] ?? null,
                        'billing_document_number' => $leg['billing_document_number'] ?? null,
                        'provider_reference' => $leg['provider_reference'] ?? null,
                        'data_source' => $leg['data_source'] ?? null,
                        'fx' => $leg['fx'] ?? null,
                        'state' => $this->candidateState($candidate),
                    ];
                }
            }
        }
        foreach ($rows as &$row) {
            $parts = array_values(array_filter([$row['first_mile'], $row['handling'], $row['last_mile']], fn ($part) => is_array($part)));
            $currencies = array_values(array_unique(array_filter(array_map(fn (array $part) => $part['currency'] ?? null, $parts))));
            $allPresent = count($parts) === 3 && count(array_filter($parts, fn (array $part): bool => ($part['amount'] ?? null) !== null)) === 3;
            if ($allPresent && count($currencies) === 1) {
                $total = '0';
                foreach ($parts as $part) $total = DecimalMoney::add($total, (string) $part['amount'], 8);
                $row['shipment_total'] = DecimalMoney::round($total);
                $row['shipment_total_currency'] = $currencies[0];
            } else {
                $row['shipment_total'] = null;
                $row['shipment_total_currency'] = $currencies[0] ?? null;
            }
            $componentMap = [
                'First Mile' => $row['first_mile'],
                'Handling' => $row['handling'],
                'Last Mile' => $row['last_mile'],
            ];
            $row['missing_components'] = array_keys(array_filter(
                $componentMap,
                fn ($part): bool => ! is_array($part) || ($part['amount'] ?? null) === null,
            ));
            $states = collect($parts)->map(fn (array $part): string => (string) ($part['state'] ?? 'blocked'));
            if ($states->contains('review')) {
                $row['state'] = 'review';
            } elseif ($row['missing_components'] !== [] || ! $allPresent || $states->contains('missing')) {
                $row['state'] = 'incomplete';
            } elseif ($states->contains('blocked')) {
                $row['state'] = 'blocked';
            } elseif ($states->contains('estimated')) {
                $row['state'] = 'estimated';
            } else {
                $row['state'] = 'confirmed';
            }
        }
        unset($row);
        ksort($rows);
        return array_values($rows);
    }

    /** @return array<string,mixed> */
    private function replacementLossSection(?array $candidate): array
    {
        if ($candidate === null) {
            return ['state' => 'none', 'amount' => '0.00', 'currency' => null, 'replacement_count' => 0, 'breakdown' => []];
        }
        return [
            'state' => $this->candidateState($candidate),
            'amount' => $candidate['amount'] ?? null,
            'currency' => $candidate['currency'] ?? null,
            'replacement_count' => (int) ($candidate['replacement_count'] ?? count($candidate['replacement_breakdown'] ?? [])),
            'breakdown' => $candidate['replacement_breakdown'] ?? [],
            'aggregation_rule' => $candidate['aggregation_rule'] ?? null,
            'double_count_guard' => $candidate['double_count_guard'] ?? null,
            'blocker' => $candidate['blocker'] ?? null,
        ];
    }

    /** @param list<array<string,mixed>> $shipments @return array<string,mixed> */
    private function logisticsSection(array $shipments): array
    {
        if ($shipments === []) {
            return ['state' => 'missing', 'shipment_count' => 0, 'confirmed_shipment_count' => 0];
        }
        $states = collect($shipments)->pluck('state');
        $state = match (true) {
            $states->contains('review') => 'review',
            $states->contains('blocked') => 'blocked',
            $states->contains('incomplete'), $states->contains('missing') => 'incomplete',
            $states->contains('estimated') => 'estimated',
            default => 'confirmed',
        };
        return [
            'state' => $state,
            'shipment_count' => count($shipments),
            'confirmed_shipment_count' => $states->filter(fn (string $value): bool => $value === 'confirmed')->count(),
        ];
    }

    /** @return array<string,mixed> */
    private function legAmount(array $leg, array $candidate): array
    {
        return [
            'amount' => $leg['posted_amount'] ?? ($leg['source_amount'] ?? null),
            'currency' => $leg['posted_currency'] ?? ($leg['source_currency'] ?? ($candidate['currency'] ?? null)),
            'source_amount' => $leg['source_amount'] ?? null,
            'source_currency' => $leg['source_currency'] ?? null,
            'fx' => $leg['fx'] ?? null,
            'unit_rate' => $leg['unit_rate'] ?? null,
            'rule' => $leg['rule'] ?? null,
            'state' => $this->candidateState($candidate),
        ];
    }

    /** @return array<string,mixed> */
    private function costLine(string $type, ?array $candidate, ?array $posted): array
    {
        $label = $this->costLabel($type);
        if ($posted !== null && ($posted['state'] ?? null) === 'confirmed') {
            return [
                'cost_type' => $type,
                'label' => $label,
                'amount' => $posted['amount'] ?? null,
                'currency' => $posted['currency'] ?? null,
                'state' => 'confirmed',
                'formula' => $this->formulaFor($type, $candidate ?? []),
                'provenance' => $posted['provenance'] ?? null,
                'status' => $posted['status'] ?? null,
                'source_reference' => $posted['source_reference'] ?? null,
                'source_at' => $posted['source_at'] ?? null,
                'blocker' => null,
            ];
        }
        if ($candidate !== null) {
            return [
                'cost_type' => $type,
                'label' => $label,
                'amount' => $candidate['amount'] ?? null,
                'currency' => $candidate['currency'] ?? null,
                'state' => $this->candidateState($candidate),
                'formula' => $this->formulaFor($type, $candidate),
                'provenance' => $candidate['provenance'] ?? null,
                'status' => $candidate['status'] ?? null,
                'source_reference' => $candidate['source_reference'] ?? null,
                'source_at' => $candidate['source_at'] ?? null,
                'blocker' => $candidate['blocker'] ?? null,
            ];
        }
        return [
            'cost_type' => $type,
            'label' => $label,
            'amount' => $posted['amount'] ?? null,
            'currency' => $posted['currency'] ?? null,
            'state' => $posted['state'] ?? 'missing',
            'formula' => $this->formulaFor($type, []),
            'provenance' => $posted['provenance'] ?? null,
            'status' => $posted['status'] ?? null,
            'source_reference' => $posted['source_reference'] ?? null,
            'source_at' => $posted['source_at'] ?? null,
            'blocker' => null,
        ];
    }

    private function candidateState(array $candidate): string
    {
        $materialization = (string) ($candidate['materialization_status'] ?? 'blocked');
        if ($materialization === 'blocked') return ($candidate['blocker'] ?? null) === 'stronger_or_different_existing_fact' ? 'review' : 'blocked';
        if (($candidate['status'] ?? null) === 'estimated' || ($candidate['provenance'] ?? null) === 'estimated_reference') return 'estimated';
        return 'confirmed';
    }

    private function costLabel(string $type): string
    {
        return match ($type) {
            'product_cost' => 'Product Cost',
            'warehouse_cost' => 'Warehouse Cost',
            'first_mile_cost' => 'First Mile',
            'overseas_handling_cost' => 'Handling',
            'last_mile_cost' => 'Last Mile',
            'payment_fee' => 'Payment Fee',
            'kol_commission' => 'KOL Commission',
            'refund_replacement_loss' => 'Refund / Replacement Loss',
            'customs_tax' => 'Customs / Duty',
            default => 'Other Variable Cost',
        };
    }

    private function formulaFor(string $type, array $candidate): string
    {
        return match ($type) {
            'product_cost' => 'Σ(Unit Product Cost × Parent Ordered Quantity)',
            'warehouse_cost' => 'Σ canonical warehouse allocation evidence applicable to Parent Order',
            'first_mile_cost' => 'Σ Shipment overseas shipped units × USD 1.00; China-direct = 0',
            'overseas_handling_cost' => 'Σ Shipment overseas shipped units × USD 1.00; China-direct = 0',
            'last_mile_cost' => 'Σ actual carrier / international parcel billing per Shipment',
            'payment_fee' => 'Provider Order Total × 3.5%',
            'kol_commission' => 'Σ eligible AffiliateWP Referral commission evidence',
            'refund_replacement_loss' => 'Σ incremental replacement fulfillment product + warehouse + first/handling + last-mile cost; original logistics excluded',
            'customs_tax' => (($candidate['rule'] ?? null) === 'no_customs_tax') ? 'Owner rule: Customs / Duty = 0' : 'Confirmed customs / duty evidence',
            default => 'Confirmed other variable cost evidence',
        };
    }
}
