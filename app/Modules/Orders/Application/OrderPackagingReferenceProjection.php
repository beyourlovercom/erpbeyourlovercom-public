<?php

namespace App\Modules\Orders\Application;

use App\Modules\Inventory\Application\ProductPackagingStandardReader;
use App\Modules\Logistics\Infrastructure\Models\ShipmentItemPackagingSnapshot;
use App\Modules\Orders\Infrastructure\Models\Order;

final class OrderPackagingReferenceProjection
{
    public function __construct(private readonly ProductPackagingStandardReader $standards) {}

    /** @return array<int,array<string,mixed>> */
    public function forOrder(Order $order): array
    {
        $order->loadMissing('items', 'shipment');
        $current = $this->standards->forProductIds(
            $order->items->pluck('product_id')->filter()->unique()->values()->all()
        );
        $snapshots = $order->shipment
            ? ShipmentItemPackagingSnapshot::query()->where('shipment_id', $order->shipment->id)->get()->keyBy('order_item_id')
            : collect();

        $references = [];
        foreach ($order->items as $item) {
            $snapshot = $snapshots->get($item->id);
            if ($snapshot) {
                $references[(int) $item->id] = [
                    'source' => 'shipment_snapshot',
                    'source_label' => '履约快照',
                    'measurement_record_id' => $snapshot->packaging_measurement_record_id,
                    'packaging_method_key' => $snapshot->packaging_method_key,
                    'packaging_method_label' => $snapshot->packaging_method_label,
                    'packaging_length_cm' => $snapshot->packaging_length_cm,
                    'packaging_width_cm' => $snapshot->packaging_width_cm,
                    'packaging_height_cm' => $snapshot->packaging_height_cm,
                    'packaged_weight_kg' => $snapshot->packaged_weight_kg,
                    'measured_at' => $snapshot->measured_at?->toIso8601String(),
                    'captured_at' => $snapshot->captured_at?->toIso8601String(),
                ];
                continue;
            }

            $standard = $item->product_id ? ($current[(int) $item->product_id] ?? null) : null;
            if (! $standard) continue;
            $references[(int) $item->id] = [
                ...$standard,
                'source' => 'current_product_standard',
                'source_label' => '当前产品包装标准',
            ];
        }

        return $references;
    }
}
