<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\InventoryBatchSyncService;
use App\Modules\Inventory\Application\Sync\NormalizedRow;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Products\Application\SkuAliasService;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SkuInventoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(string $sku): Product
    {
        return Product::create([
            'source_type' => 'manual',
            'woocommerce_product_id' => null,
            'sku' => $sku,
            'name' => "Product {$sku}",
            'product_type' => 'simple',
        ]);
    }

    private function makeWarehouse(): Warehouse
    {
        return Warehouse::create(['name' => 'Test WH', 'code' => 'TWH'.uniqid(), 'slug' => 'twh-'.uniqid(), 'is_active' => true]);
    }

    private function makeRow(string $sku, int $qty = 10): NormalizedRow
    {
        return new NormalizedRow(
            sourceRowNo: 1,
            sku: $sku,
            stockStatus: 'sellable',
            stockCountAt: null,
            putawayDate: null,
            quantity: $qty,
            rawProductName: null,
            rawWarehouseName: null,
            rawDimension: null,
            rawTotalVolume: null,
            rawStockStatus: 'sellable',
            rawStockCountDate: null,
            rawPutawayDate: null,
            rawQuantity: (string) $qty,
        );
    }

    // ── Gate 8: canonical SKU resolves as before ─────────────────────────────

    public function test_canonical_sku_resolves_correctly_in_inventory(): void
    {
        $product = $this->makeProduct('VM467A');
        $warehouse = $this->makeWarehouse();
        $service = app(InventoryBatchSyncService::class);

        $batch = $service->preview($warehouse, [$this->makeRow('VM467A')], 'csv', 'partial', null, null, null);

        $row = $batch->rows->first();
        $this->assertSame('ok', $row->validation_status);
        $this->assertSame($product->id, $row->product_id);
        $this->assertSame('VM467A', $row->sku);
        $this->assertSame('VM467A', $row->raw_sku);
    }

    // ── Gate 9: alias SKU resolves to canonical Product ID/SKU ──────────────

    public function test_alias_sku_resolves_to_canonical_in_inventory(): void
    {
        $product = $this->makeProduct('VM467A');
        $aliasService = app(SkuAliasService::class);
        $aliasService->createAlias($product->id, 'VM467A DARK GREEN');

        $warehouse = $this->makeWarehouse();
        $service = app(InventoryBatchSyncService::class);
        $batch = $service->preview($warehouse, [$this->makeRow('VM467A DARK GREEN')], 'csv', 'partial', null, null, null);

        $row = $batch->rows->first();
        $this->assertSame('ok', $row->validation_status);
        $this->assertSame($product->id, $row->product_id);
        $this->assertSame('VM467A', $row->sku, 'canonical SKU must be stored');
        $this->assertSame('VM467A DARK GREEN', $row->raw_sku, 'raw_sku must preserve input');
    }

    // ── Gate 10: unmatched SKU remains blocked and appears in reconciliation ─

    public function test_unmatched_sku_blocked_and_queued_for_reconciliation(): void
    {
        $warehouse = $this->makeWarehouse();
        $service = app(InventoryBatchSyncService::class);
        $batch = $service->preview($warehouse, [$this->makeRow('TOTALLY-UNKNOWN')], 'csv', 'partial', null, null, null);

        $row = $batch->rows->first();
        $this->assertSame('error', $row->validation_status);
        $this->assertNull($row->product_id);

        $this->assertDatabaseHas('product_sku_reconciliations', [
            'raw_sku' => 'TOTALLY-UNKNOWN',
            'status' => 'open',
        ]);
    }

    // ── Gate 11: canonical + alias rows for same Product share one identity ──

    public function test_canonical_and_alias_rows_share_product_identity(): void
    {
        $product = $this->makeProduct('VM467A');
        $aliasService = app(SkuAliasService::class);
        $aliasService->createAlias($product->id, 'VM467A DARK GREEN');

        $warehouse = $this->makeWarehouse();
        $service = app(InventoryBatchSyncService::class);

        // Two rows: one canonical, one alias
        $rows = [$this->makeRow('VM467A', 5), $this->makeRow('VM467A DARK GREEN', 3)];
        $batch = $service->preview($warehouse, $rows, 'csv', 'partial', null, null, null);

        // Both rows resolve to the same canonical product_id: preview_duplicate_skus counts
        // the canonical-identity group that appears more than once (1 group detected), confirming
        // that the deduplication key is the resolved canonical product_id, not the raw input SKU.
        $this->assertSame(1, $batch->preview_duplicate_skus, 'same canonical identity must count as one duplicate group');
        $this->assertSame(0, $batch->preview_error_rows);
        $rows = $batch->rows;
        $this->assertSame($product->id, $rows[0]->product_id);
        $this->assertSame($product->id, $rows[1]->product_id);
    }
}
