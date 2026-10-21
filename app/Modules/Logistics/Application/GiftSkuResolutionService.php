<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Inventory\Infrastructure\Models\WarehouseClearanceProduct;
use App\Modules\Orders\Infrastructure\Models\OrderItem;

final class GiftSkuResolutionService
{
    public function __construct(private readonly InventoryAvailabilityReader $inventory) {}

    public function isGiftSku(?string $sku): bool
    {
        return strcasecmp(trim((string) $sku), 'gift') === 0;
    }

    /** @return array<string,mixed> */
    public function prepare(): array
    {
        $mappings = WarehouseClearanceProduct::query()
            ->with(['warehouse:id,code,name,is_active', 'product:id,sku,name,is_active'])
            ->where('source_sku', 'gift')
            ->where('is_active', true)
            ->orderBy('priority')->orderBy('id')->get();
        $productIds = $mappings->pluck('fulfillment_product_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        return ['mappings' => $mappings, 'availability' => $this->inventory->forProductIds($productIds)];
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function options(string $sourceSku, int $quantity, ?array $context = null): array
    {
        if (! $this->isGiftSku($sourceSku)) return [];
        $context ??= $this->prepare();
        $availability = (array) ($context['availability'] ?? []);

        return collect($context['mappings'] ?? [])->filter(function ($mapping) use ($sourceSku): bool {
            return strcasecmp((string) $mapping->source_sku, trim($sourceSku)) === 0
                && (bool) $mapping->warehouse?->is_active
                && (bool) $mapping->product?->is_active
                && filled($mapping->product?->sku);
        })->map(function ($mapping) use ($availability, $quantity): array {
            $row = collect($availability[(int) $mapping->fulfillment_product_id] ?? [])->first(
                fn (array $candidate): bool => (int) ($candidate['warehouse_id'] ?? 0) === (int) $mapping->warehouse_id
            );
            $available = $row['quantity_available'] ?? null;
            return [
                'mapping_id' => (int) $mapping->id,
                'warehouse_id' => (int) $mapping->warehouse_id,
                'warehouse_code' => strtoupper((string) $mapping->warehouse->code),
                'warehouse_name' => (string) $mapping->warehouse->name,
                'fulfillment_product_id' => (int) $mapping->fulfillment_product_id,
                'fulfillment_sku' => (string) $mapping->product->sku,
                'fulfillment_name' => (string) $mapping->product->name,
                'quantity_available' => $available === null ? null : (int) $available,
                'can_fulfill' => $available !== null && (int) $available >= max(1, $quantity),
                'priority' => (int) $mapping->priority,
            ];
        })->groupBy('warehouse_code')->map(fn ($rows) => $rows->values()->all())->all();
    }

    /** @return array<string,mixed>|null */
    public function resolve(OrderItem $item, string $warehouseCode, ?int $mappingId): ?array
    {
        if (! $this->isGiftSku($item->sku)) return null;
        abort_unless($mappingId && $mappingId > 0, 422, 'gift 必须选择实际履约礼品 SKU');
        $mapping = WarehouseClearanceProduct::query()->with(['warehouse', 'product'])->whereKey($mappingId)->where('is_active', true)->first();
        abort_unless($mapping && $mapping->warehouse?->is_active && $mapping->product?->is_active, 422, '所选礼品 SKU 映射无效或已停用');
        abort_unless(strcasecmp((string) $mapping->source_sku, (string) $item->sku) === 0, 422, '所选礼品 SKU 与订单 gift 行不匹配');
        abort_unless(strcasecmp((string) $mapping->warehouse->code, trim($warehouseCode)) === 0, 422, '所选礼品 SKU 不属于当前发货仓');
        abort_unless(filled($mapping->product->sku), 422, '礼品 Product Master 缺少实际 SKU');

        $availability = $this->inventory->forProductIds([(int) $mapping->fulfillment_product_id]);
        $row = collect($availability[(int) $mapping->fulfillment_product_id] ?? [])->first(
            fn (array $candidate): bool => (int) ($candidate['warehouse_id'] ?? 0) === (int) $mapping->warehouse_id
        );
        $available = $row['quantity_available'] ?? null;
        abort_unless($available !== null && (int) $available >= (int) $item->quantity, 422, '所选实际礼品 SKU 在该仓库存不足或库存未知');

        return [
            'gift_mapping_id' => (int) $mapping->id,
            'source_sku' => (string) $item->sku,
            'resolved_product_id' => (int) $mapping->fulfillment_product_id,
            'resolved_fulfillment_sku' => (string) $mapping->product->sku,
            'resolved_product_summary' => (string) $mapping->product->name,
            'quantity_available' => (int) $available,
        ];
    }
}
