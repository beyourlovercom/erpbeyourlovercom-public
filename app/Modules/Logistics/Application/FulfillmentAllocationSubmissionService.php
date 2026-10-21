<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\FulfillmentAllocation;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class FulfillmentAllocationSubmissionService
{
    private const ALLOWED_SOURCE_CODES = ['FACTORY', 'US-NY', 'US-LA', 'US-SM', 'DE', 'CA'];
    private const MAX_SEGMENTS = 26;

    public function __construct(
        private readonly WarehouseRecommendationService $recommendations,
        private readonly FulfillmentPlanPreviewService $plans,
        private readonly FulfillmentAllocationDecisionService $decisions,
        private readonly FulfillmentDerivedOrderService $derivedOrders,
        private readonly GiftSkuResolutionService $giftSkuResolutions,
        private readonly UsFulfillmentZoneResolver $usZones,
        private readonly WarehouseRoutingPolicy $warehouseRouting,
        private readonly CarrierRoutingPolicy $carrierRouting,
        private readonly FulfillmentPaymentGate $paymentGate,
    ) {}

    public function submitRecommended(Order $order, string $planKey, ?string $note, Authenticatable $actor): FulfillmentAllocation
    {
        return DB::transaction(function () use ($order, $planKey, $note, $actor): FulfillmentAllocation {
            $locked = Order::query()->with(['address', 'items.product', 'fulfillmentAllocation'])->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->paymentGate->assertAllowed($locked);
            abort_unless($locked->order_status?->value === 'pending_shipment', 409, '只有待发货订单可以提交分仓');

            $presented = $this->decisionFor($locked);
            abort_unless((bool) ($presented['can_use_recommendation'] ?? false), 409, '当前数据不足，必须由 Vita 人工判断');
            abort_unless(hash_equals((string) ($presented['recommended_plan_key'] ?? ''), trim($planKey)), 409, '系统推荐方案已变化，请刷新后重新确认');

            return $this->persistAndMaterialize($locked, [
                'decision_source' => 'system_recommendation',
                'recommended_plan_key' => $presented['recommended_plan_key'],
                'selected_plan_key' => $presented['recommended_plan_key'],
                'plan_snapshot' => $presented['recommended_plan'],
                'manual_assignments' => null,
                'decision_note' => $this->nullableNote($note),
            ], $actor);
        });
    }

    /** @param list<array{order_item_id:int,source_code:string,gift_mapping_id?:int|null,carrier?:string|null,service?:string|null}> $assignments */
    public function submitManual(Order $order, array $assignments, ?string $note, Authenticatable $actor): FulfillmentAllocation
    {
        return DB::transaction(function () use ($order, $assignments, $note, $actor): FulfillmentAllocation {
            $locked = Order::query()->with(['address', 'items.product', 'fulfillmentAllocation'])->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->paymentGate->assertAllowed($locked);
            abort_unless($locked->order_status?->value === 'pending_shipment', 409, '只有待发货订单可以提交分仓');

            $normalized = collect($assignments)->map(fn (array $row): array => [
                'order_item_id' => (int) ($row['order_item_id'] ?? 0),
                'source_code' => $this->warehouseRouting->canonicalWarehouseCode((string) ($row['source_code'] ?? '')) ?? '',
                'gift_mapping_id' => isset($row['gift_mapping_id']) && $row['gift_mapping_id'] !== '' ? (int) $row['gift_mapping_id'] : null,
                'carrier' => trim((string) ($row['carrier'] ?? '')),
                'service' => isset($row['service']) && trim((string) $row['service']) !== '' ? trim((string) $row['service']) : null,
            ])->values();
            abort_if($normalized->isEmpty(), 422, '请为每个 SKU 选择发货仓库');
            abort_if($normalized->contains(fn (array $row): bool => $row['order_item_id'] <= 0 || ! in_array($row['source_code'], self::ALLOWED_SOURCE_CODES, true)), 422, '人工分仓包含无效仓库');
            abort_if($normalized->pluck('order_item_id')->duplicates()->isNotEmpty(), 422, '同一个订单商品不能拆数量到多个仓库');

            $orderItemIds = $locked->items->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
            abort_unless($normalized->pluck('order_item_id')->sort()->values()->all() === $orderItemIds->all(), 422, '必须为订单中的每个 SKU 完成分仓');

            $resolvedAssignments = $normalized->map(function (array $row) use ($locked): array {
                $item = $locked->items->firstWhere('id', $row['order_item_id']);
                abort_unless($item, 422, '分仓方案包含不属于原订单的 SKU');
                $resolution = $this->giftSkuResolutions->resolve($item, $row['source_code'], $row['gift_mapping_id']);
                if ($resolution !== null) {
                    $item->forceFill([
                        'source_sku' => $item->source_sku ?: $item->sku,
                        'resolved_fulfillment_sku' => $resolution['resolved_fulfillment_sku'],
                    ])->save();
                }

                $route = $this->carrierRouting->warehouseRoute($locked->address?->country, $row['source_code']);
                $defaultCarrier = trim((string) ($route['carrier'] ?? ''));
                $providedCarrier = trim((string) ($row['carrier'] ?? ''));
                $carrier = $providedCarrier !== '' ? $providedCarrier : $defaultCarrier;
                abort_if($carrier === '', 422, "发货仓 {$row['source_code']} 无法自动确定承运商，请 Vita 人工选择");
                if ($providedCarrier !== '') {
                    abort_unless(Carrier::query()->where('name', $providedCarrier)->where('is_active', true)->exists(), 422, "承运商 {$providedCarrier} 未启用");
                }
                $service = $row['service'] ?? null;
                if ($service === null && ($providedCarrier === '' || strcasecmp($providedCarrier, $defaultCarrier) === 0)) {
                    $service = isset($route['service']) && trim((string) $route['service']) !== '' ? trim((string) $route['service']) : null;
                }

                return [
                    ...$row,
                    'carrier' => $carrier,
                    'service' => $service,
                    'carrier_selection_source' => $providedCarrier !== '' && ($defaultCarrier === '' || strcasecmp($providedCarrier, $defaultCarrier) !== 0)
                        ? 'manual'
                        : 'automatic',
                    'resolution' => $resolution,
                ];
            })->values();

            $sources = $resolvedAssignments->pluck('source_code')->unique()->values();
            abort_if($sources->count() > self::MAX_SEGMENTS, 422, '单订单最多允许 26 个履约来源');
            $carrierConflict = $resolvedAssignments->groupBy('source_code')->contains(
                fn ($rows): bool => $rows->map(fn (array $row): string => $row['carrier'].'|'.($row['service'] ?? ''))->unique()->count() > 1
            );
            abort_if($carrierConflict, 422, '同一发货仓在同一履约组内必须使用同一承运商');

            $warehouseByCode = Warehouse::query()->where('is_active', true)->whereIn('code', self::ALLOWED_SOURCE_CODES)->get()->keyBy('code');
            foreach ($sources as $sourceCode) {
                abort_unless($warehouseByCode->has($sourceCode), 422, "发货仓 {$sourceCode} 尚未启用");
            }

            $segments = $sources->map(function (string $sourceCode) use ($resolvedAssignments, $locked, $warehouseByCode): array {
                $warehouse = $warehouseByCode->get($sourceCode);
                $sourceAssignments = $resolvedAssignments->where('source_code', $sourceCode)->values();
                $routeAssignment = $sourceAssignments->first();
                $items = $sourceAssignments->map(function (array $assignment) use ($locked): array {
                    $item = $locked->items->firstWhere('id', $assignment['order_item_id']);
                    $resolution = $assignment['resolution'] ?? null;
                    return [
                        'order_item_id' => (int) $item->id,
                        'product_id' => $item->product_id ? (int) $item->product_id : null,
                        'sku' => $item->sku,
                        'source_sku' => $item->sku,
                        'product_summary' => $item->product_summary,
                        'quantity' => (int) $item->quantity,
                        'gift_mapping_id' => $resolution['gift_mapping_id'] ?? null,
                        'resolved_product_id' => $resolution['resolved_product_id'] ?? null,
                        'resolved_fulfillment_sku' => $resolution['resolved_fulfillment_sku'] ?? $item->sku,
                        'resolved_product_summary' => $resolution['resolved_product_summary'] ?? $item->product_summary,
                    ];
                })->values()->all();
                return [
                    'source_type' => $sourceCode === 'FACTORY' ? 'factory' : 'warehouse',
                    'source_code' => $sourceCode,
                    'warehouse_id' => (int) $warehouse->id,
                    'warehouse_name' => (string) $warehouse->name,
                    'carrier' => $routeAssignment['carrier'],
                    'service' => $routeAssignment['service'],
                    'carrier_selection_source' => $routeAssignment['carrier_selection_source'],
                    'items' => $items,
                ];
            })->values()->all();

            $signature = collect($segments)->map(fn (array $segment): string => $segment['source_code'].'@'.($segment['carrier'] ?? '').'['.collect($segment['items'])->map(fn (array $item): string => $item['order_item_id'].'x'.$item['quantity'].'@'.($item['resolved_fulfillment_sku'] ?? $item['sku'] ?? ''))->implode(',').']')->implode('|');
            $plan = [
                'plan_key' => 'manual:'.substr(hash('sha256', $signature), 0, 16),
                'plan_type' => count($segments) > 1 ? 'split' : ($segments[0]['source_type'] === 'factory' ? 'factory' : 'single'),
                'is_split' => count($segments) > 1,
                'shipment_count' => count($segments),
                'segments' => $segments,
                'cost' => ['status' => 'not_evaluated', 'amount' => null, 'currency' => null, 'source' => null],
                'label' => count($segments) > 1 ? '人工拆分发货' : ($segments[0]['source_type'] === 'factory' ? '人工整单中国工厂发货' : '人工整单海外仓发货'),
                'human_review_required' => true,
                'execution_status' => count($segments) > 1 ? 'pending_multi_leg_execution_gate' : 'pending_single_leg_bridge',
                'destination_zone' => strtoupper((string) $locked->address?->country) === 'US'
                    ? $this->usZones->resolve($locked->address?->postal_code, [
                        'province_state' => $locked->address?->province_state,
                        'city' => $locked->address?->city,
                    ])
                    : null,
            ];
            $presented = $this->decisionFor($locked);

            return $this->persistAndMaterialize($locked, [
                'decision_source' => 'manual',
                'recommended_plan_key' => $presented['recommended_plan_key'] ?? null,
                'selected_plan_key' => $plan['plan_key'],
                'plan_snapshot' => $plan,
                'manual_assignments' => $resolvedAssignments->map(fn (array $row): array => [
                    'order_item_id' => $row['order_item_id'],
                    'source_code' => $row['source_code'],
                    'gift_mapping_id' => $row['gift_mapping_id'],
                    'carrier' => $row['carrier'],
                    'service' => $row['service'],
                    'carrier_selection_source' => $row['carrier_selection_source'],
                    'resolved_fulfillment_sku' => $row['resolution']['resolved_fulfillment_sku'] ?? null,
                ])->all(),
                'decision_note' => $this->nullableNote($note),
            ], $actor);
        });
    }

    /** @return array<string,mixed> */
    private function decisionFor(Order $order): array
    {
        $items = $order->items->map(fn ($item): array => [
            'product_id' => $item->product_id,
            'quantity' => (int) $item->quantity,
            'sku' => $item->sku,
        ])->values()->all();
        $productIds = collect($items)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $context = array_replace($this->recommendations->prepare($productIds), $this->plans->prepare($productIds));
        $preview = $this->plans->preview(
            $order->address?->country,
            $items,
            [
                'province_state' => $order->address?->province_state,
                'city' => $order->address?->city,
                'address_line_1' => $order->address?->address_line_1,
                'address_line_2' => $order->address?->address_line_2,
            ],
            [],
            $context,
        );

        return $this->decisions->present($order, $preview);
    }

    private function persistAndMaterialize(Order $order, array $attributes, Authenticatable $actor): FulfillmentAllocation
    {
        $allocation = $this->persist($order, $attributes, $actor);
        $plan = is_array($allocation->plan_snapshot) ? $allocation->plan_snapshot : [];
        $resultOrders = $this->derivedOrders->materialize(
            $order->fresh(['address', 'items.product', 'shipment.warehouse', 'fulfillmentChildren.shipment.warehouse']),
            $plan,
            $actor,
        );
        $plan['derived_orders'] = $resultOrders;
        $plan['execution_status'] = count($resultOrders) > 1 ? 'derived_orders_materialized' : 'single_order_assigned';
        $allocation->forceFill(['plan_snapshot' => $plan])->save();

        return $allocation->fresh();
    }

    private function persist(Order $order, array $attributes, Authenticatable $actor): FulfillmentAllocation
    {
        return FulfillmentAllocation::query()->updateOrCreate(['order_id' => $order->getKey()], [
            ...$attributes,
            'status' => 'submitted',
            'submitted_by' => $actor->getAuthIdentifier(),
            'submitted_at' => now(),
        ])->fresh();
    }

    private function nullableNote(?string $note): ?string
    {
        $value = trim((string) $note);
        return $value === '' ? null : $value;
    }
}
