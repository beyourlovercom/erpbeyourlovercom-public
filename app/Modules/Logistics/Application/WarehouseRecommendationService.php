<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use Illuminate\Support\Collection;

final class WarehouseRecommendationService
{
    public function __construct(
        private readonly InventoryAvailabilityReader $inventory,
        private readonly WarehouseRoutingPolicy $routing,
        private readonly UspsZoneResolver $zones,
        private readonly UsFulfillmentZoneResolver $physicalZones,
    ) {}

    /** @param array<int,int> $productIds */
    public function prepare(array $productIds): array
    {
        $warehouses = $this->inventory->activeWarehouses();

        return ['warehouses' => $warehouses, 'availability' => $this->inventory->forProductIds($productIds)];
    }

    /** @param array<int, array{product_id:int|null,quantity:int}> $items */
    public function recommend(
        ?string $country,
        array $items,
        ?string $postalCode = null,
        array $costEvidence = [],
        array $destinationContext = [],
        ?array $context = null,
    ): array {
        $countryCode = strtoupper(trim((string) $country));
        if ($countryCode === '') {
            return $this->blocked('missing_country', '订单缺少国家代码，无法自动推荐仓库。');
        }
        if (! preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return $this->blocked('invalid_country_code', '订单国家必须使用 ISO 2 位代码后才能自动推荐。');
        }

        $requirements = $this->requirements($items);
        if ($requirements === null) {
            return $this->blocked('unresolved_product', '订单存在未关联 Product Master 的商品，需人工处理。');
        }
        if ($requirements->isEmpty()) {
            return $this->blocked('no_items', '订单没有可用于库存判断的商品。');
        }

        $context ??= $this->prepare($requirements->keys()->all());
        $warehouses = collect($context['warehouses'])
            ->filter(fn (array $warehouse): bool => (bool) ($warehouse['can_auto_allocate'] ?? false));
        if ($warehouses->isEmpty()) {
            return $this->blocked('no_inventory_authority', '当前没有具备 Full Snapshot 权威库存的仓库。');
        }

        $strategy = $this->routing->strategy($countryCode);
        if ($strategy === 'blocked') {
            return $this->blocked(
                'country_policy_blocked',
                $this->routing->blockedReason($countryCode) ?? "{$countryCode} 当前 Policy 不使用德国仓。",
                $countryCode,
            );
        }

        if (($context['measurement_availability']['status'] ?? null) === 'missing_weight') {
            return $this->inventoryOnlyFallback($countryCode, $postalCode, $destinationContext, $requirements, $warehouses, $context);
        }

        if ($countryCode === 'US' && $this->routing->isUsAkHiDestination($destinationContext)) {
            $state = strtoupper(trim((string) ($destinationContext['province_state'] ?? $destinationContext['state'] ?? '')));

            return $this->blocked(
                'us_ak_hi_cost_review_required',
                "{$state} 订单必须比较美国 USPS 与中国工厂 SD经济挂号-带电成本后由人工选择，不提供一键美国仓推荐。",
                $countryCode,
                [
                    'strategy' => 'cost_compare_us_vs_factory',
                    'state' => $state,
                    'factory_allowed' => true,
                    'human_review_required' => true,
                ],
            );
        }

        if ($strategy === 'fixed') {
            return $this->recommendFixedWarehouse($countryCode, $requirements, $warehouses, $context);
        }
        if ($strategy === 'de_preferred') {
            return $this->recommendDePreferred($countryCode, $requirements, $warehouses, $context);
        }
        if ($strategy === 'de_cost_gate') {
            return $this->recommendDeCostGate($countryCode, $requirements, $warehouses, $costEvidence, $context);
        }
        if ($strategy === 'physical_zone_then_priority') {
            if ($countryCode === 'US' && $this->routing->isUsMilitaryDestination($destinationContext)) {
                return $this->recommendMilitaryUsLocal($countryCode, $requirements, $warehouses, $context);
            }

            return $this->recommendPhysicalZoneThenPriority($countryCode, $postalCode, $destinationContext, $requirements, $warehouses, $context);
        }
        if ($strategy === 'east_zone_then_priority') {
            $result = $this->recommendEastZoneThenPriority($countryCode, $postalCode, $requirements, $warehouses, $context);
            if ($countryCode === 'US' && $this->routing->isUsMilitaryDestination($destinationContext)) {
                return $this->militaryResult($result);
            }

            return $result;
        }

        return $this->recommendServiceRegion($countryCode, $requirements, $warehouses, $context);
    }

