<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Orders\Infrastructure\Models\Order;

final class FulfillmentAllocationDecisionService
{
    /** @return array<string,mixed> */
    public function present(Order $order, array $preview): array
    {
        $plans = collect($preview['plans'] ?? [])->map(fn (array $plan): array => $this->enrichPlan($order, $plan))->values();
        $comparison = is_array($preview['comparison'] ?? null) ? $preview['comparison'] : [];
        $allCosted = $plans->isNotEmpty() && $plans->every(fn (array $plan): bool => ($plan['cost']['status'] ?? null) === 'ready');
        $currencies = $plans->pluck('cost.currency')->filter()->unique()->values();
        $splitPresent = $plans->contains(fn (array $plan): bool => (bool) ($plan['is_split'] ?? false));
        $splitPenalty = config('warehouse_routing.global.split_penalty_cny');
        $giftNeedsResolution = $order->items->contains(fn ($item): bool => strcasecmp(trim((string) $item->sku), 'gift') === 0);
        $physicalZone = $preview['constraints']['us_physical_zone'] ?? null;
        $physicalZoneReady = ! is_array($physicalZone) || ($physicalZone['status'] ?? null) === 'matched';
        $canRank = ! $giftNeedsResolution
            && $physicalZoneReady
            && ($comparison['status'] ?? null) === 'ready'
            && $allCosted
            && $currencies->count() === 1
            && (! $splitPresent || is_numeric($splitPenalty));

        $rankedPlans = $plans->map(function (array $plan) use ($splitPenalty): array {
            $effective = ($plan['cost']['status'] ?? null) === 'ready' ? (float) $plan['cost']['amount'] : null;
            if ($effective !== null && ($plan['is_split'] ?? false) && is_numeric($splitPenalty)) {
                $effective += (float) $splitPenalty;
            }
            return [...$plan, 'effective_cost' => $effective === null ? null : round($effective, 4)];
        })->values();
        $recommended = $canRank ? $rankedPlans->sortBy('effective_cost')->first() : null;
        $plans = $rankedPlans;

        $manualReasons = $recommended ? [] : $this->manualReasons($preview, $plans->all());
        if ($giftNeedsResolution) {
            array_unshift($manualReasons, '订单包含 gift 礼品占位 SKU，需要 Vita 确认实际履约 SKU');
            $manualReasons = array_values(array_unique($manualReasons));
        }
        if (! $physicalZoneReady) {
            array_unshift($manualReasons, '美国收货地址 ZIP Code / 州无法可靠确定美东或美西区域');
            $manualReasons = array_values(array_unique($manualReasons));
        }
        $baseline = $recommended ? $this->baselinePlan($plans->all(), $recommended) : null;
        $saving = null;
        if ($recommended && $baseline
            && ($recommended['cost']['status'] ?? null) === 'ready'
            && ($baseline['cost']['status'] ?? null) === 'ready'
            && ($recommended['cost']['currency'] ?? null) === ($baseline['cost']['currency'] ?? null)) {
            $saving = round((float) ($baseline['effective_cost'] ?? $baseline['cost']['amount']) - (float) ($recommended['effective_cost'] ?? $recommended['cost']['amount']), 4);
        }

        return [
            'status' => $recommended ? 'system_recommendation' : 'manual_review',
            'status_label' => $recommended ? '系统推荐' : '需要人工判断',
            'reason_code' => $recommended ? 'lowest_complete_cost_plan' : ($preview['reason_code'] ?? 'insufficient_decision_evidence'),
            'manual_reasons' => $manualReasons,
            'recommended_plan_key' => $recommended['plan_key'] ?? null,
            'recommended_plan' => $recommended,
            'baseline_plan' => $baseline,
            'estimated_cost' => $recommended['effective_cost'] ?? ($recommended['cost']['amount'] ?? null),
            'currency' => $recommended['cost']['currency'] ?? null,
            'estimated_saving' => $saving,
            'plans' => $plans->all(),
            'can_use_recommendation' => $recommended !== null,
            'policy' => [
                'system_is_advisory' => true,
                'human_confirmation_required' => true,
                'missing_data_falls_back_to_manual' => true,
                'same_order_item_quantity_split_allowed' => false,
                'max_sources' => count((array) config('warehouse_master.fulfillment_codes', [])),
                'split_pattern' => 'multi_warehouse_or_factory',
            ],
        ];
    }

