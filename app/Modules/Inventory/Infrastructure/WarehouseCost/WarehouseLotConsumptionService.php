<?php

namespace App\Modules\Inventory\Infrastructure\WarehouseCost;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\Contracts\WarehouseLotConsumptionCapture;
use App\Modules\Inventory\Application\WarehouseMasterRegistry;
use App\Modules\Inventory\Application\WarehouseStorageAgingPolicy;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLotConsumption;
use App\Modules\Inventory\Infrastructure\Models\WarehouseStorageAgingAccrual;
use App\Modules\Products\Infrastructure\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class WarehouseLotConsumptionService implements WarehouseLotConsumptionCapture
{
    public function __construct(
        private readonly WarehouseMasterRegistry $warehouseMaster,
        private readonly WarehouseStorageAgingPolicy $agingPolicy,
        private readonly WarehouseStorageAgingAccrualService $agingAccruals,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string,mixed> */
    public function previewForShipment(Model $shipment): array
    {
        $shipment->loadMissing(['warehouse', 'order.items']);

        $order = $shipment->order;
        $warehouse = $shipment->warehouse;
        if ($order === null || $warehouse === null) {
            return $this->blocked($shipment, 'shipment_order_or_warehouse_missing');
        }

        if (! $this->warehouseMaster->isOverseasFulfillmentSlug((string) $warehouse->slug)) {
            return [
                'status' => 'not_applicable',
                'shipment_id' => (int) $shipment->getKey(),
                'order_id' => (int) $order->getKey(),
                'warehouse_id' => (int) $warehouse->getKey(),
                'warehouse_slug' => (string) $warehouse->slug,
                'candidates' => [],
                'blocked' => [],
                'total_quantity' => 0,
                'total_storage_cost_cny' => '0.00',
            ];
        }

        if ($shipment->shipped_at === null) {
            return $this->blocked($shipment, 'shipment_shipped_at_missing');
        }

        $existing = WarehouseInventoryLotConsumption::query()
            ->where('shipment_id', $shipment->getKey())
            ->orderBy('id')
            ->get();

        if ($existing->isNotEmpty()) {
            return [
                'status' => 'noop',
                'shipment_id' => (int) $shipment->getKey(),
                'order_id' => (int) $order->getKey(),
                'warehouse_id' => (int) $warehouse->getKey(),
                'warehouse_slug' => (string) $warehouse->slug,
                'candidates' => [],
                'existing_consumption_ids' => $existing->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'blocked' => [],
                'total_quantity' => (int) $existing->sum('quantity'),
                'total_storage_cost_cny' => $this->sumDecimal($existing->pluck('allocated_storage_cost_cny')->all()),
            ];
        }

        $consumedAt = CarbonImmutable::instance($shipment->shipped_at);
        $candidates = [];
        $blockers = [];
        $totalQuantity = 0;
        $totalCost = '0.00';
        $reservedInPlan = [];

        foreach ($order->items as $item) {
            $quantity = (int) $item->quantity;
            if ($quantity <= 0) {
                continue;
            }

            $productId = $this->effectiveProductId($item);
            if ($productId === null) {
                $blockers[] = [
                    'reason' => 'fulfillment_product_unresolved',
                    'order_item_id' => (int) $item->id,
                    'resolved_fulfillment_sku' => $item->resolved_fulfillment_sku,
                ];

                continue;
            }

            $lots = WarehouseInventoryLot::query()
                ->where('warehouse_id', $warehouse->getKey())
                ->where('product_id', $productId)
                ->where('stock_status', 'sellable')
                ->where('quantity', '>', 0)
                ->whereNotNull('putaway_date')
                ->orderBy('putaway_date')
                ->orderBy('id')
                ->get();

            if ($lots->isEmpty()) {
                $blockers[] = [
                    'reason' => 'sellable_fifo_lot_missing',
                    'order_item_id' => (int) $item->id,
                    'product_id' => $productId,
                    'required_quantity' => $quantity,
                ];

                continue;
            }

            $remaining = $quantity;
            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }

                $sourceSyncBatchId = $lot->sync_batch_id !== null ? (int) $lot->sync_batch_id : null;
                $sourceInventoryLotId = (int) $lot->id;
                $lotKey = $this->lotBusinessKey(
                    (int) $lot->warehouse_id,
                    (int) $lot->product_id,
                    (string) $lot->stock_status,
                    $lot->putaway_date->toDateString(),
                    $sourceSyncBatchId === null ? $sourceInventoryLotId : null,
                );

                $consumed = WarehouseInventoryLotConsumption::query()
                    ->where('lot_business_key', $lotKey);
                if ($sourceSyncBatchId !== null) {
                    // Preserve the existing sync-lot identity so historical P9 rows
                    // remain part of the available-quantity calculation.
                    $consumed->where('source_sync_batch_id', $sourceSyncBatchId);
                    $reservationKey = 'sync:'.$sourceSyncBatchId.'|'.$lotKey;
                } else {
                    $consumed
                        ->whereNull('source_sync_batch_id')
                        ->where('source_inventory_lot_id', $sourceInventoryLotId);
                    $reservationKey = 'lot:'.$sourceInventoryLotId.'|'.$lotKey;
                }
                $alreadyConsumed = (int) $consumed->sum('quantity');
                $planned = (int) ($reservedInPlan[$reservationKey] ?? 0);

                $available = max((int) $lot->quantity - $alreadyConsumed - $planned, 0);
                if ($available === 0) {
                    continue;
                }

                $take = min($remaining, $available);
                $dueMonths = $this->agingPolicy->billableMonths($lot->putaway_date, $consumedAt);

                $unitCost = '0.00';
                if ($dueMonths > 0) {
                    $accruals = WarehouseStorageAgingAccrual::query()
                        ->where('warehouse_id', $lot->warehouse_id)
                        ->where('product_id', $lot->product_id)
                        ->where('stock_status', $lot->stock_status)
                        ->whereDate('putaway_date', $lot->putaway_date->toDateString())
                        ->whereBetween('aging_month_index', [1, $dueMonths])
                        ->orderBy('aging_month_index')
                        ->get(['aging_month_index', 'unit_rate_cny']);

                    $indexes = $accruals->pluck('aging_month_index')->map(fn ($v) => (int) $v)->all();
                    if ($indexes !== range(1, $dueMonths)) {
                        $blockers[] = [
                            'reason' => 'storage_aging_accrual_not_current',
                            'order_item_id' => (int) $item->id,
                            'product_id' => $productId,
                            'putaway_date' => $lot->putaway_date->toDateString(),
                            'due_months' => $dueMonths,
                            'present_indexes' => $indexes,
                        ];

                        continue;
                    }
                    $unitCost = $this->sumDecimal($accruals->pluck('unit_rate_cny')->all());
                }

                $amount = bcmul((string) $take, $unitCost, 2);
                $sourceReferenceParts = [
                    'p9-fifo-consumption', 'v1', 'shipment', (int) $shipment->getKey(),
                    'item', (int) $item->id,
                ];
                if ($sourceSyncBatchId !== null) {
                    $sourceReferenceParts[] = 'batch';
                    $sourceReferenceParts[] = $sourceSyncBatchId;
                } else {
                    $sourceReferenceParts[] = 'source-lot';
                    $sourceReferenceParts[] = $sourceInventoryLotId;
                }
                $sourceReferenceParts[] = 'lot';
                $sourceReferenceParts[] = substr($lotKey, 0, 20);
                $sourceReference = implode(':', $sourceReferenceParts);

                $candidates[] = [
                    'shipment_id' => (int) $shipment->getKey(),
                    'order_id' => (int) $order->getKey(),
                    'order_item_id' => (int) $item->id,
                    'warehouse_id' => (int) $warehouse->getKey(),
                    'product_id' => $productId,
                    'stock_status' => (string) $lot->stock_status,
                    'putaway_date' => $lot->putaway_date->toDateString(),
                    'source_sync_batch_id' => $sourceSyncBatchId,
                    'source_inventory_lot_id' => $sourceInventoryLotId,
                    'quantity' => $take,
                    'aging_months_charged' => $dueMonths,
                    'unit_storage_cost_cny' => $unitCost,
                    'allocated_storage_cost_cny' => $amount,
                    'consumed_at' => $consumedAt->toDateTimeString(),
                    'lot_business_key' => $lotKey,
                    'source_reference' => $sourceReference,
                ];
                $reservedInPlan[$reservationKey] = $planned + $take;
                $remaining -= $take;
                $totalQuantity += $take;
                $totalCost = bcadd($totalCost, $amount, 2);
            }

            if ($remaining > 0) {
                $blockers[] = [
                    'reason' => 'fifo_sellable_quantity_insufficient',
                    'order_item_id' => (int) $item->id,
                    'product_id' => $productId,
                    'required_quantity' => $quantity,
                    'unallocated_quantity' => $remaining,
                ];
            }
        }

        return [
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'shipment_id' => (int) $shipment->getKey(),
            'order_id' => (int) $order->getKey(),
            'warehouse_id' => (int) $warehouse->getKey(),
            'warehouse_slug' => (string) $warehouse->slug,
            'candidates' => $candidates,
            'blocked' => $blockers,
            'total_quantity' => $totalQuantity,
            'total_storage_cost_cny' => $totalCost,
            'plan_sha' => hash('sha256', json_encode($candidates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        ];
    }

    public function assertShipmentMayReset(Model $shipment): void
    {
        if (WarehouseInventoryLotConsumption::query()->where('shipment_id', $shipment->getKey())->exists()) {
            throw new RuntimeException('warehouse_fifo_consumption_already_captured_manual_reconciliation_required');
        }
    }

    /** @return array<string,mixed> */
    public function captureForShipment(Model $shipment): array
    {
        return DB::transaction(function () use ($shipment): array {
            $locked = $shipment->newQuery()->whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['warehouse', 'order.items']);
            if ($locked->warehouse !== null
                && $locked->shipped_at !== null
                && $this->warehouseMaster->isOverseasFulfillmentSlug((string) $locked->warehouse->slug)) {
                WarehouseInventoryLot::query()
                    ->where('warehouse_id', $locked->warehouse_id)
                    ->where('stock_status', 'sellable')
                    ->where('quantity', '>', 0)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);
                $agingPreview = $this->agingAccruals->preview(
                    $locked->shipped_at->toDateString(),
                    (string) $locked->warehouse->slug,
                    false,
                );
                if ((int) ($agingPreview['blocked_total'] ?? 0) > 0) {
                    throw new RuntimeException('warehouse_storage_aging_reconciliation_blocked:'.json_encode($agingPreview['blocked'], JSON_UNESCAPED_SLASHES));
                }
                if ((int) ($agingPreview['new_accrual_rows'] ?? 0) > 0) {
                    $this->agingAccruals->apply(
                        $locked->shipped_at->toDateString(),
                        (string) $locked->warehouse->slug,
                        false,
                    );
                }
            }

            $preview = $this->previewForShipment($locked);

            if (in_array($preview['status'], ['not_applicable', 'noop'], true)) {
                return $preview;
            }
            if ($preview['status'] !== 'ready') {
                throw new RuntimeException('warehouse_fifo_consumption_blocked:'.json_encode($preview['blocked'], JSON_UNESCAPED_SLASHES));
            }

            $ids = [];
            foreach ($preview['candidates'] as $candidate) {
                $row = WarehouseInventoryLotConsumption::query()->create([
                    ...$candidate,
                    'captured_at' => now(),
                ]);
                $ids[] = (int) $row->id;
            }

            $this->audit->record(
                AuditActor::system(AuditSource::InternalSystem),
                'inventory.warehouse_lot_consumption.captured',
                $locked::class,
                $locked->getKey(),
                after: [
                    'order_id' => $preview['order_id'],
                    'warehouse_id' => $preview['warehouse_id'],
                    'consumption_ids' => $ids,
                    'total_quantity' => $preview['total_quantity'],
                    'total_storage_cost_cny' => $preview['total_storage_cost_cny'],
                    'plan_sha' => $preview['plan_sha'],
                ],
                reason: 'p9_fifo_lot_consumption',
            );

            return array_replace($preview, [
                'status' => 'captured',
                'consumption_ids' => $ids,
            ]);
        });
    }

    private function effectiveProductId(Model $item): ?int
    {
        $resolvedSku = trim((string) ($item->resolved_fulfillment_sku ?? ''));
        if ($resolvedSku !== '') {
            $resolved = Product::query()->where('sku', $resolvedSku)->first(['id']);
            if ($resolved !== null) {
                return (int) $resolved->id;
            }

            return null;
        }

        return $item->product_id !== null ? (int) $item->product_id : null;
    }

    private function lotBusinessKey(
        int $warehouseId,
        int $productId,
        string $stockStatus,
        string $putawayDate,
        ?int $directSourceLotId = null,
    ): string {
        $parts = [$warehouseId, $productId, strtolower($stockStatus), $putawayDate];
        if ($directSourceLotId !== null) {
            $parts[] = 'direct-lot';
            $parts[] = $directSourceLotId;
        }

        return hash('sha256', implode('|', $parts));
    }

    /** @param iterable<mixed> $values */
    private function sumDecimal(iterable $values): string
    {
        $total = '0.00';
        foreach ($values as $value) {
            $total = bcadd($total, (string) $value, 2);
        }

        return $total;
    }

    /** @return array<string,mixed> */
    private function blocked(Model $shipment, string $reason): array
    {
        return [
            'status' => 'blocked',
            'shipment_id' => (int) $shipment->getKey(),
            'order_id' => $shipment->order_id !== null ? (int) $shipment->order_id : null,
            'candidates' => [],
            'blocked' => [['reason' => $reason]],
            'total_quantity' => 0,
            'total_storage_cost_cny' => '0.00',
        ];
    }
}
