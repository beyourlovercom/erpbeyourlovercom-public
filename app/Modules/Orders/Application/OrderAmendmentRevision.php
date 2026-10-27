<?php

namespace App\Modules\Orders\Application;

use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Optimistic revision of all order/fulfillment/inventory facts being replaced.
 *
 * MySQL TIMESTAMP columns render in the connection's session timezone. Never
 * hash Eloquent/Carbon dates or MySQL's timezone-dependent raw timestamp text:
 * use UNIX_TIMESTAMP() (a timezone-independent instant) in batched reads.
 * SQLite tests use their stored raw values.
 */
final class OrderAmendmentRevision
{
    public function forOrder(Order $order): string
    {
        $order = Order::query()->whereKey($order->getKey())->firstOrFail();
        $items = $order->items()->orderBy('id')->get();
        $children = $order->fulfillmentChildren()
            ->with(['shipment', 'items'])->orderBy('id')->get();
        $orderIds = $children->pluck('id')->push($order->id)->all();
        $reservations = InventoryReservation::query()->whereIn('order_id', $orderIds)
            ->whereIn('status', ['reserved', 'committed'])
            ->with('items')->orderBy('id')->get();
        $allocation = $order->fulfillmentAllocation()->first();
        $shipment = $order->shipment()->first();

        $shipments = $children->pluck('shipment')->filter();
        if ($shipment !== null) $shipments->push($shipment);

        $updated = [
            'orders' => $this->stamps('orders', $orderIds),
            'order_items' => $this->stamps('order_items', $items->pluck('id')
                ->merge($children->flatMap(fn (Order $child) => $child->items->pluck('id')))->all()),
            'shipments' => $this->stamps('shipments', $shipments->pluck('id')->all(), true),
            'fulfillment_allocations' => $this->stamps('fulfillment_allocations', $allocation ? [$allocation->id] : []),
            'inventory_reservations' => $this->stamps('inventory_reservations', $reservations->pluck('id')->all()),
        ];

        $state = [
            'order' => [
                $order->id, $order->order_status?->value, $order->source_type?->value,
                $updated['orders'][$order->id]['updated'] ?? null, $order->fulfillment_parent_id,
            ],
            'items' => $items->map(fn ($item) => [
                $item->id, $item->product_id, $item->sku, $item->product_summary,
                (int) $item->quantity, (string) $item->unit_price,
                $updated['order_items'][$item->id]['updated'] ?? null,
            ])->all(),
            'shipment' => $this->shipmentState($shipment, $updated['shipments']),
            'allocation' => $allocation ? [
                $allocation->id, $allocation->status, $allocation->plan_snapshot,
                $allocation->manual_assignments,
                $updated['fulfillment_allocations'][$allocation->id]['updated'] ?? null,
            ] : null,
            'children' => $children->map(fn (Order $child) => [
                $child->id, $child->order_status?->value,
                $updated['orders'][$child->id]['updated'] ?? null,
                $child->fulfillment_suffix, $child->fulfillment_role,
                $this->shipmentState($child->shipment, $updated['shipments']),
                $child->items->sortBy('id')->map(fn ($item) => [
                    $item->id, $item->product_id, $item->sku, $item->source_sku,
                    (int) $item->quantity, (string) $item->unit_price,
                    $updated['order_items'][$item->id]['updated'] ?? null,
                ])->values()->all(),
            ])->all(),
            'reservations' => $reservations->map(fn ($r) => [
                $r->id, $r->order_id, $r->warehouse_id, $r->status,
                $updated['inventory_reservations'][$r->id]['updated'] ?? null,
                $r->items->sortBy('product_id')->map(fn ($item) => [
                    $item->product_id, $item->warehouse_inventory_id, $item->quantity,
                ])->values()->all(),
            ])->all(),
        ];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<int>|array<int,int> $ids
     * @return array<int,array{updated:mixed,shipped?:mixed}>
     */
    private function stamps(string $table, array $ids, bool $withShipped = false): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        if ($ids === []) return [];

        $mysql = DB::connection()->getDriverName() === 'mysql';
        $query = DB::table($table)->whereIn('id', $ids)->select('id')
            ->selectRaw(($mysql ? 'UNIX_TIMESTAMP(updated_at)' : 'updated_at').' AS revision_updated');
        if ($withShipped) {
            $query->selectRaw(($mysql ? 'UNIX_TIMESTAMP(shipped_at)' : 'shipped_at').' AS revision_shipped');
        }

        return $query->get()->mapWithKeys(fn ($row) => [
            (int) $row->id => [
                'updated' => $row->revision_updated,
                ...($withShipped ? ['shipped' => $row->revision_shipped] : []),
            ],
        ])->all();
    }

    /** @param array<int,array{updated:mixed,shipped?:mixed}> $stamps */
    private function shipmentState(?Shipment $shipment, array $stamps): ?array
    {
        if ($shipment === null) return null;

        return [
            $shipment->id, $shipment->warehouse_id, $shipment->shipment_status,
            $shipment->tracking_number, $stamps[$shipment->id]['shipped'] ?? null,
            $shipment->carrier, $shipment->service, $stamps[$shipment->id]['updated'] ?? null,
        ];
    }
}
