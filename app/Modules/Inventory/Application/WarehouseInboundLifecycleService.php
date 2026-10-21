<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInboundBatch;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WarehouseInboundLifecycleService
{
    public const STATUSES = ['preparing','factory_packing','in_transit','port_arrived','received','ready'];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function advance(Warehouse $warehouse, int $batchId, string $targetStatus, ?Authenticatable $actor): WarehouseInboundBatch
    {
        if (! in_array($targetStatus, self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => '无效的入库批次状态。']);
        }

        return DB::transaction(function () use ($warehouse, $batchId, $targetStatus, $actor): WarehouseInboundBatch {
            $batch = WarehouseInboundBatch::query()
                ->where('warehouse_id', $warehouse->id)
                ->lockForUpdate()
                ->findOrFail($batchId);

            $currentIndex = array_search($batch->status, self::STATUSES, true);
            $targetIndex = array_search($targetStatus, self::STATUSES, true);
            if ($currentIndex === false || $targetIndex !== $currentIndex + 1) {
                throw ValidationException::withMessages(['status' => '批次状态必须按顺序推进，不能跳级或回退。']);
            }

            $before = $this->batchAudit($batch);
            if ($targetStatus === 'received') {
                $batch->received_on = now()->toDateString();
            }
            if ($targetStatus === 'ready') {
                $this->activateInventory($warehouse, $batch, $actor);
            }

            $batch->status = $targetStatus;
            $batch->save();

            $this->audit->record(
                $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
                'warehouse.inbound_status_advanced',
                $batch::class,
                $batch->id,
                before: $before,
                after: $this->batchAudit($batch),
                reason: 'warehouse_inbound_status_advanced',
            );

            return $batch->fresh(['items']);
        });
    }

    private function activateInventory(Warehouse $warehouse, WarehouseInboundBatch $batch, ?Authenticatable $actor): void
    {
        $items = $batch->items()->lockForUpdate()->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['status' => '批次没有可入库明细，不能进入正常发货。']);
        }

        $blocked = $items->filter(fn ($item): bool => ! $item->product_id || $item->match_status !== 'matched');
        if ($blocked->isNotEmpty()) {
            $skus = $blocked->map(fn ($item) => $item->canonical_sku ?: $item->source_sku ?: '未识别')->unique()->values()->implode('、');
            throw ValidationException::withMessages(['status' => '仍有待复核 SKU，不能激活库存：'.$skus]);
        }

        $putawayDate = $batch->received_on?->toDateString() ?? now()->toDateString();
        $grouped = $items->groupBy('product_id')->map(fn ($rows): int => (int) $rows->sum('quantity'));

        foreach ($grouped as $productId => $quantity) {
            if ($quantity <= 0) continue;

            $inventory = WarehouseInventory::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', (int) $productId)
                ->lockForUpdate()
                ->first();

            $before = $inventory ? $this->inventoryAudit($inventory) : null;
            if (! $inventory) {
                $inventory = new WarehouseInventory([
                    'warehouse_id' => $warehouse->id,
                    'product_id' => (int) $productId,
                    'quantity_on_hand' => 0,
                    'quantity_reserved' => 0,
                    'expected_restock_qty' => 0,
                ]);
            }

            $inventory->quantity_on_hand = max(0, (int) $inventory->quantity_on_hand) + $quantity;
            if ($inventory->inbound_at === null) $inventory->inbound_at = $putawayDate;
            $inventory->save();

            WarehouseInventoryLot::query()->create([
                'warehouse_id' => $warehouse->id,
                'product_id' => (int) $productId,
                'stock_status' => 'sellable',
                'putaway_date' => $putawayDate,
                'quantity' => $quantity,
                'sync_batch_id' => null,
            ]);

            $this->audit->record(
                $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
                'inventory.inbound_activated',
                $inventory::class,
                $inventory->id,
                before: $before,
                after: $this->inventoryAudit($inventory),
                reason: 'warehouse_inbound_inventory_activation',
            );
        }
    }

    private function batchAudit(WarehouseInboundBatch $batch): array
    {
        return $batch->only(['warehouse_id','reference','status','quantity_total','carton_count','received_on']);
    }

    private function inventoryAudit(WarehouseInventory $inventory): array
    {
        return [
            ...$inventory->only(['warehouse_id','product_id','quantity_on_hand','quantity_reserved','inbound_at','expected_restock_qty']),
            'quantity_available' => $inventory->quantity_available,
        ];
    }
}
