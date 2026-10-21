<?php

namespace App\Modules\Inventory\Application\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface InventoryBatchSyncRepository
{
    public function createBatch(array $attributes): Model;
    public function createRow(array $attributes): Model;
    public function countPositiveInventoryExcluding(int $warehouseId, array $productIds): int;
    public function lockBatch(int $batchId): Model;
    public function confirmedRows(Model $batch): Collection;
    public function deleteAllLots(int $warehouseId): void;
    public function zeroAbsentInventory(int $warehouseId, array $productIds): void;
    public function deleteLotsForProducts(int $warehouseId, array $productIds): void;
    public function createLot(array $attributes): Model;
    public function findInventory(int $warehouseId, int $productId): ?Model;
    public function upsertInventory(int $warehouseId, int $productId, array $attributes): Model;
    public function history(int $warehouseId, int $perPage = 20): LengthAwarePaginator;
    public function detail(int $batchId): Model;
}
