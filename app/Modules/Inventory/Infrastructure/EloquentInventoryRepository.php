<?php

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Application\Contracts\InventoryRepository;
use App\Modules\Inventory\Application\InventoryStockStatus;
use App\Modules\Inventory\Application\WarehouseMasterRegistry;
use App\Modules\Inventory\Infrastructure\Models\InventoryAdjustment;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseCostEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentInventoryRepository implements InventoryRepository
{
    public function __construct(private readonly WarehouseMasterRegistry $warehouseMaster) {}

    public function dashboard(): array
    {
        $rows = WarehouseInventory::query()->get();
        $warehouses = $this->warehouseList();

        $statusCounts = $rows->countBy(fn ($row) => InventoryStockStatus::classify((int) $row->quantity_available));

        return [
            'warehouse_count' => collect($warehouses)->where('is_active', true)->count(),
            'warehouse_total_count' => count($warehouses),
            'fulfillment_warehouse_count' => collect($warehouses)->where('is_fulfillment_warehouse', true)->count(),
            'inventory_only_warehouse_count' => collect($warehouses)->where('is_inventory_only_warehouse', true)->count(),
            'warehouse_data_gap_count' => collect($warehouses)->where('inventory_state', 'never_imported')->count(),
            'warehouse_confirmed_count' => collect($warehouses)->where('inventory_state', 'confirmed')->count(),
            'sku_rows' => $rows->count(),
            'quantity_on_hand' => $rows->sum('quantity_on_hand'),
            'quantity_reserved' => $rows->sum('quantity_reserved'),
            'quantity_available' => $rows->sum(fn ($row) => $row->quantity_available),
            'out_of_stock_rows' => (int) $statusCounts->get(InventoryStockStatus::OUT_OF_STOCK, 0),
            'low_stock_rows' => (int) $statusCounts->get(InventoryStockStatus::LOW_STOCK, 0),
            'in_stock_rows' => (int) $statusCounts->get(InventoryStockStatus::IN_STOCK, 0),
            'stock_alert_rows' => (int) $statusCounts->get(InventoryStockStatus::OUT_OF_STOCK, 0) + (int) $statusCounts->get(InventoryStockStatus::LOW_STOCK, 0),
            'warehouses' => $warehouses,
        ];
    }

    public function warehouseList(): array
    {
        $confirmedBatches = InventorySyncBatch::query()
            ->where('status', 'confirmed')
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('warehouse_id');

        $master = $this->warehouseMaster->all();
        $canonicalOrder = array_flip((array) config('warehouse_master.order', []));
        $fulfillmentCodes = array_map('strtoupper', (array) config('warehouse_master.fulfillment_codes', []));
        $inventoryOnlyCodes = array_map('strtoupper', (array) config('warehouse_master.inventory_only_codes', []));

        return Warehouse::query()
            ->withCount('inventories')
            ->withSum('inventories as quantity_on_hand_total', 'quantity_on_hand')
            ->withMax('inventories as inventory_updated_at', 'updated_at')
            ->orderBy('priority')
            ->orderBy('name')
            ->get()
            ->map(function ($w) use ($confirmedBatches, $master, $fulfillmentCodes, $inventoryOnlyCodes): array {
                $batches = $confirmedBatches->get($w->id, collect());
                $latest = $batches->first();
                $hasFullBaseline = $batches->contains(fn ($batch): bool => $batch->sync_mode === 'full');
                $inventoryRows = (int) $w->inventories_count;

                $state = match (true) {
                    $hasFullBaseline => 'confirmed',
                    $latest !== null => 'partial_only',
                    $inventoryRows > 0 => 'manual',
                    default => 'never_imported',
                };

                $lastInventoryAt = $latest?->stock_count_at ?? $latest?->confirmed_at;
                if ($lastInventoryAt === null && $w->inventory_updated_at !== null) {
                    $lastInventoryAt = \Illuminate\Support\Carbon::parse($w->inventory_updated_at);
                }

                $known = $state !== 'never_imported';
                $dateOpen = $w->fulfillment_available_on === null || $w->fulfillment_available_on->isToday() || $w->fulfillment_available_on->isPast();
                $canAutoAllocate = $state === 'confirmed' && $dateOpen;
                $fulfillmentState = match (true) {
                    $w->fulfillment_available_on !== null && ! $dateOpen => 'preparing',
                    $w->fulfillment_available_on !== null && $state !== 'confirmed' => 'awaiting_inventory',
                    $canAutoAllocate => 'ready',
                    default => 'not_ready',
                };

                $code = strtoupper((string) $w->code);
                $canonical = (array) ($master[$code] ?? []);

                return [
                    'id'=>$w->id,'slug'=>$w->slug,'public_slug'=>$canonical['public_slug'] ?? $w->slug,
                    'code'=>$w->code,'name'=>$w->name,'name_en'=>$canonical['name_en'] ?? null,
                    'country_code'=>$w->country_code,'role_label'=>$canonical['role_label'] ?? '仓库',
                    'is_fulfillment_warehouse'=>in_array($code, $fulfillmentCodes, true),
                    'is_inventory_only_warehouse'=>in_array($code, $inventoryOnlyCodes, true),
                    'address'=>$this->address($w),'ownership_type'=>$w->ownership_type,'is_active'=>$w->is_active,'priority'=>$w->priority,
                    'fulfillment_available_on'=>$w->fulfillment_available_on?->toDateString(),
                    'fulfillment_state'=>$fulfillmentState,
                    'fulfillment_state_label'=>match ($fulfillmentState) {
                        'preparing' => '备货中',
                        'awaiting_inventory' => '待库存确认',
                        'ready' => '可履约',
                        default => '未就绪',
                    },
                    'product_count'=>$known ? $inventoryRows : null,
                    'quantity_on_hand'=>$known ? (int)($w->quantity_on_hand_total ?? 0) : null,
                    'inventory_state'=>$state,
                    'inventory_state_label'=>match ($state) {
                        'confirmed' => '已确认',
                        'partial_only' => '仅部分数据',
                        'manual' => '手工数据',
                        default => '库存未导入',
                    },
                    'has_full_snapshot'=>$hasFullBaseline,
                    'can_auto_allocate'=>$canAutoAllocate,
                    'latest_sync_mode'=>$latest?->sync_mode,
                    'latest_batch_id'=>$latest?->id,
                    'last_inventory_at'=>$lastInventoryAt?->toIso8601String(),
                ];
            })
            ->sortBy(fn (array $row): int => $canonicalOrder[strtoupper((string) $row['code'])] ?? 9999)
            ->values()->all();
    }

    public function findWarehouseBySlug(string $slug): ?Model
    {
        $direct = Warehouse::query()->where('slug', $slug)->first();
        if ($direct !== null) return $direct;

        $code = $this->warehouseMaster->codeForSlug($slug);
        return $code === null ? null : Warehouse::query()->where('code', $code)->first();
    }
    public function createWarehouse(array $attributes): Model { return Warehouse::query()->create($attributes); }
    public function updateWarehouse(Model $warehouse, array $attributes): Model { $warehouse->fill($attributes)->save(); return $warehouse->fresh(); }
    public function warehouseDetail(Model $warehouse): Model
    {
        $warehouse = $warehouse->load(['inventories.product','inventories.warehouse','serviceRegions'])->loadCount('inventories');
        $this->attachShippedDemand($warehouse->inventories);
        return $warehouse;
    }

    public function productInventory(array $filters = [], int $perPage = 100): LengthAwarePaginator
    {
        $warehouseFilter = trim((string) ($filters['warehouse'] ?? ''));
        $stockStatus = trim((string) ($filters['stock_status'] ?? ''));
        $warehouse = $warehouseFilter !== '' ? $this->findWarehouseBySlug($warehouseFilter) : null;
        $page = WarehouseInventory::query()->with(['warehouse','product'])
            ->when($warehouseFilter !== '', fn ($q) => $warehouse ? $q->where('warehouse_id', $warehouse->getKey()) : $q->whereRaw('1 = 0'))
            ->when($filters['search'] ?? null, fn ($q,$search) => $q->whereHas('product', fn ($p) => $p
                ->where('sku','like',"%{$search}%")
                ->orWhere('name','like',"%{$search}%")
                ->orWhere('gtin','like',"%{$search}%")
                ->orWhereHas('skuAliases', fn ($aliases) => $aliases->where('alias_sku','like',"%{$search}%"))
            ))
            ->when($stockStatus === 'alert', fn ($q) => $q->whereRaw('(quantity_on_hand - quantity_reserved) <= 9'))
            ->when($stockStatus === InventoryStockStatus::OUT_OF_STOCK, fn ($q) => $q->whereRaw('(quantity_on_hand - quantity_reserved) <= 1'))
            ->when($stockStatus === InventoryStockStatus::LOW_STOCK, fn ($q) => $q->whereRaw('(quantity_on_hand - quantity_reserved) BETWEEN 2 AND 9'))
            ->when($stockStatus === InventoryStockStatus::IN_STOCK, fn ($q) => $q->whereRaw('(quantity_on_hand - quantity_reserved) >= 10'))
            ->orderByDesc('inbound_at')->orderBy('id')->paginate($perPage)->withQueryString();
        $this->attachShippedDemand($page->getCollection());
        return $page;
    }

    public function upsertInventory(Model $warehouse, Model $product, array $attributes): Model
    {
        $item = WarehouseInventory::query()->firstOrNew(['warehouse_id'=>$warehouse->getKey(),'product_id'=>$product->getKey()]);
        $before = $item->exists ? $item->only(['quantity_on_hand','quantity_reserved']) : ['quantity_on_hand'=>0,'quantity_reserved'=>0];
        $item->fill($attributes); $item->save();
        InventoryAdjustment::query()->create([
            'warehouse_id'=>$warehouse->getKey(),'product_id'=>$product->getKey(),'warehouse_inventory_id'=>$item->id,
            'quantity_before'=>$before['quantity_on_hand'],'quantity_after'=>$item->quantity_on_hand,
            'reserved_before'=>$before['quantity_reserved'],'reserved_after'=>$item->quantity_reserved,
            'actor_user_id'=>auth()->id(),'reason'=>'inventory_saved',
        ]);
        return $item->fresh(['warehouse','product']);
    }

    public function findInventory(Model $warehouse, int $inventoryId): Model { return WarehouseInventory::query()->where('warehouse_id',$warehouse->getKey())->findOrFail($inventoryId); }
    public function updateInventory(Model $inventory, array $attributes): Model
    {
        $before=$inventory->only(['quantity_on_hand','quantity_reserved']); $inventory->fill($attributes); $inventory->save();
        InventoryAdjustment::query()->create([
            'warehouse_id'=>$inventory->warehouse_id,'product_id'=>$inventory->product_id,'warehouse_inventory_id'=>$inventory->id,
            'quantity_before'=>$before['quantity_on_hand'],'quantity_after'=>$inventory->quantity_on_hand,
            'reserved_before'=>$before['quantity_reserved'],'reserved_after'=>$inventory->quantity_reserved,
            'actor_user_id'=>auth()->id(),'reason'=>'manual_inventory_adjustment',
        ]);
        return $inventory->fresh(['warehouse','product']);
    }

    public function syncServiceRegions(Model $warehouse, array $countryCodes): void
    {
        $warehouse->serviceRegions()->delete();
        foreach ($countryCodes as $index=>$code) $warehouse->serviceRegions()->create(['country_code'=>$code,'priority'=>$index+1]);
    }

    public function warehouseCosts(Model $warehouse, array $months): Collection
    {
        $start = min($months);
        $end = max($months);
        return WarehouseCostEntry::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->whereBetween('cost_month', [$start, $end.' 23:59:59'])
            ->orderByDesc('cost_month')->orderByDesc('id')->get();
    }

    public function createWarehouseCost(Model $warehouse, array $attributes): Model
    {
        return WarehouseCostEntry::query()->create(['warehouse_id' => $warehouse->getKey(), ...$attributes]);
    }

    public function findWarehouseCost(Model $warehouse, int $costId): Model
    {
        return WarehouseCostEntry::query()->where('warehouse_id', $warehouse->getKey())->findOrFail($costId);
    }

    public function deleteWarehouseCost(Model $cost): void
    {
        $cost->delete();
    }

    private function attachShippedDemand(Collection $rows): void
    {
        if ($rows->isEmpty()) return;
        $warehouseIds = $rows->pluck('warehouse_id')->filter()->unique()->values()->all();
        $productIds = $rows->pluck('product_id')->filter()->unique()->values()->all();
        if ($warehouseIds === [] || $productIds === []) return;

        $totals = collect();
        foreach (['shipments', 'shipment_attempts'] as $source) {
            $sourceTotals = DB::table($source)
                ->join('order_items', 'order_items.order_id', '=', $source.'.order_id')
                ->whereIn($source.'.warehouse_id', $warehouseIds)
                ->whereIn('order_items.product_id', $productIds)
                ->whereNotNull($source.'.shipped_at')
                ->where($source.'.shipped_at', '>=', now()->subDays(90))
                ->groupBy($source.'.warehouse_id', 'order_items.product_id')
                ->selectRaw($source.'.warehouse_id, order_items.product_id, SUM(order_items.quantity) as shipped_90d_qty')
                ->get();

            foreach ($sourceTotals as $total) {
                $key = $total->warehouse_id.':'.$total->product_id;
                $totals->put($key, (int) $totals->get($key, 0) + (int) $total->shipped_90d_qty);
            }
        }

        foreach ($rows as $row) {
            $row->setAttribute('shipped_90d_qty', (int) $totals->get($row->warehouse_id.':'.$row->product_id, 0));
        }
    }

    private function address(Model $w): string
    {
        return collect([$w->address_line1,$w->address_line2,$w->city,$w->state_region,$w->postal_code,$w->country_code])->filter()->implode(', ');
    }
}
