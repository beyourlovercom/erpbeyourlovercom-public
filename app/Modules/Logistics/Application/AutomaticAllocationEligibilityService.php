<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Orders\Infrastructure\Models\Order;

final class AutomaticAllocationEligibilityService
{
    public function __construct(
        private readonly WarehouseRecommendationService $recommendations,
        private readonly WarehouseRoutingPolicy $routingPolicy,
        private readonly FulfillmentPlanPreviewService $fulfillmentPlans,
        private readonly FulfillmentPaymentGate $paymentGate,
    ) {}

    /** @param iterable<Order> $orders @return array<int,array{allowed:bool,state:string,reason_code:string,reason_label:string}> */
    public function preparePaymentDecisions(iterable $orders): array
    {
        return $this->paymentGate->prepare($orders);
    }

    /** @return array<string,mixed> */
    public function evaluate(
        Order $order,
        ?array $recommendation = null,
        ?array $activeReservationsByOrderId = null,
        ?array $paymentDecision = null,
    ): array {
        $order->loadMissing(['address', 'items', 'shipment.warehouse', 'externalReferences']);

        if ($order->externalReferences->contains('provider', 'historical_logistics')) {
            return $this->blocked('HISTORICAL_EXCLUDED', 'historical_excluded', '历史物流订单永久排除自动分配。');
        }

        $payment = $paymentDecision ?? $this->paymentGate->evaluate($order);
        if (! $payment['allowed']) {
            return $this->blocked('PAYMENT_NOT_AUTHORIZED', $payment['reason_code'], $payment['reason_label']);
        }

        if ($order->order_status?->value !== 'pending_shipment') {
            return $this->blocked('BLOCKED_OTHER', 'order_not_pending', '只有待发货订单可以自动分配。');
        }

        $activeReservation = $activeReservationsByOrderId !== null
            ? ($activeReservationsByOrderId[$order->id] ?? null)
            : InventoryReservation::query()
                ->where('order_id', $order->id)
                ->where('status', 'reserved')
                ->first();
        if ($activeReservation) {
            if ($order->shipment?->warehouse_selection_source === 'automatic'
                && (int) $order->shipment->warehouse_id === (int) $activeReservation->warehouse_id) {
                return $this->result(
                    'ALREADY_RESERVED',
                    'allocated',
                    'already_automatic',
                    '已自动分配并预留库存。',
                    $this->shipmentWarehouse($order),
                );
            }

            return $this->blocked('ALREADY_RESERVED', 'already_reserved', '订单已有库存预留，不自动覆盖现有履约选择。');
        }

        if ($order->shipment) {
            if ($order->shipment->warehouse_id !== null) {
                return $this->blocked(
                    $order->shipment->warehouse_selection_source === 'manual' ? 'MANUAL_WAREHOUSE_SELECTED' : 'ALREADY_HAS_DRAFT',
                    'existing_shipment_draft',
                    '订单已有履约草稿或仓库选择，不自动覆盖。',
                );
            }

            return $this->blocked('ALREADY_HAS_DRAFT', 'existing_shipment_draft', '订单已有履约草稿，不自动覆盖。');
        }

        $countryCode = strtoupper(trim((string) $order->address?->country));
        if ($countryCode === '') {
            return $this->blocked('MISSING_COUNTRY', 'missing_country', '订单缺少国家代码，无法自动分配。');
        }
        if (! preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return $this->blocked('INVALID_COUNTRY', 'invalid_country_code', '订单国家必须使用 ISO 2 位代码后才能自动分配。');
        }

        if (! $this->routingPolicy->automaticEnabled($countryCode)) {
            return $this->blocked(
                'COUNTRY_NOT_AUTO_ENABLED',
                'country_not_auto_enabled',
                "{$countryCode} 尚未开放自动分配，继续使用人工履约。",
            );
        }

        if ($recommendation === null) {
            $items = $order->items->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'quantity' => (int) $item->quantity,
            ])->values()->all();
            $productIds = collect($items)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            $context = array_replace(
                $this->recommendations->prepare($productIds),
                $this->fulfillmentPlans->prepare($productIds),
            );
            $recommendation = $this->recommendations->recommend(
                $countryCode,
                $items,
                $order->address?->postal_code,
                [],
                [],
                $context,
            );
        }

        if (($recommendation['status'] ?? null) !== 'recommended') {
            return $this->fromRecommendationBlock($recommendation);
        }

        $recommendedCode = strtoupper((string) ($recommendation['warehouse']['code'] ?? ''));
        $allowedWarehouseCodes = $this->routingPolicy->warehouseCodes($countryCode);
        if ($allowedWarehouseCodes === [] || ! in_array($recommendedCode, $allowedWarehouseCodes, true)) {
            return $this->blocked(
                'MULTIPLE_POLICY_AMBIGUITY',
                'routing_policy_mismatch',
                '推荐结果不在统一 Warehouse Routing Policy 允许的仓库范围内，已停止自动分配。',
            );
        }

        $fixedWarehouseCode = $this->routingPolicy->fixedWarehouseCode($countryCode);
        if ($this->routingPolicy->strategy($countryCode) === 'fixed'
            && ($fixedWarehouseCode === null || $recommendedCode !== $fixedWarehouseCode)) {
            return $this->blocked(
                'MULTIPLE_POLICY_AMBIGUITY',
                'routing_policy_mismatch',
                '推荐结果与统一 Warehouse Routing Policy 的固定仓规则不一致，已停止自动分配。',
            );
        }

        return $this->result(
            'READY_AUTO_ALLOCATE',
            'ready',
            'ready_auto_allocate',
            '库存与路由均满足，可自动分配。',
            $recommendation['warehouse'],
            $countryCode,
        );
    }

    /** @param array<string,mixed> $recommendation */
    private function fromRecommendationBlock(array $recommendation): array
    {
        $reasonCode = (string) ($recommendation['reason_code'] ?? 'blocked_other');
        $state = match ($reasonCode) {
            'missing_country' => 'MISSING_COUNTRY',
            'invalid_country_code' => 'INVALID_COUNTRY',
            'unresolved_product' => 'UNRESOLVED_PRODUCT',
            'service_regions_unconfigured', 'no_service_region_match' => 'NO_SERVICE_REGION',
            'no_inventory_authority' => 'NO_FULL_SNAPSHOT_AUTHORITY',
            'insufficient_stock' => 'INSUFFICIENT_STOCK',
            'missing_product_weight_inventory_only' => 'MISSING_PRODUCT_WEIGHT',
            default => 'BLOCKED_OTHER',
        };

        return $this->blocked($state, $reasonCode, (string) ($recommendation['reason_label'] ?? '当前无法自动分配。'));
    }

    /** @return array<string,mixed> */
    private function blocked(string $state, string $reasonCode, string $reasonLabel): array
    {
        return $this->result($state, 'blocked', $reasonCode, $reasonLabel);
    }

    /** @param array<string,mixed>|null $warehouse */
    private function result(
        string $state,
        string $status,
        string $reasonCode,
        string $reasonLabel,
        ?array $warehouse = null,
        ?string $countryCode = null,
    ): array {
        return [
            'state' => $state,
            'status' => $status,
            'reason_code' => $reasonCode,
            'reason_label' => $reasonLabel,
            'country_code' => $countryCode,
            'warehouse' => $warehouse,
            'can_apply' => $state === 'READY_AUTO_ALLOCATE',
        ];
    }

    /** @return array<string,mixed>|null */
    private function shipmentWarehouse(Order $order): ?array
    {
        $warehouse = $order->shipment?->warehouse;
        if (! $warehouse) {
            return null;
        }

        return ['id' => (int) $warehouse->id, 'slug' => (string) $warehouse->slug, 'code' => (string) $warehouse->code, 'name' => (string) $warehouse->name];
    }
}
