<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

final class FulfillmentDerivedOrderService
{
    private const MAX_SEGMENTS = 26;

    public function __construct(private readonly ShipmentService $shipments) {}

    /** @param array<string,mixed> $plan @return list<array<string,mixed>> */
    public function materialize(Order $parent, array $plan, Authenticatable $actor): array
    {
        $segments = collect($plan['segments'] ?? [])->values();
        abort_if($segments->isEmpty() || $segments->count() > self::MAX_SEGMENTS, 422, '履约方案必须包含 1–26 个发货段');

        if ($segments->count() === 1) {
            $segment = $segments->first();
            $this->clearPendingChildren($parent);
            $this->assignShipment($parent, $segment, $actor);

            return [$this->summary($parent->fresh(['items', 'shipment.warehouse']), $segment, false)];
        }

        abort_if(blank($parent->erp_order_id), 422, '原订单缺少 ERP 订单号，不能生成拆分订单');
        abort_if(strlen((string) $parent->erp_order_id) > 63, 422, '原订单号过长，不能追加拆单后缀');
        $this->assertParentShipmentMayBeReplaced($parent);
        $parent->shipment?->delete();

        $children = collect();
        foreach ($segments as $index => $segment) {
            $suffix = $this->suffixForIndex($index);
            $children->push($this->upsertChild($parent, $suffix, $segment, $actor));
        }

        $keepSuffixes = $children->pluck('fulfillment_suffix')->all();
        $parent->originalFulfillmentChildren()->whereNotIn('fulfillment_suffix', $keepSuffixes)->get()->each(fn (Order $child) => $this->deletePendingChild($child));

        return $children->map(function (Order $child) use ($segments): array {
            $index = $this->indexForSuffix((string) $child->fulfillment_suffix);
            return $this->summary($child->fresh(['items', 'shipment.warehouse']), $segments->get($index) ?? [], true);
        })->values()->all();
    }

    private function suffixForIndex(int $index): string
    {
        abort_if($index < 0 || $index >= self::MAX_SEGMENTS, 422, '履约段序号超出范围');
        return chr(ord('A') + $index);
    }

    private function indexForSuffix(string $suffix): int
    {
        $suffix = strtoupper(trim($suffix));
        abort_unless(strlen($suffix) === 1 && $suffix >= 'A' && $suffix <= 'Z', 422, '履约后缀无效');
        return ord($suffix) - ord('A');
    }

    private function upsertChild(Order $parent, string $suffix, array $segment, Authenticatable $actor): Order
    {
        $child = Order::query()->firstOrNew([
            'fulfillment_parent_id' => $parent->getKey(),
            'fulfillment_suffix' => $suffix,
            'fulfillment_role' => 'original',
        ]);
        if ($child->exists) $this->assertChildMayBeRebuilt($child);

        $child->forceFill([
            'customer_id' => $parent->customer_id,
            'internal_order_number' => 'FULF-'.$parent->getKey().'-'.$suffix,
            'erp_order_id' => $parent->erp_order_id.$suffix,
            'source_type' => OrderSourceType::ManualOther,
            'order_kind' => OrderKind::FulfillmentDerived,
            'external_order_number' => null,
            'order_date' => $parent->order_date,
            'order_note' => $parent->order_note,
            'owner_id' => $parent->owner_id,
            'order_status' => OrderStatus::PendingShipment,
            'currency' => $parent->currency,
        ])->save();

        if ($parent->address) {
            $child->address()->updateOrCreate([], $parent->address->only([
                'recipient_name', 'phone', 'country', 'province_state', 'city',
                'address_line_1', 'address_line_2', 'postal_code',
            ]));
        }

        $sourceItems = collect($segment['items'] ?? [])->keyBy(fn (array $item): int => (int) ($item['order_item_id'] ?? 0));
        abort_if($sourceItems->isEmpty(), 422, '拆分订单缺少 SKU');
        $parentItems = $parent->items->keyBy('id');
        $child->items()->delete();
        foreach ($sourceItems as $sourceItemId => $segmentItem) {
            $source = $parentItems->get((int) $sourceItemId);
            abort_unless($source, 422, '拆分方案包含不属于原订单的 SKU');
            $resolvedSku = trim((string) ($segmentItem['resolved_fulfillment_sku'] ?? ''));
            $resolvedProductId = isset($segmentItem['resolved_product_id']) && $segmentItem['resolved_product_id'] !== null
                ? (int) $segmentItem['resolved_product_id']
                : $source->product_id;
            $resolvedSummary = trim((string) ($segmentItem['resolved_product_summary'] ?? ''));
            $child->items()->create([
                'product_id' => $resolvedProductId,
                'woocommerce_line_item_id' => $source->woocommerce_line_item_id,
                'product_summary' => $resolvedSummary !== '' ? $resolvedSummary : $source->product_summary,
                'sku' => $resolvedSku !== '' ? $resolvedSku : $source->sku,
                'source_sku' => $source->source_sku ?: $source->sku,
                'resolved_fulfillment_sku' => $resolvedSku !== '' ? $resolvedSku : ($source->resolved_fulfillment_sku ?: $source->sku),
                'quantity' => $source->quantity,
                'unit_price' => $source->unit_price,
                'line_revenue' => $source->line_revenue,
            ]);
        }

        $this->assignShipment($child->fresh(['items', 'shipment']), $segment, $actor);
        return $child->fresh(['items', 'shipment.warehouse']);
    }

