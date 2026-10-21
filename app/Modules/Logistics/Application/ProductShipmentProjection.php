<?php

namespace App\Modules\Logistics\Application;

use Illuminate\Support\Facades\DB;

/** Read-only Product projection of canonical Shipment records linked through Orders. */
final class ProductShipmentProjection
{
    /** @return list<array<string,mixed>> */
    public function forProduct(int $productId, int $limit = 20): array
    {
        return DB::table('shipments')
            ->join('orders', 'orders.id', '=', 'shipments.order_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'shipments.warehouse_id')
            ->whereExists(function ($query) use ($productId): void {
                $query->selectRaw('1')->from('order_items')
                    ->whereColumn('order_items.order_id', 'shipments.order_id')
                    ->where('order_items.product_id', $productId);
            })
            ->select('shipments.id', 'shipments.order_id', 'orders.erp_order_id', 'orders.external_order_number',
                'shipments.carrier', 'shipments.service', 'shipments.tracking_number', 'shipments.shipped_at',
                'shipments.shipment_status', 'warehouses.name as warehouse_name')
            ->orderByDesc('shipments.shipped_at')->orderByDesc('shipments.id')->limit($limit)->get()
            ->map(fn (object $shipment): array => [
                'id' => (int) $shipment->id,
                'order_id' => (int) $shipment->order_id,
                'erp_order_id' => $shipment->erp_order_id,
                'external_order_number' => $shipment->external_order_number,
                'order_number' => $shipment->erp_order_id,
                'carrier' => $shipment->carrier,
                'service' => $shipment->service,
                'tracking_number' => $shipment->tracking_number,
                'shipped_at' => $shipment->shipped_at ? substr((string) $shipment->shipped_at, 0, 10) : null,
                'status' => $shipment->shipment_status,
                'warehouse_name' => $shipment->warehouse_name,
            ])->values()->all();
    }
}
