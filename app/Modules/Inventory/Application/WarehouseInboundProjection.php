<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInboundBatch;

final class WarehouseInboundProjection
{
    private const LABELS = [
        'preparing' => '准备中',
        'factory_packing' => '工厂打包',
        'in_transit' => '运输中',
        'port_arrived' => '港口已到货',
        'received' => '仓库已入库',
        'ready' => '正常发货',
    ];

    private const NEXT = [
        'preparing' => ['status'=>'factory_packing','label'=>'进入工厂打包'],
        'factory_packing' => ['status'=>'in_transit','label'=>'开始运输'],
        'in_transit' => ['status'=>'port_arrived','label'=>'标记港口到货'],
        'port_arrived' => ['status'=>'received','label'=>'确认仓库入库'],
        'received' => ['status'=>'ready','label'=>'激活库存并进入正常发货'],
    ];

    public function forWarehouse(Warehouse $warehouse): array
    {
        $batches = WarehouseInboundBatch::query()
            ->where('warehouse_id', $warehouse->id)
            ->with(['items.product:id,sku,name'])
            ->orderByRaw("CASE status WHEN 'preparing' THEN 0 WHEN 'factory_packing' THEN 1 WHEN 'in_transit' THEN 2 WHEN 'port_arrived' THEN 3 WHEN 'received' THEN 4 WHEN 'ready' THEN 5 ELSE 6 END")
            ->orderBy('eta_date')
            ->orderByDesc('id')
            ->get();

        $openStatuses = ['preparing','factory_packing','in_transit','port_arrived'];
        $open = $batches->whereIn('status', $openStatuses);

        return [
            'summary' => [
                'batch_count' => $batches->count(),
                'in_transit_batches' => $open->count(),
                'in_transit_units' => $open->sum('quantity_total'),
                'in_transit_cartons' => $open->sum(fn ($b) => (int) ($b->carton_count ?? 0)),
                'received_batches' => $batches->where('status', 'received')->count(),
                'ready_batches' => $batches->where('status', 'ready')->count(),
            ],
            'statuses' => collect(WarehouseInboundLifecycleService::STATUSES)
                ->map(fn (string $status): array => ['key'=>$status,'label'=>self::LABELS[$status]])
                ->all(),
            'batches' => $batches->map(function (WarehouseInboundBatch $batch): array {
                $blockedCount = $batch->items->filter(fn ($item): bool => ! $item->product_id || $item->match_status !== 'matched')->count();
                $next = self::NEXT[$batch->status] ?? null;

                return [
                    'id' => $batch->id,
                    'reference' => $batch->reference,
                    'transport_mode' => $batch->transport_mode,
                    'status' => $batch->status,
                    'status_label' => self::LABELS[$batch->status] ?? $batch->status,
                    'next_status' => $next['status'] ?? null,
                    'next_status_label' => $next['label'] ?? null,
                    'activation_blocked_count' => $blockedCount,
                    'carton_count' => $batch->carton_count,
                    'quantity_total' => $batch->quantity_total,
                    'gross_weight_kg' => $batch->gross_weight_kg,
                    'volume_cbm' => $batch->volume_cbm,
                    'shipped_on' => $batch->shipped_on?->format('Y-m-d'),
                    'eta_date' => $batch->eta_date?->format('Y-m-d'),
                    'eta_is_estimated' => (bool) $batch->eta_is_estimated,
                    'received_on' => $batch->received_on?->format('Y-m-d'),
                    'source_filename' => $batch->source_filename,
                    'note' => $batch->note,
                    'items' => $batch->items->map(fn ($item): array => [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'source_sku' => $item->source_sku,
                        'canonical_sku' => $item->canonical_sku,
                        'source_product_name' => $item->source_product_name,
                        'quantity' => $item->quantity,
                        'evidence_type' => $item->evidence_type,
                        'match_status' => $item->match_status,
                        'product_name' => $item->product?->name ?? $item->source_product_name,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }
}