    private function assignShipment(Order $order, array $segment, Authenticatable $actor): void
    {
        $warehouseId = (int) ($segment['warehouse_id'] ?? 0);
        abort_if($warehouseId <= 0, 422, '履约方案缺少有效仓库');
        $attributes = [
            'warehouse_id' => $warehouseId,
            'warehouse_selection_source' => 'allocation',
        ];
        if (filled($segment['carrier'] ?? null)) {
            $attributes['carrier'] = trim((string) $segment['carrier']);
            $attributes['service'] = filled($segment['service'] ?? null) ? trim((string) $segment['service']) : null;
            $attributes['carrier_selection_source'] = (string) ($segment['carrier_selection_source'] ?? 'automatic');
        }
        $this->shipments->saveDraft($order, $attributes, $actor);
    }

    private function assertParentShipmentMayBeReplaced(Order $parent): void
    {
        $this->assertNoActiveReservation($parent);
        $shipment = $parent->shipment;
        if (! $shipment) return;
        abort_if($shipment->shipment_status !== 'pending' || filled($shipment->tracking_number) || $shipment->shipped_at !== null, 409, '原订单已经进入实际物流，不能再拆单');
    }

    private function assertChildMayBeRebuilt(Order $child): void
    {
        $this->assertNoActiveReservation($child);
        $shipment = $child->shipment;
        if (! $shipment) return;
        abort_if($shipment->shipment_status !== 'pending' || filled($shipment->tracking_number) || $shipment->shipped_at !== null, 409, "衍生订单 {$child->erp_order_id} 已进入实际物流，不能重建");
    }

    private function assertNoActiveReservation(Order $order): void
    {
        abort_if(
            InventoryReservation::query()->where('order_id', $order->getKey())
                ->whereIn('status', ['reserved', 'committed'])->exists(),
            409,
            '订单已有预留或已扣减库存，不能直接重建履约商品；请先使用受控库存重规划流程'
        );
    }

    private function clearPendingChildren(Order $parent): void
    {
        $parent->originalFulfillmentChildren()->get()->each(fn (Order $child) => $this->deletePendingChild($child));
    }

    private function deletePendingChild(Order $child): void
    {
        $this->assertChildMayBeRebuilt($child);
        $child->delete();
    }

    /** @return array<string,mixed> */
    private function summary(Order $order, array $segment, bool $derived): array
    {
        return [
            'order_id' => (int) $order->getKey(),
            'erp_order_id' => $order->erp_order_id,
            'derived' => $derived,
            'suffix' => $order->fulfillment_suffix,
            'warehouse_id' => $order->shipment?->warehouse_id,
            'warehouse_code' => $order->shipment?->warehouse?->code ?? ($segment['source_code'] ?? null),
            'warehouse_name' => $order->shipment?->warehouse?->name ?? ($segment['warehouse_name'] ?? null),
            'carrier' => $order->shipment?->carrier ?? ($segment['carrier'] ?? null),
            'service' => $order->shipment?->service ?? ($segment['service'] ?? null),
            'carrier_selection_source' => $order->shipment?->carrier_selection_source ?? ($segment['carrier_selection_source'] ?? null),
            'items' => $order->items->map(fn ($item): array => [
                'sku' => $item->resolved_fulfillment_sku ?: $item->sku,
                'source_sku' => $item->source_sku ?: $item->sku,
                'resolved_fulfillment_sku' => $item->resolved_fulfillment_sku ?: $item->sku,
                'product_summary' => $item->product_summary,
                'quantity' => (int) $item->quantity,
            ])->values()->all(),
        ];
    }
}