    private function enrichPlan(Order $order, array $plan): array
    {
        $itemMap = $order->items->keyBy('product_id');
        $segments = collect($plan['segments'] ?? [])->map(function (array $segment) use ($itemMap): array {
            $items = collect($segment['items'] ?? [])->map(function (array $item) use ($itemMap): array {
                $orderItem = $itemMap->get((int) ($item['product_id'] ?? 0));
                return [
                    ...$item,
                    'sku' => $orderItem?->sku,
                    'product_summary' => $orderItem?->product_summary,
                ];
            })->values()->all();
            return [...$segment, 'items' => $items];
        })->values()->all();

        return [
            ...$plan,
            'label' => $this->planLabel($plan, $segments),
            'segments' => $segments,
        ];
    }

    private function planLabel(array $plan, array $segments): string
    {
        if (($plan['is_split'] ?? false) === true) return '拆分发货';
        $segment = $segments[0] ?? [];
        if (($segment['source_type'] ?? null) === 'factory') return '整单中国工厂发货';
        $warehouse = trim((string) ($segment['warehouse_name'] ?? $segment['source_code'] ?? '海外仓'));
        return "整单{$warehouse}发货";
    }

    /** @param list<array<string,mixed>> $plans */
    private function manualReasons(array $preview, array $plans): array
    {
        $reasons = [];
        $fallback = $preview['fallback_policy'] ?? [];
        if (($fallback['mode'] ?? null) === 'inventory_only_missing_weight') {
            $reasons[] = '产品包装/运输重量数据不足';
        }
        if (($preview['status'] ?? null) === 'blocked') {
            $reasons[] = match ($preview['reason_code'] ?? null) {
                'missing_country' => '订单国家/地区信息缺失',
                'unresolved_or_empty_items' => '订单 SKU / Product 数据不完整',
                'insufficient_stock', 'no_fulfillment_plan' => '现有库存无法形成可靠履约方案',
                default => '系统无法形成可靠履约方案',
            };
        }
        foreach ($plans as $plan) {
            $code = $plan['cost']['reason_code'] ?? null;
            $label = match ($code) {
                'missing_product_weight', 'missing_shipping_reference_weight' => '产品运输重量数据不足',
                'missing_packaging_standard' => '产品包装尺寸/重量数据不足',
                'missing_packaging_cost' => '产品包装成本数据不足',
                'routing_rate_unavailable', 'factory_carrier_rate_unavailable' => '候选仓物流成本数据不足',
                'fx_unavailable' => '汇率换算数据不足',
                default => null,
            };
            if ($label) $reasons[] = $label;
        }
        if (collect($plans)->contains(fn (array $plan): bool => (bool) ($plan['is_split'] ?? false))
            && ! is_numeric(config('warehouse_routing.global.split_penalty_cny'))) {
            $reasons[] = '拆单附加成本尚未配置';
        }
        if (($preview['comparison']['status'] ?? null) === 'partial_evidence') {
            $reasons[] = '部分候选方案成本数据不足';
        } elseif (($preview['comparison']['status'] ?? null) === 'missing_evidence') {
            $reasons[] = '履约成本证据不足';
        } elseif (($preview['comparison']['status'] ?? null) === 'currency_mismatch') {
            $reasons[] = '候选方案成本币种无法统一比较';
        }

        return array_values(array_unique($reasons ?: ['系统证据不足，请人工判断']));
    }

    /** @param list<array<string,mixed>> $plans */
    private function baselinePlan(array $plans, array $recommended): ?array
    {
        $factory = collect($plans)->first(fn (array $plan): bool => ($plan['plan_type'] ?? null) === 'factory' && ($plan['cost']['status'] ?? null) === 'ready');
        if ($factory && ($factory['plan_key'] ?? null) !== ($recommended['plan_key'] ?? null)) return $factory;

        return collect($plans)
            ->filter(fn (array $plan): bool => ($plan['plan_key'] ?? null) !== ($recommended['plan_key'] ?? null) && ($plan['cost']['status'] ?? null) === 'ready')
            ->sortBy(fn (array $plan): float => (float) ($plan['effective_cost'] ?? $plan['cost']['amount']))->first();
    }
}
