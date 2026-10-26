<?php

namespace App\Modules\Orders\Application;

use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;

/** Optimistic, versioned revision of all facts that an amendment may replace. */
final class OrderAmendmentRevision
{
    public function forOrder(Order $order): string
    {
        $order = Order::query()->whereKey($order->getKey())->firstOrFail();
        $children = $order->fulfillmentChildren()->with(['shipment', 'items'])->orderBy('id')->get();
        $ids = $children->pluck('id')->push($order->id)->all();
        $reservations = InventoryReservation::query()->whereIn('order_id', $ids)
            ->whereIn('status', ['reserved', 'committed'])->with('items')
            ->orderBy('id')->get();
        $allocation = $order->fulfillmentAllocation()->first();
        $shipment = $order->shipment()->first();

        $state = [
            'order' => [
                $order->id, $order->order_status?->value, $order->source_type?->value,
                $order->getRawOriginal('updated_at'), $order->fulfillment_parent_id,
            ],
            'items' => $order->items()->orderBy('id')->get()->map(fn ($item) => [
                $item->id, $item->product_id, $item->sku, $item->product_summary,
                (int) $item->quantity, (string) $item->unit_price, $item->getRawOriginal('updated_at'),
            ])->all(),
            'shipment' => $this->shipmentState($shipment),
            'allocation' => $allocation ? [
                $allocation->id, $allocation->status, $allocation->plan_snapshot,
                $allocation->manual_assignments, $allocation->getRawOriginal('updated_at'),
            ] : null,
            'children' => $children->map(fn (Order $child) => [
                $child->id, $child->order_status?->value, $child->getRawOriginal('updated_at'),
                $child->fulfillment_suffix, $child->fulfillment_role,
                $this->shipmentState($child->shipment),
                $child->items->sortBy('id')->map(fn ($item) => [
                    $item->id, $item->product_id, $item->sku, $item->source_sku,
                    (int) $item->quantity, (string) $item->unit_price, $item->getRawOriginal('updated_at'),
                ])->values()->all(),
            ])->all(),
            'reservations' => $reservations->map(fn ($r) => [
                $r->id, $r->order_id, $r->warehouse_id, $r->status,
                $r->getRawOriginal('updated_at'),
                $r->items->sortBy('product_id')->map(fn ($item) => [
                    $item->product_id, $item->warehouse_inventory_id, $item->quantity,
                ])->values()->all(),
            ])->all(),
        ];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    /** Use raw DB values: Carbon timezone conversions make identical MySQL snapshots hash differently across workers. */
    private function shipmentState(?Shipment $shipment): ?array
    {
        if ($shipment === null) return null;

        return [
            $shipment->id, $shipment->warehouse_id, $shipment->shipment_status,
            $shipment->tracking_number, $shipment->getRawOriginal('shipped_at'),
            $shipment->carrier, $shipment->service, $shipment->getRawOriginal('updated_at'),
        ];
    }
}
