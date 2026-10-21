<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\Contracts\InventoryRepository;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Products\Application\Contracts\ProductRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryService
{
    public function __construct(
        private readonly InventoryRepository $inventory,
        private readonly ProductRepository $products,
        private readonly AuditRecorder $audit,
        private readonly WarehouseMasterRegistry $warehouseMaster,
    ) {}

    public function dashboard(): array { return $this->inventory->dashboard(); }
    public function warehouses(): array { return $this->inventory->warehouseList(); }
    public function productInventory(array $filters = []): mixed { return $this->inventory->productInventory($filters); }
    public function searchProducts(string $query): array { return $this->products->searchSkus($query, 20); }
    public function warehousePublicSlug(Model $warehouse): string { return $this->warehouseMaster->publicSlugForCode((string) $warehouse->code, (string) $warehouse->slug); }
    public function canonicalWarehouseSlug(string $slug): ?string
    {
        $warehouse = $this->inventory->findWarehouseBySlug($slug);
        return $warehouse ? $this->warehousePublicSlug($warehouse) : null;
    }

    public function replenishmentMetrics(Model $inventory): array
    {
        $shipped90d = max(0, (int) ($inventory->shipped_90d_qty ?? 0));
        $dailyRaw = $shipped90d / 90;
        $available = max(0, (int) $inventory->quantity_available);
        $expectedRestock = max(0, (int) ($inventory->expected_restock_qty ?? 0));
        $warehouse = $inventory->warehouse;
        $lead = $warehouse?->replenishment_lead_time_days;
        $safetyDays = $warehouse?->replenishment_safety_stock_days;
        $targetDays = $warehouse?->replenishment_target_cover_days;
        $configured = $lead !== null && $safetyDays !== null && $targetDays !== null;
        $daysOfCover = $dailyRaw > 0 ? round($available / $dailyRaw, 1) : null;

        if (! $configured) {
            return [
                'policy_status' => 'unconfigured', 'policy_label' => '补货策略未配置',
                'shipped_90d_qty' => $shipped90d, 'monthly_average' => round($shipped90d / 3, 2),
                'daily_average' => round($dailyRaw, 2), 'days_of_cover' => $daysOfCover,
                'lead_time_days' => null, 'safety_stock_days' => null, 'target_cover_days' => null,
                'safety_stock_qty' => null, 'reorder_point_qty' => null, 'target_stock_qty' => null,
                'recommended_restock_qty' => null, 'reorder_needed' => null,
            ];
        }

        $safetyQty = (int) ceil($dailyRaw * (int) $safetyDays);
        $reorderPoint = (int) ceil($dailyRaw * ((int) $lead + (int) $safetyDays));
        $targetStock = (int) ceil($dailyRaw * (int) $targetDays);
        $needsReorder = $dailyRaw > 0 && $available <= $reorderPoint;
        $recommended = $needsReorder ? max($targetStock - $available - $expectedRestock, 0) : 0;
        $policyStatus = $dailyRaw <= 0 ? 'no_demand' : ($needsReorder ? 'reorder' : 'healthy');
        $policyLabel = match ($policyStatus) {
            'no_demand' => '近90天无发货',
            'reorder' => $recommended > 0 ? '建议补货' : '已由预计到货覆盖',
            default => '库存覆盖正常',
        };

        return [
            'policy_status' => $policyStatus, 'policy_label' => $policyLabel,
            'shipped_90d_qty' => $shipped90d, 'monthly_average' => round($shipped90d / 3, 2),
            'daily_average' => round($dailyRaw, 2), 'days_of_cover' => $daysOfCover,
            'lead_time_days' => (int) $lead, 'safety_stock_days' => (int) $safetyDays, 'target_cover_days' => (int) $targetDays,
            'safety_stock_qty' => $safetyQty, 'reorder_point_qty' => $reorderPoint, 'target_stock_qty' => $targetStock,
            'recommended_restock_qty' => $recommended, 'reorder_needed' => $needsReorder,
        ];
    }

    public function warehouse(string $slug): Model
    {
        $warehouse = $this->inventory->findWarehouseBySlug($slug);
        abort_if($warehouse === null, 404);
        return $this->inventory->warehouseDetail($warehouse);
    }

    public function createWarehouse(array $attributes, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($attributes, $actor): Model {
            $warehouse = $this->inventory->createWarehouse($this->normalizeWarehouse($attributes));
            $this->record($actor, 'warehouse.created', $warehouse, null, $this->warehouseAudit($warehouse), 'warehouse_created');
            return $warehouse;
        });
    }

    public function updateWarehouse(Model $warehouse, array $attributes, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($warehouse, $attributes, $actor): Model {
            $before = $this->warehouseAudit($warehouse);
            $updated = $this->inventory->updateWarehouse($warehouse, $this->normalizeWarehouse($attributes));
            $this->record($actor, 'warehouse.updated', $updated, $before, $this->warehouseAudit($updated), 'warehouse_updated');
            return $updated;
        });
    }

    public function updateBilling(Model $warehouse, ?string $markdown, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($warehouse, $markdown, $actor): Model {
            $before = ['billing_markdown' => $warehouse->billing_markdown];
            $updated = $this->inventory->updateWarehouse($warehouse, ['billing_markdown' => $markdown]);
            $this->record($actor, 'warehouse.billing_updated', $updated, $before, ['billing_markdown' => $updated->billing_markdown], 'warehouse_billing_markdown_updated');
            return $updated;
        });
    }

    public function updateRegions(Model $warehouse, array $countryCodes, ?Authenticatable $actor): Model
    {
        $codes = collect($countryCodes)->map(fn ($code) => strtoupper(trim((string) $code)))->filter()->unique()->values()->all();
        return DB::transaction(function () use ($warehouse, $codes, $actor): Model {
            $before = $warehouse->serviceRegions()->pluck('country_code')->sort()->values()->all();
            $this->inventory->syncServiceRegions($warehouse, $codes);
            $after = $warehouse->serviceRegions()->pluck('country_code')->sort()->values()->all();
            $this->record($actor, 'warehouse.service_regions_updated', $warehouse, ['country_codes' => $before], ['country_codes' => $after], 'warehouse_service_regions_updated');
            return $this->inventory->warehouseDetail($warehouse->fresh());
        });
    }

    public function saveInventory(Model $warehouse, int $productId, array $attributes, ?Authenticatable $actor): Model
    {
        $product = $this->products->findById($productId);
        abort_if($product === null, 422, '产品不存在');
        return DB::transaction(function () use ($warehouse, $product, $attributes, $actor): Model {
            $existing = $warehouse->inventories()->where('product_id', $product->getKey())->first();
            $before = $existing ? $this->inventoryAudit($existing) : null;
            $saved = $this->inventory->upsertInventory($warehouse, $product, $this->normalizeInventory($attributes));
            $this->record($actor, 'inventory.stock_saved', $saved, $before, $this->inventoryAudit($saved), $existing ? 'inventory_updated' : 'inventory_created');
            return $saved;
        });
    }

    public function updateInventory(Model $warehouse, int $inventoryId, array $attributes, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($warehouse, $inventoryId, $attributes, $actor): Model {
            $item = $this->inventory->findInventory($warehouse, $inventoryId);
            $before = $this->inventoryAudit($item);
            $updated = $this->inventory->updateInventory($item, $this->normalizeInventory($attributes));
            $this->record($actor, 'inventory.stock_adjusted', $updated, $before, $this->inventoryAudit($updated), 'manual_inventory_adjustment');
            return $updated;
        });
    }

    public function saveRestockSupplement(Model $warehouse, int $productId, int $quantity, ?Authenticatable $actor): Model
    {
        $product = $this->products->findById($productId);
        abort_if($product === null, 422, '产品不存在');

        return DB::transaction(function () use ($warehouse, $product, $quantity, $actor): Model {
            $item = WarehouseInventory::query()->firstOrNew([
                'warehouse_id' => $warehouse->getKey(),
                'product_id' => $product->getKey(),
            ]);
            if (! $item->exists) {
                $item->quantity_on_hand = 0;
                $item->quantity_reserved = 0;
                $item->expected_restock_qty = 0;
            }
            $before = $item->exists ? $this->inventoryAudit($item) : null;
            $item->expected_restock_qty = max(0, $quantity);
            $item->save();
            $item->loadMissing(['warehouse','product']);
            $this->record($actor, 'inventory.restock_supplement_saved', $item, $before, $this->inventoryAudit($item), 'restock_manual_supplement_saved');
            return $item;
        });
    }

    public function warehouseCostCenter(Model $warehouse): array
    {
        $months = collect(range(0, 2))->map(fn (int $offset): string => now()->startOfMonth()->subMonths($offset)->toDateString());
        $rows = $this->inventory->warehouseCosts($warehouse, $months->all());
        $labels = ['shipping' => 'Shipping / 运费', 'parcel_outbound' => 'Parcel Outbound / 出库处理', 'storage' => 'Storage / 仓储费', 'other' => 'Other / 其他'];

        return [
            'months' => $months->map(function (string $month) use ($rows, $labels): array {
                $monthRows = $rows->filter(fn ($row): bool => $row->cost_month?->toDateString() === $month)->values();
                $currencies = $monthRows->groupBy('currency')->map(function ($currencyRows, string $currency): array {
                    $sum = fn (string $category): float => round((float) $currencyRows->where('category', $category)->sum(fn ($row) => (float) $row->amount), 2);
                    return [
                        'currency' => $currency,
                        'total' => round((float) $currencyRows->sum(fn ($row) => (float) $row->amount), 2),
                        'shipping' => $sum('shipping'),
                        'parcel_outbound' => $sum('parcel_outbound'),
                        'storage' => $sum('storage'),
                        'other' => $sum('other'),
                    ];
                })->sortBy('currency')->values()->all();

                return [
                    'month' => substr($month, 0, 7),
                    'label' => substr($month, 0, 4).'年'.substr($month, 5, 2).'月',
                    'currencies' => $currencies,
                    'entries' => $monthRows->map(fn ($row): array => [
                        'id' => $row->id,
                        'category' => $row->category,
                        'category_label' => $labels[$row->category] ?? $row->category,
                        'amount' => (float) $row->amount,
                        'currency' => $row->currency,
                        'note' => $row->note,
                        'source_type' => $row->source_type,
                        'source_reference' => $row->source_reference,
                        'created_at' => $row->created_at?->toIso8601String(),
                    ])->all(),
                ];
            })->all(),
        ];
    }

    public function addWarehouseCost(Model $warehouse, array $attributes, ?Authenticatable $actor): Model
    {
        $attributes['cost_month'] = substr((string) $attributes['cost_month'], 0, 7).'-01';
        $attributes['category'] = strtolower(trim((string) $attributes['category']));
        $attributes['currency'] = strtoupper(trim((string) $attributes['currency']));
        $attributes['amount'] = round((float) $attributes['amount'], 2);
        $attributes['note'] = isset($attributes['note']) && trim((string) $attributes['note']) !== '' ? trim((string) $attributes['note']) : null;
        $attributes['source_type'] = 'manual';
        $attributes['created_by_user_id'] = $actor?->getAuthIdentifier();

        return DB::transaction(function () use ($warehouse, $attributes, $actor): Model {
            $cost = $this->inventory->createWarehouseCost($warehouse, $attributes);
            $this->record($actor, 'warehouse.cost_recorded', $cost, null, $cost->only(['warehouse_id','cost_month','category','amount','currency','note','source_type']), 'warehouse_cost_manual_entry');
            return $cost;
        });
    }

    public function removeWarehouseCost(Model $warehouse, int $costId, ?Authenticatable $actor): void
    {
        DB::transaction(function () use ($warehouse, $costId, $actor): void {
            $cost = $this->inventory->findWarehouseCost($warehouse, $costId);
            if ($cost->source_type !== 'manual') throw ValidationException::withMessages(['cost' => '导入账单费用不能通过手工入口删除。']);
            $before = $cost->only(['warehouse_id','cost_month','category','amount','currency','note','source_type']);
            $this->record($actor, 'warehouse.cost_deleted', $cost, $before, null, 'warehouse_cost_manual_entry_removed');
            $this->inventory->deleteWarehouseCost($cost);
        });
    }

    private function normalizeWarehouse(array $attributes): array
    {
        foreach (['slug','code'] as $key) if (isset($attributes[$key])) $attributes[$key] = strtolower(trim((string) $attributes[$key]));
        if (isset($attributes['country_code']) && $attributes['country_code'] !== '') $attributes['country_code'] = strtoupper(trim((string) $attributes['country_code']));
        if (array_key_exists('fulfillment_available_on', $attributes) && $attributes['fulfillment_available_on'] === '') $attributes['fulfillment_available_on'] = null;
        $keys = ['replenishment_lead_time_days','replenishment_safety_stock_days','replenishment_target_cover_days'];
        $values = collect($keys)->mapWithKeys(fn ($key): array => [$key => array_key_exists($key, $attributes) && $attributes[$key] !== '' ? $attributes[$key] : null]);
        $provided = $values->filter(fn ($value): bool => $value !== null)->count();
        if ($provided > 0 && $provided < 3) {
            throw ValidationException::withMessages(['replenishment_policy' => '补货策略必须同时填写补货周期、安全库存天数和目标覆盖天数。']);
        }
        if ($provided === 3 && (int) $values['replenishment_target_cover_days'] < (int) $values['replenishment_lead_time_days'] + (int) $values['replenishment_safety_stock_days']) {
            throw ValidationException::withMessages(['replenishment_target_cover_days' => '目标覆盖天数必须不少于补货周期 + 安全库存天数。']);
        }
        foreach ($keys as $key) if (array_key_exists($key, $attributes)) $attributes[$key] = $values[$key] === null ? null : (int) $values[$key];
        return $attributes;
    }

    private function normalizeInventory(array $attributes): array
    {
        $attributes['quantity_on_hand'] = max(0, (int) ($attributes['quantity_on_hand'] ?? 0));
        $attributes['quantity_reserved'] = max(0, (int) ($attributes['quantity_reserved'] ?? 0));
        $attributes['expected_restock_qty'] = max(0, (int) ($attributes['expected_restock_qty'] ?? 0));
        return $attributes;
    }

    private function record(?Authenticatable $actor, string $action, Model $entity, ?array $before, ?array $after, string $reason): void
    {
        $this->audit->record(
            $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
            $action, get_class($entity), $entity->getKey(), before: $before, after: $after, reason: $reason,
        );
    }

    private function warehouseAudit(Model $w): array
    {
        return $w->only(['slug','code','name','country_code','address_line1','address_line2','city','state_region','postal_code','timezone','type','is_active','priority','fulfillment_available_on','replenishment_lead_time_days','replenishment_safety_stock_days','replenishment_target_cover_days']);
    }

    private function inventoryAudit(Model $i): array
    {
        return [
            ...$i->only(['warehouse_id','product_id','quantity_on_hand','quantity_reserved','inbound_at','location','standard_qoh','expected_restock_qty']),
            'quantity_available' => $i->quantity_available,
        ];
    }
}
