<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseClearanceProduct;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WarehouseClearanceService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array<int,array<string,mixed>> */
    public function listForWarehouse(Warehouse $warehouse): array
    {
        $rows = WarehouseClearanceProduct::query()
            ->with('product:id,sku,name,is_active')
            ->where('warehouse_id', $warehouse->id)
            ->where('source_sku', 'gift')
            ->where('is_active', true)
            ->orderBy('priority')->orderBy('id')->get();
        $inventory = WarehouseInventory::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('product_id', $rows->pluck('fulfillment_product_id'))
            ->get()->keyBy('product_id');

        return $rows->map(function (WarehouseClearanceProduct $row) use ($inventory): array {
            $stock = $inventory->get($row->fulfillment_product_id);
            $available = $stock?->quantity_available;
            return [
                'id' => (int) $row->id,
                'product' => [
                    'id' => (int) $row->fulfillment_product_id,
                    'sku' => (string) ($row->product?->sku ?? ''),
                    'name' => (string) ($row->product?->name ?? ''),
                ],
                'priority' => (int) $row->priority,
                'quantity_on_hand' => $stock ? (int) $stock->quantity_on_hand : null,
                'quantity_reserved' => $stock ? (int) $stock->quantity_reserved : null,
                'quantity_available' => $available === null ? null : (int) $available,
                'status' => $available === null ? 'unknown' : ((int) $available > 0 ? 'available' : 'out_of_stock'),
                'status_label' => $available === null ? '库存未知' : ((int) $available > 0 ? '可用' : '缺货'),
            ];
        })->values()->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function candidatesForWarehouse(Warehouse $warehouse): array
    {
        $activeIds = WarehouseClearanceProduct::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('source_sku', 'gift')->where('is_active', true)
            ->pluck('fulfillment_product_id')->map(fn ($id) => (int) $id)->all();

        return WarehouseInventory::query()
            ->with('product:id,sku,name,is_active')
            ->where('warehouse_id', $warehouse->id)
            ->orderBy('product_id')->get()
            ->filter(fn (WarehouseInventory $row): bool => (bool) $row->product?->is_active)
            ->map(fn (WarehouseInventory $row): array => [
                'product_id' => (int) $row->product_id,
                'sku' => (string) $row->product->sku,
                'name' => (string) $row->product->name,
                'quantity_available' => (int) $row->quantity_available,
                'already_clearance' => in_array((int) $row->product_id, $activeIds, true),
            ])->values()->all();
    }

    /** @param array<int,int|string> $productIds */
    public function add(Warehouse $warehouse, array $productIds, ?Authenticatable $actor): void
    {
        $ids = collect($productIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) throw ValidationException::withMessages(['product_ids' => '请选择至少一个仓库库存商品。']);
        $existingInventoryIds = WarehouseInventory::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('product_id', $ids->all())
            ->pluck('product_id')->map(fn ($id) => (int) $id);
        $missing = $ids->diff($existingInventoryIds)->values();
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['product_ids' => '只能加入当前仓库真实库存中存在的商品。']);
        }

        DB::transaction(function () use ($warehouse, $ids, $actor): void {
            $priority = (int) WarehouseClearanceProduct::query()
                ->where('warehouse_id', $warehouse->id)->where('source_sku', 'gift')
                ->where('is_active', true)->max('priority');
            foreach ($ids as $productId) {
                $row = WarehouseClearanceProduct::query()->firstOrNew([
                    'warehouse_id' => $warehouse->id,
                    'source_sku' => 'gift',
                    'fulfillment_product_id' => $productId,
                ]);
                if ($row->exists && $row->is_active) continue;
                $before = $row->exists ? $this->auditPayload($row) : null;
                $row->priority = ++$priority;
                $row->is_active = true;
                $row->save();
                $this->record($actor, 'warehouse.clearance_added', $row, $before, $this->auditPayload($row), 'warehouse_clearance_added');
            }
        });
    }

    public function updatePriority(Warehouse $warehouse, int $clearanceId, int $priority, ?Authenticatable $actor): void
    {
        DB::transaction(function () use ($warehouse, $clearanceId, $priority, $actor): void {
            $rows = WarehouseClearanceProduct::query()
                ->where('warehouse_id', $warehouse->id)->where('source_sku', 'gift')->where('is_active', true)
                ->orderBy('priority')->orderBy('id')->get();
            /** @var WarehouseClearanceProduct|null $target */
            $target = $rows->firstWhere('id', $clearanceId);
            abort_if($target === null, 404);
            $before = $this->auditPayload($target);
            $ordered = $rows->reject(fn (WarehouseClearanceProduct $row): bool => $row->id === $target->id)->values();
            $position = min(max(1, $priority), $ordered->count() + 1) - 1;
            $ordered->splice($position, 0, [$target]);
            foreach ($ordered as $index => $row) {
                $normalized = $index + 1;
                if ((int) $row->priority !== $normalized) {
                    $row->priority = $normalized;
                    $row->save();
                }
            }
            $target->refresh();
            $this->record($actor, 'warehouse.clearance_priority_updated', $target, $before, $this->auditPayload($target), 'warehouse_clearance_priority_updated');
        });
    }

    public function remove(Warehouse $warehouse, int $clearanceId, ?Authenticatable $actor): void
    {
        $row = $this->activeRow($warehouse, $clearanceId);
        $before = $this->auditPayload($row);
        $row->is_active = false;
        $row->save();
        $this->record($actor, 'warehouse.clearance_removed', $row, $before, $this->auditPayload($row), 'warehouse_clearance_removed');
    }

    private function activeRow(Warehouse $warehouse, int $clearanceId): WarehouseClearanceProduct
    {
        return WarehouseClearanceProduct::query()
            ->whereKey($clearanceId)->where('warehouse_id', $warehouse->id)
            ->where('source_sku', 'gift')->where('is_active', true)->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function auditPayload(WarehouseClearanceProduct $row): array
    {
        return [
            'warehouse_id' => (int) $row->warehouse_id,
            'product_id' => (int) $row->fulfillment_product_id,
            'source_sku' => (string) $row->source_sku,
            'priority' => (int) $row->priority,
            'is_active' => (bool) $row->is_active,
        ];
    }

    private function record(?Authenticatable $actor, string $action, Model $entity, ?array $before, array $after, string $reason): void
    {
        $this->audit->record(
            $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
            $action, get_class($entity), $entity->getKey(), before: $before, after: $after, reason: $reason,
        );
    }
}