    private function inventoryOnlyFallback(
        string $countryCode,
        ?string $postalCode,
        array $destinationContext,
        Collection $requirements,
        Collection $warehouses,
        array $context,
    ): array {
        $allowedCodes = $this->routing->warehouseCodes($countryCode);
        $policyWarehouses = $allowedCodes === []
            ? $warehouses
            : $warehouses->filter(function (array $warehouse) use ($allowedCodes): bool {
                $canonical = $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? ''));

                return $canonical !== null && in_array($canonical, $allowedCodes, true);
            })->values();

        if ($this->routing->strategy($countryCode) === null && $allowedCodes === []) {
            $policyWarehouses = $warehouses->filter(function (array $warehouse) use ($countryCode): bool {
                return collect($warehouse['service_regions'] ?? [])->contains(
                    fn (array $region): bool => strtoupper((string) ($region['country_code'] ?? '')) === $countryCode
                );
            })->values();
        }

        $physicalZone = null;
        if ($countryCode === 'US' && $this->routing->strategy($countryCode) === 'physical_zone_then_priority'
            && ! $this->routing->isUsMilitaryDestination($destinationContext)
            && ! $this->routing->isUsAkHiDestination($destinationContext)) {
            $physicalZone = $this->physicalZones->resolve($postalCode, $destinationContext);
            if (($physicalZone['status'] ?? null) !== 'matched') {
                return $this->blocked(
                    'us_physical_zone_unresolved',
                    '美国收货地址的 ZIP Code / 州无法可靠确定美东或美西区域，请 Vita 人工判断。',
                    $countryCode,
                    $this->physicalRoutingContext($physicalZone),
                );
            }
            $zoneCodes = $this->physicalZones->priorityFor($physicalZone);
            $policyWarehouses = $policyWarehouses->filter(function (array $warehouse) use ($zoneCodes): bool {
                $canonical = $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? ''));
                return $canonical !== null && in_array($canonical, $zoneCodes, true);
            })->values();
        }

        if ($policyWarehouses->isEmpty()) {
            return $this->blocked('no_service_region_match', "没有可用仓库覆盖 {$countryCode}。", $countryCode, $physicalZone ? $this->physicalRoutingContext($physicalZone) : null);
        }

        $eligible = $policyWarehouses->filter(
            fn (array $warehouse): bool => $this->covers($warehouse, $requirements, $context['availability'])
        )->values();
        $candidates = $eligible->map(fn (array $warehouse): array => [
            'id' => (int) $warehouse['id'],
            'slug' => (string) $warehouse['slug'],
            'code' => $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? '')) ?? (string) $warehouse['code'],
            'name' => (string) $warehouse['name'],
        ])->values()->all();
        $missing = array_values(array_map('intval', (array) ($context['measurement_availability']['missing_weight_product_ids'] ?? [])));

        if ($eligible->isEmpty()) {
            return [
                'status' => 'blocked',
                'reason_code' => 'insufficient_stock',
                'reason_label' => '产品缺少重量且当前没有单一可用仓库能整单满足库存；进入缺货/采购流程。',
                'country_code' => $countryCode,
                'required_sku_count' => $requirements->count(),
                'candidate_count' => 0,
                'warehouse' => null,
                'routing_context' => [
                    'strategy' => 'inventory_only_missing_weight',
                    'physical_zone' => $physicalZone,
                    'cost_ranking_enabled' => false,
                    'manual_selection_required' => true,
                    'missing_weight_product_ids' => $missing,
                    'candidate_warehouses' => [],
                    'next_action' => 'procurement',
                ],
            ];
        }

        return [
            'status' => 'blocked',
            'reason_code' => 'missing_product_weight_inventory_only',
            'reason_label' => '产品缺少重量，已跳过成本比较和最优仓推荐；仅按库存列出可整单发货仓库，请人工选择。',
            'country_code' => $countryCode,
            'required_sku_count' => $requirements->count(),
            'candidate_count' => count($candidates),
            'warehouse' => null,
            'routing_context' => [
                'strategy' => 'inventory_only_missing_weight',
                'physical_zone' => $physicalZone,
                'cost_ranking_enabled' => false,
                'manual_selection_required' => true,
                'missing_weight_product_ids' => $missing,
                'candidate_warehouses' => $candidates,
                'next_action' => 'manual_warehouse_selection',
            ],
        ];
    }

    private function recommendFixedWarehouse(
        string $countryCode,
        Collection $requirements,
        Collection $warehouses,
        array $context,
    ): array {
        $fixedCode = $this->routing->fixedWarehouseCode($countryCode);
        if ($fixedCode === null) {
            return $this->blocked('fixed_warehouse_unconfigured', '固定仓策略未配置唯一仓库。', $countryCode);
        }

        $warehouse = $warehouses->first(fn (array $candidate): bool => $this->routing->canonicalWarehouseCode((string) ($candidate['code'] ?? '')) === $fixedCode
        );
        if ($warehouse === null) {
            return $this->blocked('no_inventory_authority', "固定仓 {$fixedCode} 当前没有 Full Snapshot 权威库存。", $countryCode);
        }

        $region = collect($warehouse['service_regions'] ?? [])->first(
            fn (array $item): bool => strtoupper((string) ($item['country_code'] ?? '')) === $countryCode
        );
        if ($region === null) {
            return $this->blocked('no_service_region_match', "固定仓 {$fixedCode} 尚未配置 {$countryCode} 服务区域。", $countryCode);
        }

        if (! $this->covers($warehouse, $requirements, $context['availability'])) {
            return $this->blocked('insufficient_stock', "固定仓 {$fixedCode} 无法一次满足订单全部商品数量。", $countryCode);
        }

        return $this->recommended(
            $warehouse,
            $countryCode,
            $requirements->count(),
            1,
            'recommended_fixed_warehouse',
            "{$countryCode} 固定由 {$fixedCode} 履约，且库存可整单满足。",
        );
    }

    private function recommendDePreferred(
        string $countryCode,
        Collection $requirements,
        Collection $warehouses,
        array $context,
    ): array {
        $warehouse = $this->deWarehouse($countryCode, $requirements, $warehouses, $context);
        if (isset($warehouse['blocked'])) {
            return $warehouse['blocked'];
        }

        return $this->recommended(
            $warehouse,
            $countryCode,
            $requirements->count(),
            1,
            'recommended_de_preferred',
            "{$countryCode} 属于德国仓优先国家，DE 可整单履约，推荐德国仓。",
            ['strategy' => 'de_preferred', 'carrier' => 'DHL'],
        );
    }

    private function recommendDeCostGate(
        string $countryCode,
        Collection $requirements,
        Collection $warehouses,
        array $costEvidence,
        array $context,
    ): array {
        $warehouse = $this->deWarehouse($countryCode, $requirements, $warehouses, $context);
        if (isset($warehouse['blocked'])) {
            return $warehouse['blocked'];
        }

        $gate = $this->routing->deCostGate($countryCode) ?? [];
        $required = (array) ($gate['required_evidence'] ?? []);
        foreach ($required as $field) {
            if (! array_key_exists($field, $costEvidence) || $costEvidence[$field] === null || $costEvidence[$field] === '') {
                return $this->blocked(
                    'missing_de_cost_evidence',
                    "{$countryCode} 属于德国仓成本比较国家；缺少真实成本证据 {$field}，不自动推荐德国仓。",
                    $countryCode,
                    ['strategy' => 'de_cost_gate', 'missing_field' => $field],
                );
            }
        }

        $china = $this->numericCost($costEvidence['china_historical_shipping_cost_eur'] ?? null);
        $deDhl = $this->numericCost($costEvidence['de_dhl_shipping_cost_eur'] ?? null);
        if ($china === null || $deDhl === null) {
            return $this->blocked(
                'invalid_de_cost_evidence',
                '成本证据必须是非负 EUR 数值；无法比较时不自动推荐德国仓。',
                $countryCode,
                ['strategy' => 'de_cost_gate'],
            );
        }

        $handling = max(0.0, (float) ($gate['per_order_handling_cost_eur'] ?? 1.0));
        $deTotal = round($deDhl + $handling, 4);
        $context = [
            'strategy' => 'de_cost_gate',
            'currency' => 'EUR',
            'de_dhl_shipping_cost_eur' => $deDhl,
            'de_handling_cost_eur' => $handling,
            'de_total_routing_cost_eur' => $deTotal,
            'china_historical_shipping_cost_eur' => $china,
            'china_historical_cost_source' => (string) $costEvidence['china_historical_cost_source'],
            'de_dhl_quote_source' => (string) $costEvidence['de_dhl_quote_source'],
            'storage_cost_in_routing' => false,
        ];

        if ($deTotal >= $china) {
            return $this->blocked(
                'de_cost_not_lower',
                "德国仓 DHL + €1 成本 €{$deTotal} 未低于中国历史直发成本 €{$china}，不推荐德国仓。",
                $countryCode,
                $context,
            );
        }

        return $this->recommended(
            $warehouse,
            $countryCode,
            $requirements->count(),
            1,
            'recommended_de_cost_lower',
            "德国仓 DHL + €1 成本 €{$deTotal} 低于中国历史直发成本 €{$china}，推荐德国仓。",
            $context,
        );
    }

    /** @return array<string,mixed> */
    private function deWarehouse(string $countryCode, Collection $requirements, Collection $warehouses, array $context): array
    {
        $allowed = $this->routing->warehouseCodes($countryCode);
        $warehouse = $warehouses->first(fn (array $candidate): bool => in_array($this->routing->canonicalWarehouseCode((string) ($candidate['code'] ?? '')), $allowed, true)
        );
        if ($warehouse === null) {
            return ['blocked' => $this->blocked(
                'no_inventory_authority',
                '德国仓当前没有 Full Snapshot 权威库存。',
                $countryCode,
            )];
        }

        if (! $this->covers($warehouse, $requirements, $context['availability'])) {
            return ['blocked' => $this->blocked(
                'insufficient_stock',
                '德国仓无法一次满足订单全部商品数量；请进入整单/拆单履约方案评估。',
                $countryCode,
            )];
        }

        return $warehouse;
    }

    /** @param array<string,mixed> $result */
    private function militaryResult(array $result): array
    {
        $context = array_merge((array) ($result['routing_context'] ?? []), [
            'military_address' => true,
            'must_use_us_warehouse' => true,
            'factory_allowed' => false,
            'human_review_if_split_required' => true,
        ]);

        if (($result['status'] ?? null) === 'recommended') {
            $result['reason_code'] = 'recommended_military_us_local';
            $result['reason_label'] = '军事地址必须由美国仓当地发货；当前推荐美国仓可整单履约。';
            $result['routing_context'] = $context;

            return $result;
        }

        return $this->blocked(
            'military_us_warehouse_review_required',
            '军事地址禁止中国工厂直发；当前没有单一美国仓可整单履约，请评估美国仓之间拆单。',
            'US',
            $context,
        );
    }

    private function numericCost(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return $number >= 0 ? round($number, 4) : null;
    }

    private function recommendMilitaryUsLocal(
        string $countryCode,
        Collection $requirements,
        Collection $warehouses,
        array $context,
    ): array {
        $allowedCodes = $this->routing->warehouseCodes($countryCode);
        $eligible = $warehouses->filter(function (array $warehouse) use ($allowedCodes, $requirements, $context): bool {
            $code = $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? ''));
            return $code !== null
                && in_array($code, $allowedCodes, true)
                && $this->covers($warehouse, $requirements, $context['availability']);
        })->sortBy([['priority', 'asc'], ['id', 'asc']])->values();

        if ($eligible->isEmpty()) {
            return $this->militaryResult($this->blocked(
                'insufficient_stock',
                '军事地址必须由美国仓当地发货，但当前美国仓无法整单满足库存。',
                $countryCode,
            ));
        }

        return $this->militaryResult($this->recommended(
            $eligible->first(),
            $countryCode,
            $requirements->count(),
            $eligible->count(),
            'recommended_military_us_local',
            '军事地址必须由美国仓当地发货；当前推荐美国仓可整单履约。',
        ));
    }

    private function recommendPhysicalZoneThenPriority(
        string $countryCode,
        ?string $postalCode,
        array $destinationContext,
        Collection $requirements,
        Collection $warehouses,
        array $context,
    ): array {
        $resolution = $this->physicalZones->resolve($postalCode, $destinationContext);
        if (($resolution['status'] ?? null) !== 'matched') {
            return $this->blocked(
                'us_physical_zone_unresolved',
                '美国收货地址的 ZIP Code / 州无法可靠确定美东或美西区域，请 Vita 人工判断。',
                $countryCode,
                $this->physicalRoutingContext($resolution),
            );
        }

        $priority = $this->physicalZones->priorityFor($resolution);
        $eligibleByCode = collect();
        foreach ($warehouses as $warehouse) {
            $code = $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? ''));
            if ($code !== null && in_array($code, $priority, true)
                && $this->covers($warehouse, $requirements, $context['availability'])) {
                $eligibleByCode[$code] = $warehouse;
            }
        }

        foreach ($priority as $code) {
            if (! $eligibleByCode->has($code)) continue;
            $winner = $eligibleByCode->get($code);
            $zoneLabel = (string) ($resolution['zone_label'] ?? $resolution['zone'] ?? '美国区域');
            $reason = $code === 'US-SM'
                ? "{$zoneLabel}：美国3世迈仓库存可整单满足，按冻结规则优先世迈仓。"
                : ($code === 'US-LA'
                    ? "{$zoneLabel}：美国3世迈仓无法整单满足，MorePlus 有货，按冻结规则回退 MorePlus。"
                    : "{$zoneLabel}：收货地址属于美东区域且纽约仓库存可整单满足，推荐美东纽约仓。");

            return $this->recommended(
                $winner,
                $countryCode,
                $requirements->count(),
                $eligibleByCode->count(),
                'recommended_physical_zone',
                $reason,
                $this->physicalRoutingContext($resolution),
            );
        }

        $zoneLabel = (string) ($resolution['zone_label'] ?? $resolution['zone'] ?? '美国区域');
        return $this->blocked(
            'insufficient_stock_in_physical_zone',
            "{$zoneLabel}对应美国仓无法整单满足库存；不跨美东/美西区域兜底，请比较中国工厂或拆单方案。",
            $countryCode,
            $this->physicalRoutingContext($resolution),
        );
    }

    /** @return array<string,mixed> */
    private function physicalRoutingContext(array $resolution): array
    {
        return [
            'strategy' => 'physical_zone_then_priority',
            'physical_zone' => $resolution,
            'postal_code' => $resolution['postal_code'] ?? null,
            'state' => $resolution['state'] ?? null,
            'warehouse_priority' => $this->physicalZones->priorityFor($resolution),
            'cross_region_fallback' => false,
        ];
    }

    private function recommendEastZoneThenPriority(
        string $countryCode,
        ?string $postalCode,
        Collection $requirements,
        Collection $warehouses,
        array $context,
    ): array {
        $allowedCodes = $this->routing->warehouseCodes($countryCode);
        $policyWarehouses = $warehouses->filter(function (array $warehouse) use ($allowedCodes): bool {
            $canonical = $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? ''));

            return $canonical !== null && in_array($canonical, $allowedCodes, true);
        })->values();

        if ($policyWarehouses->isEmpty()) {
            return $this->blocked('no_inventory_authority', '路由策略中的仓库当前没有可用 Full Snapshot 权威库存。', $countryCode);
        }

        $eligible = $policyWarehouses->filter(
            fn (array $warehouse): bool => $this->covers($warehouse, $requirements, $context['availability'])
        )->values();
        if ($eligible->isEmpty()) {
            return $this->blocked('insufficient_stock', '美国候选仓无法由单一仓库一次满足订单全部商品数量。', $countryCode);
        }

        $eastRule = $this->routing->eastZoneRule($countryCode) ?? [];
        $eastCode = strtoupper(trim((string) ($eastRule['warehouse'] ?? '')));
        $maxZone = max(1, (int) ($eastRule['max_zone'] ?? 4));
        $dataFile = (string) ($eastRule['data_file'] ?? '');
        $zone = $this->zones->resolve($postalCode, $dataFile);

        $east = $eligible->first(fn (array $warehouse): bool => $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? '')) === $eastCode
        );

        if ($east !== null && ($zone['status'] ?? null) === 'matched' && (int) $zone['zone'] <= $maxZone) {
            return $this->recommended(
                $east,
                $countryCode,
                $requirements->count(),
                $eligible->count(),
                'recommended_east_zone',
                "美东 USPS Zone {$zone['zone']} ≤ {$maxZone}，且美东仓可整单履约，优先美东仓。",
                $this->routingContext($postalCode, $zone, $this->routing->fallbackPriority($countryCode)),
            );
        }

        foreach ($this->routing->fallbackPriority($countryCode) as $code) {
            $winner = $eligible->first(fn (array $warehouse): bool => $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? '')) === $code
            );
            if ($winner !== null) {
                $label = ($zone['status'] ?? null) === 'matched'
                    ? "美东 USPS Zone {$zone['zone']} 未触发 Zone ≤ {$maxZone} 美东优先，按 US-SM → US-LA → US-NY 顺序选择可整单履约仓。"
                    : 'ZIP 无法从当前美东 USPS 分区表唯一确认 Zone，按 US-SM → US-LA → US-NY 安全 fallback。';

                return $this->recommended(
                    $winner,
                    $countryCode,
                    $requirements->count(),
                    $eligible->count(),
                    'recommended_fallback_priority',
                    $label,
                    $this->routingContext($postalCode, $zone, $this->routing->fallbackPriority($countryCode)),
                );
            }
        }

        $winner = $eligible->sortBy([['priority', 'asc'], ['id', 'asc']])->first();

        return $this->recommended(
            $winner,
            $countryCode,
            $requirements->count(),
            $eligible->count(),
            'recommended_policy_safety_fallback',
            '路由优先级未覆盖全部候选仓，已按仓库主数据优先级安全兜底。',
            $this->routingContext($postalCode, $zone, $this->routing->fallbackPriority($countryCode)),
        );
    }

    private function recommendServiceRegion(string $countryCode, Collection $requirements, Collection $warehouses, array $context): array
    {
        if (! $warehouses->contains(fn (array $warehouse): bool => ! empty($warehouse['service_regions']))) {
            return $this->blocked('service_regions_unconfigured', '可自动分配仓库尚未配置服务国家/地区。');
        }

        $regional = $warehouses->map(function (array $warehouse) use ($countryCode): ?array {
            $region = collect($warehouse['service_regions'] ?? [])->first(
                fn (array $item): bool => strtoupper((string) ($item['country_code'] ?? '')) === $countryCode
            );

            return $region === null ? null : [...$warehouse, 'service_region_priority' => (int) ($region['priority'] ?? 9999)];
        })->filter()->values();

        if ($regional->isEmpty()) {
            return $this->blocked('no_service_region_match', "没有可自动分配仓库覆盖 {$countryCode}。", $countryCode);
        }

        $eligible = $regional->filter(
            fn (array $warehouse): bool => $this->covers($warehouse, $requirements, $context['availability'])
        );
        if ($eligible->isEmpty()) {
            return $this->blocked('insufficient_stock', '覆盖该国家的仓库无法一次满足订单全部商品数量。', $countryCode);
        }

        $winner = $eligible->sortBy([
            ['service_region_priority', 'asc'],
            ['priority', 'asc'],
            ['id', 'asc'],
        ])->first();

        return $this->recommended(
            $winner,
            $countryCode,
            $requirements->count(),
            $eligible->count(),
            'recommended',
            '库存与服务区域均满足，可采用系统推荐。',
        );
    }

    /** @param array<int, array{product_id:int|null,quantity:int}> $items */
    private function requirements(array $items): ?Collection
    {
        $requirements = collect();
        foreach ($items as $item) {
            $productId = $item['product_id'] ?? null;
            if ($productId === null) {
                return null;
            }
            $quantity = max(0, (int) ($item['quantity'] ?? 0));
            if ($quantity === 0) {
                continue;
            }
            $requirements[(int) $productId] = (int) ($requirements[(int) $productId] ?? 0) + $quantity;
        }

        return $requirements;
    }

    private function covers(array $warehouse, Collection $requirements, array $availability): bool
    {
        foreach ($requirements as $productId => $requiredQuantity) {
            $row = collect($availability[(int) $productId] ?? [])->first(
                fn (array $item): bool => (int) ($item['warehouse_id'] ?? 0) === (int) $warehouse['id']
            );
            if ((int) ($row['quantity_available'] ?? 0) < (int) $requiredQuantity) {
                return false;
            }
        }

        return true;
    }

    private function recommended(
        array $warehouse,
        string $countryCode,
        int $requiredSkuCount,
        int $candidateCount,
        string $reasonCode,
        string $reasonLabel,
        ?array $routingContext = null,
    ): array {
        $canonical = $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? ''));

        return [
            'status' => 'recommended',
            'reason_code' => $reasonCode,
            'reason_label' => $reasonLabel,
            'country_code' => $countryCode,
            'required_sku_count' => $requiredSkuCount,
            'candidate_count' => $candidateCount,
            'warehouse' => [
                'id' => (int) $warehouse['id'],
                'slug' => (string) $warehouse['slug'],
                'code' => $canonical ?? (string) $warehouse['code'],
                'name' => (string) $warehouse['name'],
            ],
            'routing_context' => $routingContext,
        ];
    }

    private function routingContext(?string $postalCode, array $zone, array $fallback): array
    {
        return [
            'strategy' => 'east_zone_then_priority',
            'postal_code' => $postalCode,
            'east_zone_status' => $zone['status'] ?? null,
            'east_zone' => $zone['zone'] ?? null,
            'east_zone_prefix' => $zone['prefix'] ?? null,
            'fallback_priority' => $fallback,
        ];
    }

    private function blocked(
        string $reasonCode,
        string $reasonLabel,
        ?string $countryCode = null,
        ?array $routingContext = null,
    ): array {
        return [
            'status' => 'blocked',
            'reason_code' => $reasonCode,
            'reason_label' => $reasonLabel,
            'country_code' => $countryCode,
            'required_sku_count' => null,
            'candidate_count' => 0,
            'warehouse' => null,
            'routing_context' => $routingContext,
        ];
    }
}
