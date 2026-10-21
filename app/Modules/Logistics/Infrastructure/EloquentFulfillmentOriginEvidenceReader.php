<?php

namespace App\Modules\Logistics\Infrastructure;

use App\Modules\Logistics\Application\Contracts\FulfillmentOriginEvidenceReader;
use App\Modules\Orders\Infrastructure\Models\Order;

final class EloquentFulfillmentOriginEvidenceReader implements FulfillmentOriginEvidenceReader
{
    public function read(int|string $orderId): ?array
    {
        if (! (bool) config('erp_accounting.multi_shipment.enabled', false)) {
            return $this->readLegacy($orderId);
        }

        $order = Order::query()->with([
            'items', 'shipment.warehouse',
            'originalFulfillmentChildren.items', 'originalFulfillmentChildren.shipment.warehouse',
        ])->find($orderId);
        if ($order === null) return null;

        $parent = $order->fulfillment_parent_id ? $order->fulfillmentParent()->with([
            'items', 'shipment.warehouse',
            'originalFulfillmentChildren.items', 'originalFulfillmentChildren.shipment.warehouse',
        ])->first() : $order;
        if ($parent === null) return null;

        $owners = $parent->originalFulfillmentChildren->isNotEmpty() ? $parent->originalFulfillmentChildren : collect([$parent]);
        $legs = [];
        foreach ($owners->sortBy('id') as $owner) {
            $shipment = $owner->shipment;
            if ($shipment === null) {
                return ['blocked' => 'fulfillment_shipment_missing', 'fulfillment_order_id' => (int) $owner->getKey()];
            }
            $warehouse = $shipment->warehouse;
            if ($warehouse === null) {
                return ['blocked' => 'shipment_warehouse_missing', 'shipment_id' => (int) $shipment->getKey()];
            }
            $items = $owner->items->map(fn ($item): array => [
                'order_item_id' => (int) $item->getKey(),
                'product_id' => $item->product_id === null ? null : (int) $item->product_id,
                'sku' => (string) ($item->resolved_fulfillment_sku ?: $item->sku),
                'quantity' => (int) $item->quantity,
            ])->values();
            $units = (int) $items->sum('quantity');
            if ($items->isEmpty() || $units <= 0) {
                return ['blocked' => 'shipment_item_quantity_missing', 'shipment_id' => (int) $shipment->getKey()];
            }
            $legs[] = [
                'shipment_id' => (int) $shipment->getKey(),
                'fulfillment_order_id' => (int) $owner->getKey(),
                'fulfillment_suffix' => $owner->fulfillment_suffix,
                'warehouse_id' => (int) $warehouse->getKey(),
                'warehouse_slug' => strtolower((string) $warehouse->slug),
                'warehouse_code' => (string) $warehouse->code,
                'warehouse_name' => (string) $warehouse->name,
                'warehouse_country_code' => $warehouse->country_code === null ? null : strtoupper((string) $warehouse->country_code),
                'warehouse_selection_source' => $shipment->warehouse_selection_source,
                'carrier' => $shipment->carrier,
                'service' => $shipment->service,
                'tracking_number' => $shipment->tracking_number,
                'shipment_status' => $shipment->shipment_status,
                'shipped_units' => $units,
                'items' => $items->all(),
                'updated_at' => $shipment->updated_at,
            ];
        }

        if ($legs === []) return ['blocked' => 'canonical_shipment_missing', 'shipment_count' => 0];
        return [
            'parent_order_id' => (int) $parent->getKey(),
            'shipment_count' => count($legs),
            'shipments' => $legs,
        ];
    }

    private function readLegacy(int|string $orderId): ?array
    {
        $shipmentClass = 'App\\Modules\\Logistics\\Infrastructure\\Models\\Shipment';
        $shipments = $shipmentClass::query()->with('warehouse')->where('order_id', $orderId)->orderBy('id')->get();
        if ($shipments->count() !== 1) {
            return [
                'blocked' => $shipments->isEmpty() ? 'canonical_shipment_missing' : 'multiple_canonical_shipments',
                'shipment_count' => $shipments->count(),
            ];
        }

        $shipment = $shipments->first();
        $warehouse = $shipment->warehouse;
        if ($warehouse === null) return ['blocked' => 'shipment_warehouse_missing', 'shipment_id' => (int) $shipment->getKey()];
        return [
            'shipment_id' => (int) $shipment->getKey(), 'warehouse_id' => (int) $warehouse->getKey(),
            'warehouse_slug' => strtolower((string) $warehouse->slug), 'warehouse_code' => (string) $warehouse->code,
            'warehouse_name' => (string) $warehouse->name,
            'warehouse_country_code' => $warehouse->country_code === null ? null : strtoupper((string) $warehouse->country_code),
            'warehouse_selection_source' => $shipment->warehouse_selection_source,
        ];
    }
}
