<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use Illuminate\Support\Collection;

/**
 * Read-only planner for comparing single-shipment and split-shipment fulfillment.
 * It never reserves stock, creates shipments, or marks orders shipped.
 */
final class FulfillmentPlanPreviewService
{
    private const MAX_SPLIT_PLANS = 64;

    public function __construct(
        private readonly InventoryAvailabilityReader $inventory,
        private readonly WarehouseRoutingPolicy $routing,
        private readonly CarrierRoutingPolicy $carrierRouting,
        private readonly UsFulfillmentZoneResolver $physicalZones,
        private readonly ?FulfillmentPlanCostEstimator $costEstimator = null,
    ) {}

    /** @param array<int,int> $productIds @return array<string,mixed> */
    public function prepare(array $productIds): array
    {
        return $this->costEstimator?->prepare($productIds) ?? [];
    }

    /**
     * @param  array<int,array{product_id:int|null,quantity:int,sku?:?string}>  $items
     * @param  array<string,mixed>  $destinationContext
     * @param  array<string,mixed>  $costEvidence  plan_costs[plan_key] => amount/currency/source
     * @return array<string,mixed>
     */
    public function preview(
        ?string $country,
        array $items,
        array $destinationContext = [],
        array $costEvidence = [],
        ?array $context = null,
    ): array {
        $countryCode = strtoupper(trim((string) $country));
        $requirements = $this->requirements($items);
        if ($countryCode === '' || $requirements === null || $requirements->isEmpty()) {
            return [
                'status' => 'blocked',
                'reason_code' => $countryCode === '' ? 'missing_country' : 'unresolved_or_empty_items',
                'plans' => [],
                'comparison' => $this->emptyComparison(),
            ];
        }

        $military = $countryCode === 'US' && $this->routing->isUsMilitaryDestination($destinationContext);
        $akHi = $countryCode === 'US' && $this->routing->isUsAkHiDestination($destinationContext);
        $factoryAllowed = $this->routing->factoryAllowed($destinationContext);
        $warehouseCodes = $this->routing->warehouseCodes($countryCode);
        $physicalZone = null;
        if ($countryCode === 'US' && $this->routing->strategy($countryCode) === 'physical_zone_then_priority'
            && ! $military && ! $akHi) {
            $physicalZone = $this->physicalZones->resolve(
                $destinationContext['postal_code'] ?? $destinationContext['zip'] ?? null,
                $destinationContext,
            );
            $warehouseCodes = ($physicalZone['status'] ?? null) === 'matched'
                ? $this->physicalZones->priorityFor($physicalZone)
                : [];
        }
        $warehouses = collect($context['warehouses'] ?? $this->inventory->activeWarehouses())
            ->filter(fn (array $warehouse): bool => (bool) ($warehouse['can_auto_allocate'] ?? false))
            ->filter(function (array $warehouse) use ($warehouseCodes): bool {
                $canonical = $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? ''));

                return $canonical !== null && in_array($canonical, $warehouseCodes, true);
            })->values();
        $availability = $context['availability'] ?? $this->inventory->forProductIds($requirements->keys()->all());
        $planContext = $context ?? [];
        $measurementAvailability = $planContext['measurement_availability']
            ?? $this->costEstimator?->measurementAvailability($requirements->keys()->all(), $planContext);
        $inventoryOnly = is_array($measurementAvailability)
            && ($measurementAvailability['status'] ?? null) === 'missing_weight';
        if ($inventoryOnly) {
            $planContext['inventory_only_missing_weight'] = true;
        }

        $plans = [];
        foreach ($warehouses as $warehouse) {
            if ($this->covers($warehouse, $requirements, $availability)) {
                $plans[] = $this->singleWarehousePlan($countryCode, $warehouse, $requirements, $costEvidence, $destinationContext, $planContext);
            }
        }
        if (! $inventoryOnly && $factoryAllowed) {
            $plans[] = $this->factoryPlan($countryCode, $requirements, $costEvidence, $destinationContext, $planContext);
        }

        if (! $inventoryOnly && $this->routing->splitShipmentAllowed()) {
            foreach ($this->splitPlans($countryCode, $requirements, $warehouses, $availability, $factoryAllowed, $costEvidence, $destinationContext, $planContext) as $plan) {
                $plans[] = $plan;
            }
        }

        $plans = collect($plans)->unique('plan_key')->values()->all();
        $comparison = $inventoryOnly ? $this->inventoryOnlyComparison() : $this->comparison($plans);
        $reasonCode = $inventoryOnly
            ? ($plans === [] ? 'insufficient_stock' : 'inventory_only_manual_warehouse_selection')
            : ($plans === [] ? 'no_fulfillment_plan' : 'fulfillment_plans_ready');

        return [
            'status' => $plans === [] ? 'blocked' : 'preview',
            'reason_code' => $reasonCode,
            'country_code' => $countryCode,
            'constraints' => [
                'military_address' => $military,
                'ak_hi' => $akHi,
                'factory_allowed' => $factoryAllowed,
                'must_use_us_warehouse' => $military,
                'split_allowed' => $this->routing->splitShipmentAllowed(),
                'automatic_split' => $this->routing->automaticSplitShipmentEnabled(),
                'split_execution_mode' => $this->routing->splitExecutionMode(),
                'human_review_required' => $inventoryOnly || $akHi || $military
                    || ($physicalZone !== null && ($physicalZone['status'] ?? null) !== 'matched')
                    || collect($plans)->contains(fn (array $plan): bool => (bool) ($plan['is_split'] ?? false)),
                'us_physical_zone' => $physicalZone,
            ],
            'plans' => $plans,
            'comparison' => $comparison,
            'fallback_policy' => [
                'mode' => $inventoryOnly ? 'inventory_only_missing_weight' : 'strict_measurement',
                'cost_ranking_enabled' => ! $inventoryOnly,
                'warehouse_auto_recommendation_enabled' => ! $inventoryOnly,
                'missing_weight_product_ids' => $measurementAvailability['missing_weight_product_ids'] ?? [],
                'next_action' => $inventoryOnly
                    ? ($plans === [] ? 'procurement' : 'manual_warehouse_selection')
                    : 'strict_routing',
            ],
            'cost_evidence_contract' => [
                'shape' => 'plan_costs[plan_key] = {amount,currency,source}',
                'ranking_requires_same_currency' => true,
                'missing_cost_behavior' => 'show_plan_without_cost_and_require_human_review',
            ],
            'execution_boundary' => [
                'split_plan' => 'preview_only_until_multi_shipment_multi_reservation_gate',
                'single_warehouse_plan' => 'existing_single_shipment_flow',
                'factory_plan' => 'manual_review_only',
                'auto_ship' => false,
            ],
        ];
    }

    /** @return Collection<int,int>|null */
    private function requirements(array $items): ?Collection
    {
        $requirements = collect();
        foreach ($items as $item) {
            $productId = $item['product_id'] ?? null;
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($productId === null || $quantity <= 0) {
                return null;
            }
            $requirements[(int) $productId] = (int) ($requirements[(int) $productId] ?? 0) + $quantity;
        }

        return $requirements->sortKeys();
    }

    private function covers(array $warehouse, Collection $requirements, array $availability): bool
    {
        foreach ($requirements as $productId => $quantity) {
            if ($this->available($warehouse, (int) $productId, $availability) < (int) $quantity) {
                return false;
            }
        }

        return true;
    }

    private function available(array $warehouse, int $productId, array $availability): int
    {
        $row = collect($availability[$productId] ?? [])->first(
            fn (array $item): bool => (int) ($item['warehouse_id'] ?? 0) === (int) ($warehouse['id'] ?? 0)
        );

        return max(0, (int) ($row['quantity_available'] ?? 0));
    }

    private function singleWarehousePlan(string $countryCode, array $warehouse, Collection $requirements, array $costEvidence, array $destinationContext, array $context): array
    {
        $code = $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? '')) ?? (string) ($warehouse['code'] ?? '');
        $route = $this->carrierRouting->warehouseRoute($countryCode, $code);
        $segments = [[
            'source_type' => 'warehouse',
            'source_code' => $code,
            'warehouse_id' => (int) $warehouse['id'],
            'warehouse_name' => (string) $warehouse['name'],
            'source_class' => $this->sourceClass($countryCode, $code),
            'carrier' => $route['carrier'] ?? null,
            'service' => $route['service'] ?? null,
            'carrier_selection_source' => filled($route['carrier'] ?? null) ? 'automatic' : null,
            'items' => $this->items($requirements),
        ]];

        return $this->plan('single', $segments, $costEvidence, true, $countryCode, $destinationContext, $context);
    }

    private function factoryPlan(string $countryCode, Collection $requirements, array $costEvidence, array $destinationContext, array $context): array
    {
        $factory = array_replace($this->routing->factoryFallback(), $this->carrierRouting->factoryRoute($countryCode));
        $segments = [[
            'source_type' => 'factory',
            'source_code' => (string) ($factory['warehouse_code'] ?? 'FACTORY'),
            'warehouse_id' => null,
            'warehouse_name' => (string) ($factory['label'] ?? '中国工厂'),
            'source_class' => 'china_factory',
            'carrier' => (string) ($factory['carrier'] ?? '4PX'),
            'service' => $factory['service'] ?? null,
            'rate_source' => $factory['rate_source'] ?? null,
            'carrier_selection_source' => 'automatic',
            'items' => $this->items($requirements),
        ]];

        return $this->plan('factory', $segments, $costEvidence, false, $countryCode, $destinationContext, $context);
    }

    /** @return list<array<string,mixed>> */
    private function splitPlans(
        string $countryCode,
        Collection $requirements,
        Collection $warehouses,
        array $availability,
        bool $factoryAllowed,
        array $costEvidence,
        array $destinationContext,
        array $context,
    ): array {
        if ($requirements->count() < 2) {
            return [];
        }

        $choices = [];
        foreach ($requirements as $productId => $quantity) {
            $sources = [];
            foreach ($warehouses as $warehouse) {
                if ($this->available($warehouse, (int) $productId, $availability) >= (int) $quantity) {
                    $code = $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? '')) ?? (string) $warehouse['code'];
                    $route = $this->carrierRouting->warehouseRoute($countryCode, $code);
                    $sources[] = [
                        'source_type' => 'warehouse',
                        'source_code' => $code,
                        'warehouse_id' => (int) $warehouse['id'],
                        'warehouse_name' => (string) $warehouse['name'],
                        'source_class' => $this->sourceClass($countryCode, $code),
                        'carrier' => $route['carrier'] ?? null,
                        'service' => $route['service'] ?? null,
                        'carrier_selection_source' => filled($route['carrier'] ?? null) ? 'automatic' : null,
                    ];
                }
            }
            if ($factoryAllowed) {
                $factory = array_replace($this->routing->factoryFallback(), $this->carrierRouting->factoryRoute($countryCode));
                $sources[] = [
                    'source_type' => 'factory',
                    'source_code' => (string) ($factory['warehouse_code'] ?? 'FACTORY'),
                    'warehouse_id' => null,
                    'warehouse_name' => (string) ($factory['label'] ?? '中国工厂'),
                    'source_class' => 'china_factory',
                    'carrier' => (string) ($factory['carrier'] ?? '4PX'),
                    'service' => $factory['service'] ?? null,
                    'rate_source' => $factory['rate_source'] ?? null,
                    'carrier_selection_source' => 'automatic',
                ];
            }
            if ($sources === []) {
                return [];
            }
            $choices[] = ['product_id' => (int) $productId, 'quantity' => (int) $quantity, 'sources' => $sources];
        }

        $assignments = [[]];
        foreach ($choices as $choice) {
            $next = [];
            foreach ($assignments as $assignment) {
                foreach ($choice['sources'] as $source) {
                    $next[] = [...$assignment, ['product_id' => $choice['product_id'], 'quantity' => $choice['quantity'], 'source' => $source]];
                    if (count($next) >= self::MAX_SPLIT_PLANS * 4) {
                        break 2;
                    }
                }
            }
            $assignments = $next;
        }

        $plans = [];
        foreach ($assignments as $assignment) {
            $groups = [];
            foreach ($assignment as $row) {
                $source = $row['source'];
                $key = $source['source_type'].':'.$source['source_code'];
                $groups[$key] ??= [...$source, 'items' => []];
                $groups[$key]['items'][] = ['product_id' => $row['product_id'], 'quantity' => $row['quantity']];
            }
            if (count($groups) !== 2) {
                continue;
            }
            $groupRows = array_values($groups);
            $factoryCount = collect($groupRows)->where('source_type', 'factory')->count();
            $warehouseCount = collect($groupRows)->where('source_type', 'warehouse')->count();
            if ($factoryCount !== 1 || $warehouseCount !== 1) {
                continue;
            }
            $plans[] = $this->plan('split', $groupRows, $costEvidence, false, $countryCode, $destinationContext, $context);
            if (count($plans) >= self::MAX_SPLIT_PLANS) {
                break;
            }
        }

        return collect($plans)->unique('plan_key')->values()->all();
    }

    /** @param list<array<string,mixed>> $segments */
    private function plan(string $type, array $segments, array $costEvidence, bool $singleExecutable, string $countryCode, array $destinationContext, array $context): array
    {
        usort($segments, fn (array $a, array $b): int => strcmp((string) $a['source_code'], (string) $b['source_code']));
        foreach ($segments as &$segment) {
            usort($segment['items'], fn (array $a, array $b): int => ((int) $a['product_id']) <=> ((int) $b['product_id']));
        }
        unset($segment);

        $signature = implode('|', array_map(function (array $segment): string {
            $items = implode(',', array_map(fn (array $item): string => $item['product_id'].'x'.$item['quantity'], $segment['items']));

            return $segment['source_code'].'['.$items.']';
        }, $segments));
        $planKey = $type.':'.substr(hash('sha256', $signature), 0, 16);
        $cost = $this->cost($planKey, $costEvidence, $countryCode, $segments, $destinationContext, $context);

        return [
            'plan_key' => $planKey,
            'plan_type' => $type,
            'is_split' => count($segments) > 1,
            'shipment_count' => count($segments),
            'segments' => $segments,
            'cost' => $cost,
            'human_review_required' => count($segments) > 1 || $type === 'factory',
            'execution_status' => count($segments) > 1
                ? 'preview_only_multi_shipment_schema_required'
                : ($singleExecutable ? 'current_single_shipment_supported' : 'manual_review_only'),
        ];
    }

    /** @return array<string,mixed> */
    private function cost(string $planKey, array $costEvidence, string $countryCode, array $segments, array $destinationContext, array $context): array
    {
        if ((bool) ($context['inventory_only_missing_weight'] ?? false)) {
            return [
                'status' => 'not_evaluated',
                'amount' => null,
                'currency' => null,
                'source' => null,
                'reason_code' => 'missing_product_weight',
                'reason' => 'Product weight is missing; Owner policy skips fulfillment cost ranking.',
            ];
        }

        $evidence = $costEvidence['plan_costs'][$planKey] ?? null;
        if (! is_array($evidence) || ! is_numeric($evidence['amount'] ?? null) || (float) $evidence['amount'] < 0 || blank($evidence['currency'] ?? null)) {
            if ($this->costEstimator !== null) {
                return $this->costEstimator->estimate($countryCode, $segments, $destinationContext, $context);
            }

            return ['status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null];
        }

        return [
            'status' => 'ready',
            'amount' => round((float) $evidence['amount'], 4),
            'currency' => strtoupper(trim((string) $evidence['currency'])),
            'source' => isset($evidence['source']) ? (string) $evidence['source'] : null,
        ];
    }

    /** @param list<array<string,mixed>> $plans */
    private function comparison(array $plans): array
    {
        $costed = collect($plans)->filter(fn (array $plan): bool => ($plan['cost']['status'] ?? null) === 'ready')->values();
        if ($costed->isEmpty()) {
            return $this->emptyComparison();
        }
        $currencies = $costed->pluck('cost.currency')->unique()->values();
        if ($currencies->count() !== 1) {
            return [
                'status' => 'currency_mismatch',
                'currency' => null,
                'lowest_cost_plan_key' => null,
                'best_single_plan_key' => null,
                'best_split_plan_key' => null,
                'split_saving' => null,
            ];
        }

        $sorted = $costed->sortBy('cost.amount')->values();
        $single = $costed->filter(fn (array $plan): bool => ! ($plan['is_split'] ?? false))->sortBy('cost.amount')->first();
        $split = $costed->filter(fn (array $plan): bool => (bool) ($plan['is_split'] ?? false))->sortBy('cost.amount')->first();
        $saving = ($single && $split) ? round((float) $single['cost']['amount'] - (float) $split['cost']['amount'], 4) : null;

        return [
            'status' => $costed->count() === count($plans) ? 'ready' : 'partial_evidence',
            'currency' => (string) $currencies->first(),
            'lowest_cost_plan_key' => $sorted->first()['plan_key'] ?? null,
            'best_single_plan_key' => $single['plan_key'] ?? null,
            'best_split_plan_key' => $split['plan_key'] ?? null,
            'split_saving' => $saving,
        ];
    }

    /** @return array<string,mixed> */
    private function inventoryOnlyComparison(): array
    {
        return [
            'status' => 'not_evaluated_missing_weight',
            'currency' => null,
            'lowest_cost_plan_key' => null,
            'best_single_plan_key' => null,
            'best_split_plan_key' => null,
            'split_saving' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyComparison(): array
    {
        return [
            'status' => 'missing_evidence',
            'currency' => null,
            'lowest_cost_plan_key' => null,
            'best_single_plan_key' => null,
            'best_split_plan_key' => null,
            'split_saving' => null,
        ];
    }

    /** @return list<array{product_id:int,quantity:int}> */
    private function items(Collection $requirements): array
    {
        return $requirements->map(fn (int $quantity, int $productId): array => ['product_id' => $productId, 'quantity' => $quantity])->values()->all();
    }

    private function sourceClass(string $countryCode, string $warehouseCode): string
    {
        if ($countryCode === 'US' && str_starts_with($warehouseCode, 'US-')) {
            return 'local_country_warehouse';
        }
        if ($countryCode === 'CA' && $warehouseCode === 'CA') {
            return 'local_country_warehouse';
        }
        if ($countryCode === 'DE' && $warehouseCode === 'DE') {
            return 'local_country_warehouse';
        }

        return 'approved_nearby_warehouse';
    }
}
