<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\Contracts\InventoryBatchSyncRepository;
use App\Modules\Inventory\Application\Sync\NormalizedRow;
use App\Modules\Products\Application\SkuIdentityResolver;
use App\Modules\Products\Application\SkuResolveResult;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class InventoryBatchSyncService
{
    public function __construct(
        private readonly InventoryBatchSyncRepository $sync,
        private readonly SkuIdentityResolver $resolver,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Validate rows against Product Master and warehouse, return preview data.
     * Does NOT persist any inventory mutation; creates a batch record in 'preview' status.
     *
     * @param NormalizedRow[] $rows
     */
    public function preview(
        Model $warehouse,
        array $rows,
        string $sourceType,
        string $syncMode,
        ?string $originalFilename,
        ?string $stockCountAt,
        ?Authenticatable $actor,
    ): Model {
        $checksum = hash('sha256', serialize(array_map(fn ($r) => [$r->sku, $r->quantity, $r->stockStatus, $r->stockCountAt, $r->putawayDate], $rows)));

        return DB::transaction(function () use ($warehouse, $rows, $sourceType, $syncMode, $originalFilename, $stockCountAt, $checksum, $actor): Model {
            $batch = $this->sync->createBatch([
                'warehouse_id'      => $warehouse->getKey(),
                'source_type'       => $sourceType,
                'sync_mode'         => $syncMode,
                'original_filename' => $originalFilename,
                'checksum'          => $checksum,
                'stock_count_at'    => $stockCountAt,
                'status'            => 'preview',
                'creator_user_id'   => $actor?->getAuthIdentifier(),
            ]);

            $resolveMap = $this->buildResolveMap(array_filter(array_map(fn ($r) => $r->sku, $rows)));
            $stats = ['total' => 0, 'unique_skus' => [], 'sku_counts' => [], 'sellable' => 0, 'defective' => 0, 'unmatched' => 0, 'warnings' => 0, 'errors' => 0];
            $matchedProductIds = [];

            foreach ($rows as $row) {
                $stats['total']++;
                $resolveResult = $row->sku !== null ? ($resolveMap[$row->sku] ?? null) : null;
                [$valStatus, $valMessage, $productId, $canonicalSku] = $this->validateRowWithResult($row, $resolveResult, $warehouse);
                if ($valStatus === 'error') $stats['errors']++;
                if ($row->sku !== null) {
                    // Canonical identity key for deduplication: use resolved product id when known
                    $dedupeKey = $productId !== null ? "pid:{$productId}" : "raw:{$row->sku}";
                    $stats['unique_skus'][$dedupeKey] = true;
                    $stats['sku_counts'][$dedupeKey] = ($stats['sku_counts'][$dedupeKey] ?? 0) + 1;
                }
                if ($valStatus === 'warning') $stats['warnings']++;
                if ($valStatus !== 'error' && $productId !== null) {
                    $matchedProductIds[$productId] = true;
                    if ($row->stockStatus === 'sellable') $stats['sellable'] += ($row->quantity ?? 0);
                    if ($row->stockStatus === 'defective') $stats['defective'] += ($row->quantity ?? 0);
                }
                if ($productId === null && $row->sku !== null) $stats['unmatched']++;

                $this->sync->createRow([
                    'sync_batch_id'       => $batch->getKey(),
                    'source_row_no'       => $row->sourceRowNo,
                    'raw_sku'             => $row->sku,
                    'raw_product_name'    => $row->rawProductName,
                    'raw_warehouse_name'  => $row->rawWarehouseName,
                    'raw_stock_status'    => $row->rawStockStatus,
                    'raw_stock_count_date'=> $row->rawStockCountDate,
                    'raw_putaway_date'    => $row->rawPutawayDate,
                    'raw_quantity'        => $row->rawQuantity,
                    'raw_dimension'       => $row->rawDimension,
                    'raw_total_volume'    => $row->rawTotalVolume,
                    'sku'                 => $canonicalSku ?? $row->sku,
                    'stock_status'        => $row->stockStatus,
                    'stock_count_at'      => $row->stockCountAt,
                    'putaway_date'        => $row->putawayDate,
                    'quantity'            => $row->quantity,
                    'product_id'          => $productId,
                    'validation_status'   => $valStatus,
                    'validation_message'  => $valMessage,
                ]);
            }

            $duplicateSkus = count(array_filter($stats['sku_counts'], fn (int $count): bool => $count > 1));
            $zeroedSkus = $syncMode === 'full'
                ? $this->sync->countPositiveInventoryExcluding($warehouse->getKey(), array_keys($matchedProductIds))
                : 0;

            $batch->update([
                'preview_total_rows'     => $stats['total'],
                'preview_unique_skus'    => count($stats['unique_skus']),
                'preview_sellable_qty'   => $stats['sellable'],
                'preview_defective_qty'  => $stats['defective'],
                'preview_unmatched_skus' => $stats['unmatched'],
                'preview_duplicate_skus' => $duplicateSkus,
                'preview_warning_rows'   => $stats['warnings'],
                'preview_zeroed_skus'    => $zeroedSkus,
                'preview_error_rows'     => $stats['errors'],
            ]);

            return $batch->load('rows');
        });
    }

    /**
     * Confirm an existing preview batch. Exactly-once: throws if already confirmed.
     */
    public function confirm(Model $batch, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($batch, $actor): Model {
            $batch = $this->sync->lockBatch($batch->getKey());
            if ($batch->status === 'confirmed') abort(422, '批次已确认，不可重复确认');
            if ($batch->preview_error_rows > 0) abort(422, '存在错误行，无法确认');
            $warehouse = $batch->warehouse;

            // Group valid rows by SKU → collect lots per SKU+status+putaway_date
            $rows = $this->sync->confirmedRows($batch);

            // Group: [productId => [status => [putawayDate => qty]]]
            $lots = [];
            foreach ($rows as $row) {
                $pid    = $row->product_id;
                $status = $row->stock_status ?? 'sellable';
                $pdate  = $row->putaway_date?->format('Y-m-d');
                $lots[$pid][$status][$pdate] = ($lots[$pid][$status][$pdate] ?? 0) + ($row->quantity ?? 0);
            }

            $touchedProductIds = array_keys($lots);

            if ($batch->sync_mode === 'full') {
                $this->sync->deleteAllLots($warehouse->getKey());
                $this->sync->zeroAbsentInventory($warehouse->getKey(), $touchedProductIds);
            } else {
                // Partial: replace lots for each touched SKU across all statuses
                $this->sync->deleteLotsForProducts($warehouse->getKey(), $touchedProductIds);
            }

            // Insert new lots and update warehouse_inventory
            foreach ($lots as $pid => $statusGroups) {
                foreach ($statusGroups as $status => $dateBuckets) {
                    foreach ($dateBuckets as $pdate => $qty) {
                        $this->sync->createLot([
                            'warehouse_id'  => $warehouse->getKey(),
                            'product_id'    => $pid,
                            'stock_status'  => $status,
                            'putaway_date'  => $pdate,
                            'quantity'      => $qty,
                            'sync_batch_id' => $batch->getKey(),
                        ]);
                    }
                }

                // Aggregate sellable lots → update canonical warehouse_inventory
                $sellableQty = array_sum($lots[$pid]['sellable'] ?? []);
                // Earliest sellable putaway date
                $sellableDates = array_filter(array_keys($lots[$pid]['sellable'] ?? []), fn ($d) => $d !== null && $d !== '');
                $earliestPutaway = $sellableDates ? min($sellableDates) : null;

                $existing = $this->sync->findInventory($warehouse->getKey(), $pid);
                $reserved = $existing?->quantity_reserved ?? 0;

                $this->sync->upsertInventory($warehouse->getKey(), $pid, [
                    'quantity_on_hand' => $sellableQty,
                    'quantity_reserved'=> $reserved, // preserve
                    'inbound_at'       => $earliestPutaway,
                ]);
            }

            $batch->update([
                'status'           => 'confirmed',
                'confirmer_user_id'=> $actor?->getAuthIdentifier(),
                'confirmed_at'     => now(),
            ]);

            $this->audit->record(
                $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
                'inventory.batch_sync_confirmed',
                $batch::class,
                $batch->getKey(),
                before: null,
                after: [
                    'warehouse_id'   => $warehouse->getKey(),
                    'sync_mode'      => $batch->sync_mode,
                    'source_type'    => $batch->source_type,
                    'sellable_qty'   => $batch->preview_sellable_qty,
                    'defective_qty'  => $batch->preview_defective_qty,
                    'unique_skus'    => $batch->preview_unique_skus,
                ],
                reason: 'inventory_batch_sync_confirmed',
            );

            return $batch->fresh();
        });
    }

    public function batchHistory(Model $warehouse, int $perPage = 20): mixed
    {
        return $this->sync->history($warehouse->getKey(), $perPage);
    }

    public function batchDetail(int $batchId): Model
    {
        return $this->sync->detail($batchId);
    }

    // ──────────────────────────────────────────────────────────────
    /** @return array{0:string,1:string|null,2:int|null,3:string|null} [status, message, productId, canonicalSku] */
    private function validateRowWithResult(NormalizedRow $row, ?SkuResolveResult $result, Model $warehouse): array
    {
        if ($row->sku === null || $row->sku === '') {
            return ['error', 'SKU 不能为空', null, null];
        }
        if ($row->quantity === null) {
            return ['error', '数量无效', null, null];
        }
        if ($row->quantity < 0) {
            return ['error', '数量不能为负', null, null];
        }
        if ($row->stockStatus === null) {
            return ['error', "库存属性未知（原始值: {$row->rawStockStatus}），必须为 正品 或 次品", null, null];
        }
        if ($result === null || ! $result->isResolved()) {
            $this->resolver->observeUnmatched($row->sku, 'inventory_batch', null, $row->rawProductName);
            return ['error', "SKU {$row->sku} 在产品主数据中不存在", null, null];
        }
        return ['ok', null, (int) $result->product->getKey(), $result->canonicalSku];
    }

    /** @return array<string, SkuResolveResult> raw_sku => resolve result */
    private function buildResolveMap(array $skus): array
    {
        $map = [];
        foreach (array_unique($skus) as $sku) {
            $map[(string) $sku] = $this->resolver->resolve((string) $sku);
        }
        return $map;
    }
}
