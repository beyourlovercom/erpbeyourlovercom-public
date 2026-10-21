<?php

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Application\Contracts\InventoryBatchSyncRepository;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncRow;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class EloquentInventoryBatchSyncRepository implements InventoryBatchSyncRepository
{
    public function createBatch(array $attributes): Model
    {
        return InventorySyncBatch::query()->create($attributes);
    }

    public function createRow(array $attributes): Model
    {
        return InventorySyncRow::query()->create($attributes);
    }

    public function countPositiveInventoryExcluding(int $warehouseId, array $productIds): int
    {
        $query = WarehouseInventory::query()
            ->where('warehouse_id', $warehouseId)
            ->where('quantity_on_hand', '>', 0);
        if ($productIds !== []) $query->whereNotIn('product_id', $productIds);
        return $query->count();
    }

    public function lockBatch(int $batchId): Model
    {
        return InventorySyncBatch::query()->whereKey($batchId)->lockForUpdate()->firstOrFail();
    }

    public function confirmedRows(Model $batch): Collection
    {
        return InventorySyncRow::query()
            ->where('sync_batch_id', $batch->getKey())
            ->where('validation_status', '!=', 'error')
            ->whereNotNull('product_id')
            ->get();
    }

    public function deleteAllLots(int $warehouseId): void
    {
        WarehouseInventoryLot::query()->where('warehouse_id', $warehouseId)->delete();
    }

    public function zeroAbsentInventory(int $warehouseId, array $productIds): void
    {
        $query = WarehouseInventory::query()->where('warehouse_id', $warehouseId);
        if ($productIds !== []) $query->whereNotIn('product_id', $productIds);
        $query->update(['quantity_on_hand' => 0, 'inbound_at' => null]);
    }

    public function deleteLotsForProducts(int $warehouseId, array $productIds): void
    {
        if ($productIds === []) return;
        WarehouseInventoryLot::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->delete();
    }

    public function createLot(array $attributes): Model
    {
        return WarehouseInventoryLot::query()->create($attributes);
    }

    public function findInventory(int $warehouseId, int $productId): ?Model
    {
        return WarehouseInventory::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->first();
    }

    public function upsertInventory(int $warehouseId, int $productId, array $attributes): Model
    {
        return WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouseId, 'product_id' => $productId],
            $attributes,
        );
    }

    public function history(int $warehouseId, int $perPage = 20): LengthAwarePaginator
    {
        return InventorySyncBatch::query()
            ->where('warehouse_id', $warehouseId)
            ->with('creator', 'confirmer')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function detail(int $batchId): Model
    {
        return InventorySyncBatch::query()
            ->with(['warehouse', 'creator', 'confirmer', 'rows'])
            ->findOrFail($batchId);
    }
}
