<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Inventory\Application\ProductPackagingStandardReader;
use App\Modules\Products\Application\ProductShippingWeightStandardReader;
use App\Modules\Products\Infrastructure\Models\Product;

final class FulfillmentPlanCostEstimator
{
    public function __construct(
        private readonly ProductPackagingStandardReader $packaging,
        private readonly ProductShippingWeightStandardReader $shippingWeights,
        private readonly FxReferenceSettingsService $fx,
        private readonly ChinaFactoryRoutingCostCalculator $china,
        private readonly GermanyDhlRoutingCostCalculator $dhl,
    ) {}

    /** @param array<int,int> $productIds @return array<string,mixed> */
    public function prepare(array $productIds): array
    {
        $packagingStandards = $this->packaging->forProductIds($productIds);
        $shippingWeightStandards = $this->shippingWeights->forProductIds($productIds);
        $packagingCosts = Product::query()->whereIn('id', $productIds)->get(['id', 'standard_packaging_cost', 'cost_currency'])->mapWithKeys(fn (Product $product): array => [(int) $product->id => [
            'amount' => $product->standard_packaging_cost,
            'currency' => $product->cost_currency,
        ]])->all();
        $context = [
            'packaging_standards' => $packagingStandards,
            'shipping_weight_standards' => $shippingWeightStandards,
            'packaging_costs' => $packagingCosts,
        ];

        return [
            ...$context,
            'measurement_availability' => $this->measurementAvailability($productIds, $context),
        ];
    }

    /** @param array<int,int> $productIds @param array<string,mixed> $context @return array<string,mixed> */
    public function measurementAvailability(array $productIds, array $context = []): array
    {
        $ids = collect($productIds)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $packagingStandards = $context['packaging_standards'] ?? $this->packaging->forProductIds($ids);
        $shippingWeightStandards = $context['shipping_weight_standards'] ?? $this->shippingWeights->forProductIds($ids);

        $products = [];
        $missingWeightProductIds = [];
        foreach ($ids as $productId) {
            $packaging = $packagingStandards[$productId] ?? null;
            $shipping = $shippingWeightStandards[$productId] ?? null;
            $hasPackagingWeight = is_array($packaging)
                && is_numeric($packaging['packaged_weight_kg'] ?? null)
                && (float) $packaging['packaged_weight_kg'] > 0;
            $hasShippingWeight = is_array($shipping)
                && is_numeric($shipping['shipping_weight_kg'] ?? null)
                && (float) $shipping['shipping_weight_kg'] > 0;

            $mode = $hasPackagingWeight
                ? 'strict_packaging'
                : ($hasShippingWeight ? 'shipping_weight_only' : 'missing_weight');
            if ($mode === 'missing_weight') {
                $missingWeightProductIds[] = $productId;
            }
            $products[$productId] = [
                'product_id' => $productId,
                'mode' => $mode,
                'has_packaging_weight' => $hasPackagingWeight,
                'has_shipping_weight' => $hasShippingWeight,
            ];
        }

        return [
            'status' => $missingWeightProductIds === [] ? 'ready' : 'missing_weight',
            'cost_ranking_enabled' => $missingWeightProductIds === [],
            'missing_weight_product_ids' => $missingWeightProductIds,
            'products' => $products,
        ];
    }

