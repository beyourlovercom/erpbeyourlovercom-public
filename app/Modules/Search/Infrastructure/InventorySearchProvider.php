<?php

namespace App\Modules\Search\Infrastructure;

use App\Modules\Inventory\Application\WarehouseMasterRegistry;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Search\Application\Contracts\SearchProvider;

final class InventorySearchProvider implements SearchProvider
{
    public function __construct(private readonly WarehouseMasterRegistry $warehouses) {}

    public function key(): string { return 'inventory'; }

    public function label(): string { return '库存'; }

    public function permission(): string { return 'inventory.view'; }

    public function viewAllHref(string $query): string
    {
        return '/inventory/products?search='.rawurlencode($query);
    }

    public function search(string $query, int $limit): array
    {
        $needle = trim($query);
        $like = '%'.$needle.'%';

        $rows = WarehouseInventory::query()
            ->with(['product:id,sku,name', 'warehouse:id,slug,code,name'])
            ->where(function ($q) use ($like): void {
                $q->whereHas('product', function ($product) use ($like): void {
                    $product->where('sku', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhereHas('skuAliases', fn ($aliases) => $aliases->where('alias_sku', 'like', $like));
                })->orWhereHas('warehouse', fn ($warehouse) => $warehouse->where('code', 'like', $like)->orWhere('name', 'like', $like));
            })
            ->limit(40)
            ->get(['id', 'warehouse_id', 'product_id', 'quantity_on_hand', 'quantity_reserved', 'location'])
            ->sortBy(fn (WarehouseInventory $row): array => [$this->rank($row, $needle), strtoupper((string) $row->product?->sku), (int) $row->id])
            ->take($limit)
            ->values();

        return $rows->map(function (WarehouseInventory $row): array {
            $sku = (string) ($row->product?->sku ?: '无 SKU');
            $warehouseName = (string) ($row->warehouse?->name ?: $row->warehouse?->code ?: '仓库');
            $available = max((int) $row->quantity_on_hand - (int) $row->quantity_reserved, 0);
            $slug = $this->warehouses->publicSlugForCode((string) $row->warehouse?->code, (string) $row->warehouse?->slug);

            return [
                'id' => 'inventory:'.$row->id,
                'label' => $sku.' · '.$warehouseName,
                'subtitle' => '可用 '.$available.' · 当前 '.(int) $row->quantity_on_hand.' · 预留 '.(int) $row->quantity_reserved,
                'href' => '/inventory/products?search='.rawurlencode($sku).'&warehouse='.rawurlencode($slug),
                'meta' => [
                    'inventory_id' => (int) $row->id,
                    'product_id' => (int) $row->product_id,
                    'warehouse_id' => (int) $row->warehouse_id,
                ],
            ];
        })->all();
    }

    private function rank(WarehouseInventory $row, string $needle): int
    {
        $n = mb_strtolower($needle);
        $sku = mb_strtolower((string) $row->product?->sku);
        $warehouseCode = mb_strtolower((string) $row->warehouse?->code);

        if ($sku !== '' && $sku === $n) return 0;
        if ($warehouseCode !== '' && $warehouseCode === $n) return 1;
        if ($sku !== '' && str_starts_with($sku, $n)) return 2;

        return 3;
    }
}
