<?php

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Application\Contracts\P9WarehouseStorageCostEvidenceReader;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLotConsumption;
use App\Modules\Orders\Infrastructure\Models\Order;

final class EloquentP9WarehouseStorageCostEvidenceReader implements P9WarehouseStorageCostEvidenceReader
{
    /** @return array<string,mixed>|null */
    public function read(int $orderId): ?array
    {
        $order = Order::query()->with('fulfillmentChildren:id,fulfillment_parent_id')->find($orderId);
        if ($order === null) return null;

        $orderIds = collect([$order->id])
            ->merge($order->fulfillmentChildren->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $rows = WarehouseInventoryLotConsumption::query()
            ->whereIn('order_id', $orderIds)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) return null;

        $amount = '0.00';
        $quantity = 0;
        $fingerprint = [];
        foreach ($rows as $row) {
            $amount = bcadd($amount, (string) $row->allocated_storage_cost_cny, 2);
            $quantity += (int) $row->quantity;
            $fingerprint[] = implode('|', [
                (int) $row->id,
                (string) $row->source_reference,
                (int) $row->quantity,
                (string) $row->allocated_storage_cost_cny,
            ]);
        }
        sort($fingerprint);

        return [
            'source_amount' => $amount,
            'currency' => 'CNY',
            'consumed_units' => $quantity,
            'consumption_ids' => $rows->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'shipment_ids' => $rows->pluck('shipment_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'warehouse_ids' => $rows->pluck('warehouse_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'source_reference' => 'p9-storage-aging-allocation:v1:order:'.$orderId.':'.substr(hash('sha256', implode(';', $fingerprint)), 0, 24),
            'source_at' => $rows->max('captured_at')?->toISOString(),
        ];
    }
}