    /** @param list<array<string,mixed>> $segments @param array<string,mixed> $destinationContext @param array<string,mixed> $context */
    public function estimate(string $country, array $segments, array $destinationContext = [], array $context = []): array
    {
        $productIds = collect($segments)
            ->flatMap(fn (array $segment) => collect($segment['items'] ?? [])->pluck('product_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()->values()->all();
        $packagingStandards = $context['packaging_standards'] ?? $this->packaging->forProductIds($productIds);
        $shippingWeightStandards = $context['shipping_weight_standards'] ?? $this->shippingWeights->forProductIds($productIds);

        $packagingCosts = $context['packaging_costs'] ?? Product::query()->whereIn('id', $productIds)->get(['id', 'standard_packaging_cost', 'cost_currency'])->mapWithKeys(fn (Product $product): array => [(int) $product->id => ['amount' => $product->standard_packaging_cost, 'currency' => $product->cost_currency]])->all();

        $allComponents = [];
        $segmentEvidence = [];
        foreach ($segments as $segment) {
            $packagingCost = $this->packagingCostForSegment($segment, $packagingCosts);
            if (($packagingCost['status'] ?? null) !== 'ready') {
                return $packagingCost;
            }
            $allComponents = [...$allComponents, ...$packagingCost['components']];
            $sourceType = (string) ($segment['source_type'] ?? '');
            $sourceCode = strtoupper((string) ($segment['source_code'] ?? ''));
            $carrier = trim((string) ($segment['carrier'] ?? ''));

            if ($sourceType === 'factory' && $carrier === '4PX') {
                $measurement = $this->shippingWeightForSegment($segment, $shippingWeightStandards);
                if ($measurement['status'] !== 'ready') {
                    return $measurement;
                }
                $routing = $this->china->calculate(
                    $country,
                    $measurement['actual_weight_kg'],
                    $segment['items'] ?? [],
                    $destinationContext,
                );
            } else {
                $measurement = $this->packagingForSegment($segment, $packagingStandards);
                if ($measurement['status'] !== 'ready') {
                    return $measurement;
                }

                if ($sourceType === 'factory') {
                    $routing = [
                        'status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null,
                        'reason_code' => 'factory_carrier_rate_unavailable',
                        'reason' => "No frozen automatic routing rate for factory carrier {$carrier}",
                    ];
                } else {
                    $routing = $sourceCode === 'DE'
                        ? $this->dhl->calculate($country, $measurement['actual_weight_kg'])
                        : [
                            'status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null,
                            'reason_code' => 'routing_rate_unavailable',
                            'reason' => 'No frozen automatic routing rate for source',
                        ];
                }
            }

            if (($routing['status'] ?? null) !== 'ready') {
                return [...$routing, 'packaging' => $measurement];
            }
            $allComponents = [...$allComponents, ...$routing['components']];
            $segmentEvidence[] = ['source_code' => $sourceCode, 'packaging' => $measurement, 'packaging_cost' => $packagingCost, 'routing' => $routing];
        }

        $fxResult = $this->fx->convertToCny(array_map(
            fn (array $component): array => ['currency' => $component['currency'], 'revenue' => $component['amount']],
            $allComponents,
        ));
        if (! ($fxResult['available'] ?? false)) {
            return [
                'status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null,
                'reason_code' => $fxResult['reason'] ?? 'fx_unavailable',
                'reason' => 'ERP Settings FX conversion unavailable',
                'components' => $allComponents,
                'segments' => $segmentEvidence,
            ];
        }

        $provenances = collect($segmentEvidence)->pluck('packaging.provenance')->filter()->unique()->values();
        $provenance = $provenances->count() === 1
            ? (string) $provenances->first()
            : 'mixed-measurement-evidence';

        return [
            'status' => 'ready',
            'amount' => round((float) $fxResult['amount_cny'], 4),
            'currency' => 'CNY',
            'source' => 'automatic-rate-engine',
            'components' => $allComponents,
            'segments' => $segmentEvidence,
            'fx' => [
                'source' => $fxResult['source'] ?? null,
                'reference_date' => $fxResult['reference_date'] ?? null,
                'stale' => (bool) ($fxResult['stale'] ?? false),
                'conversions' => $fxResult['conversions'] ?? [],
            ],
            'provenance' => $provenance,
            'possible_surcharges' => collect($segmentEvidence)->flatMap(fn (array $row) => $row['routing']['possible_surcharges'] ?? [])->unique()->values()->all(),
        ];
    }

    /** @param list<array<string,mixed>> $segments @param array<string,mixed> $destinationContext @param array<string,mixed> $context */
    public function estimateRoutingOnly(string $country, array $segments, array $destinationContext = [], array $context = []): array
    {
        $productIds = collect($segments)
            ->flatMap(fn (array $segment) => collect($segment['items'] ?? [])->pluck('product_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()->values()->all();
        $packagingStandards = $context['packaging_standards'] ?? $this->packaging->forProductIds($productIds);
        $shippingWeightStandards = $context['shipping_weight_standards'] ?? $this->shippingWeights->forProductIds($productIds);

        $allComponents = [];
        $segmentEvidence = [];
        foreach ($segments as $segment) {
            $sourceType = (string) ($segment['source_type'] ?? '');
            $sourceCode = strtoupper((string) ($segment['source_code'] ?? ''));
            $carrier = trim((string) ($segment['carrier'] ?? ''));

            if ($sourceType === 'factory' && $carrier === '4PX') {
                $measurement = $this->shippingWeightForSegment($segment, $shippingWeightStandards);
                if ($measurement['status'] !== 'ready') return $measurement;
                $routing = $this->china->calculate($country, $measurement['actual_weight_kg'], $segment['items'] ?? [], $destinationContext);
            } else {
                $measurement = $this->packagingForSegment($segment, $packagingStandards);
                if ($measurement['status'] !== 'ready') return $measurement;
                if ($sourceType === 'factory') {
                    $routing = [
                        'status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null,
                        'reason_code' => 'factory_carrier_rate_unavailable',
                        'reason' => "No frozen automatic routing rate for factory carrier {$carrier}",
                    ];
                } else {
                    $routing = $sourceCode === 'DE'
                        ? $this->dhl->calculate($country, $measurement['actual_weight_kg'])
                        : [
                            'status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null,
                            'reason_code' => 'routing_rate_unavailable',
                            'reason' => 'No frozen automatic routing rate for source',
                        ];
                }
            }

            if (($routing['status'] ?? null) !== 'ready') return [...$routing, 'measurement' => $measurement];
            $allComponents = [...$allComponents, ...$routing['components']];
            $segmentEvidence[] = ['source_code' => $sourceCode, 'measurement' => $measurement, 'routing' => $routing];
        }

        $fxResult = $this->fx->convertToCny(array_map(
            fn (array $component): array => ['currency' => $component['currency'], 'revenue' => $component['amount']],
            $allComponents,
        ));
        if (! ($fxResult['available'] ?? false)) {
            return [
                'status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null,
                'reason_code' => $fxResult['reason'] ?? 'fx_unavailable',
                'reason' => 'ERP Settings FX conversion unavailable',
                'components' => $allComponents, 'segments' => $segmentEvidence,
            ];
        }

        return [
            'status' => 'ready',
            'amount' => round((float) $fxResult['amount_cny'], 4),
            'currency' => 'CNY',
            'source' => 'automatic-rate-engine-routing-only',
            'components' => $allComponents,
            'segments' => $segmentEvidence,
            'fx' => [
                'source' => $fxResult['source'] ?? null,
                'reference_date' => $fxResult['reference_date'] ?? null,
                'stale' => (bool) ($fxResult['stale'] ?? false),
                'conversions' => $fxResult['conversions'] ?? [],
            ],
            'possible_surcharges' => collect($segmentEvidence)->flatMap(fn (array $row) => $row['routing']['possible_surcharges'] ?? [])->unique()->values()->all(),
        ];
    }

    /** @param array<string,mixed> $segment @param array<int,array<string,mixed>> $standards */
    private function packagingCostForSegment(array $segment, array $standards): array
    {
        $components = [];
        foreach ($segment['items'] ?? [] as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            $standard = $standards[$productId] ?? null;
            if ($quantity <= 0 || ! is_array($standard) || ! is_numeric($standard['amount'] ?? null) || (float) $standard['amount'] < 0 || blank($standard['currency'] ?? null)) {
                return [
                    'status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null,
                    'reason_code' => 'missing_packaging_cost',
                    'reason' => 'Standard packaging cost and currency are required for fulfillment ranking',
                    'product_id' => $productId,
                    'components' => [],
                ];
            }
            $components[] = [
                'key' => 'packaging_cost_'.$productId,
                'label' => 'Standard packaging cost',
                'amount' => round((float) $standard['amount'] * $quantity, 4),
                'currency' => strtoupper(trim((string) $standard['currency'])),
                'source' => 'product-standard-packaging-cost',
                'product_id' => $productId,
                'quantity' => $quantity,
            ];
        }

        return ['status' => 'ready', 'components' => $components];
    }

    private function shippingWeightForSegment(array $segment, array $standards): array
    {
        $actual = 0.0;
        $evidenceIds = [];
        foreach ($segment['items'] ?? [] as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            $standard = $standards[$productId] ?? null;
            if ($quantity <= 0 || ! is_array($standard) || ! is_numeric($standard['shipping_weight_kg'] ?? null) || (float) $standard['shipping_weight_kg'] <= 0) {
                return [
                    'status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null,
                    'reason_code' => 'missing_shipping_reference_weight',
                    'reason' => 'Adopted shipping reference weight is required for actual-weight factory routing',
                    'product_id' => $productId,
                ];
            }
            $actual += (float) $standard['shipping_weight_kg'] * $quantity;
            if (isset($standard['evidence_id'])) {
                $evidenceIds[] = (int) $standard['evidence_id'];
            }
        }

        return [
            'status' => 'ready',
            'actual_weight_kg' => round($actual, 6),
            'volume_cm3' => null,
            'provenance' => 'approved-shipping-reference-weight',
            'evidence_ids' => array_values(array_unique($evidenceIds)),
        ];
    }

    private function packagingForSegment(array $segment, array $standards): array
    {
        $actual = 0.0;
        $volume = 0.0;
        foreach ($segment['items'] ?? [] as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            $standard = $standards[$productId] ?? null;
            if ($quantity <= 0 || ! is_array($standard) || ! $this->hasPackaging($standard)) {
                return [
                    'status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null,
                    'reason_code' => 'missing_packaging_standard',
                    'reason' => 'Approved product packaging standard is required',
                ];
            }
            $actual += (float) $standard['packaged_weight_kg'] * $quantity;
            $volume += (float) $standard['packaging_length_cm'] * (float) $standard['packaging_width_cm'] * (float) $standard['packaging_height_cm'] * $quantity;
        }

        return [
            'status' => 'ready',
            'actual_weight_kg' => round($actual, 6),
            'volume_cm3' => round($volume, 6),
            'provenance' => 'approved-packaging-standard',
        ];
    }

    private function hasPackaging(array $standard): bool
    {
        foreach (['packaged_weight_kg', 'packaging_length_cm', 'packaging_width_cm', 'packaging_height_cm'] as $key) {
            if (! is_numeric($standard[$key] ?? null) || (float) $standard[$key] <= 0) {
                return false;
            }
        }

        return true;
    }
}
