<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\InventoryBatchSyncService;
use App\Modules\Inventory\Application\Sync\NormalizedRow;
use App\Modules\Inventory\Application\Sync\PasteAdapter;
use App\Modules\Inventory\Application\Sync\CsvAdapter;
use App\Modules\Inventory\Application\Sync\RowNormalizer;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class InventoryBatchSyncM1Test extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────
    // Access control
    // ──────────────────────────────────────────

    public function test_sync_page_requires_inventory_operate_permission(): void
    {
        $view = $this->userWith(['inventory.view']);
        $this->actingAs($view)->get('/inventory/sync')->assertForbidden();

        $op = $this->userWith(['inventory.operate']);
        $this->withoutVite();
        $this->actingAs($op)->get('/inventory/sync')->assertOk()->assertInertia(fn ($p) => $p->component('Inventory/Sync'));
    }

    // ──────────────────────────────────────────
    // Paste adapter
    // ──────────────────────────────────────────

    public function test_paste_adapter_parses_tab_separated_with_all_known_headers(): void
    {
        $text = implode("\t", ['SKU', 'Product name/产品名称', 'Warehouse/仓库', 'Stock properties/库存属性', 'Stock count date/库存统计日期', 'Putaway date/上架日期', 'Qty/数量', 'Dimension/尺寸', 'Total volume总体积']) . "\n"
              . implode("\t", ['ABC123', 'Test Product', '德国仓', '正品', '2026-08-01', '2026-07-15', '100', '10x20x5cm', '10L']);

        $rows = (new PasteAdapter())->parse($text);
        $this->assertCount(1, $rows);
        $this->assertSame('ABC123', $rows[0]->sku);
        $this->assertSame('sellable', $rows[0]->stockStatus);
        $this->assertSame(100, $rows[0]->quantity);
        $this->assertSame('2026-07-15', $rows[0]->putawayDate);
        $this->assertSame('德国仓', $rows[0]->rawWarehouseName);
        $this->assertSame('10x20x5cm', $rows[0]->rawDimension);
    }

    public function test_paste_adapter_defaults_missing_stock_status_to_sellable(): void
    {
        $text = "SKU\tQty/数量\n" . "SKU001\t50";
        $rows = (new PasteAdapter())->parse($text);
        $this->assertCount(1, $rows);
        $this->assertSame('sellable', $rows[0]->stockStatus);
    }

    public function test_paste_adapter_returns_empty_for_missing_required_columns(): void
    {
        $text = "Product name\tWarehouse\n" . "Test\t德国仓";
        $rows = (new PasteAdapter())->parse($text);
        $this->assertEmpty($rows);
    }

    // ──────────────────────────────────────────
    // CSV adapter
    // ──────────────────────────────────────────

    public function test_csv_adapter_parses_real_header_schema(): void
    {
        $csv = "SKU,Product name/产品名称,Warehouse/仓库,Stock properties/库存属性,Stock count date/库存统计日期,Putaway date/上架日期,Qty/数量,Stock age/库龄（天）,Dimension/尺寸,Total volume总体积\n"
             . "SF030C,Test,德国仓,正品,2026-08-01,2026-06-01,26,,10x5,2L\n"
             . "CA594B,Test2,德国仓,次品,2026-08-01,2026-05-01,34,,,,\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $csv);
        $rows = (new CsvAdapter())->parse($file);

        $this->assertCount(2, $rows);
        $this->assertSame('SF030C', $rows[0]->sku);
        $this->assertSame('sellable', $rows[0]->stockStatus);
        $this->assertSame(26, $rows[0]->quantity);
        $this->assertSame('CA594B', $rows[1]->sku);
        $this->assertSame('defective', $rows[1]->stockStatus);
        $this->assertSame(34, $rows[1]->quantity);
    }

    // ──────────────────────────────────────────
    // Row normalizer
    // ──────────────────────────────────────────

    public function test_stock_status_normalizer_maps_chinese_and_english(): void
    {
        $this->assertSame('sellable', RowNormalizer::normalizeStockStatus('正品'));
        $this->assertSame('defective', RowNormalizer::normalizeStockStatus('次品'));
        $this->assertSame('sellable', RowNormalizer::normalizeStockStatus('sellable'));
        $this->assertSame('defective', RowNormalizer::normalizeStockStatus('defective'));
        $this->assertNull(RowNormalizer::normalizeStockStatus('unknown'));
        $this->assertNull(RowNormalizer::normalizeStockStatus(''));
        $this->assertNull(RowNormalizer::normalizeStockStatus(null));
    }

    public function test_date_normalizer_handles_common_formats(): void
    {
        $this->assertSame('2026-08-01', RowNormalizer::normalizeDate('2026-08-01'));
        $this->assertSame('2026-08-01', RowNormalizer::normalizeDate('2026/08/01'));
        $this->assertNull(RowNormalizer::normalizeDate(''));
        $this->assertNull(RowNormalizer::normalizeDate(null));
    }

    // ──────────────────────────────────────────
    // Product Master SKU matching
    // ──────────────────────────────────────────

    public function test_preview_marks_unmatched_sku_as_error_and_blocks_confirm(): void
    {
        $warehouse = Warehouse::where('slug', 'de')->firstOrFail();
        $service   = app(InventoryBatchSyncService::class);
        $user      = $this->userWith(['inventory.operate']);

        $rows = [
            new NormalizedRow(1, 'NONEXISTENT-SKU', 'sellable', null, '2026-07-01', 10),
        ];

        $batch = $service->preview($warehouse, $rows, 'paste', 'partial', null, null, $user);

        $this->assertSame('preview', $batch->status);
        $this->assertSame(1, $batch->preview_error_rows);
        $this->assertSame(1, $batch->preview_unmatched_skus);
        $this->assertDatabaseHas('inventory_sync_rows', ['sync_batch_id' => $batch->getKey(), 'validation_status' => 'error']);
    }

    public function test_preview_does_not_auto_create_products(): void
    {
        $warehouse = Warehouse::where('slug', 'de')->firstOrFail();
        $service   = app(InventoryBatchSyncService::class);
        $user      = $this->userWith(['inventory.operate']);

        $rows = [new NormalizedRow(1, 'AUTO-CREATE-TEST', 'sellable', null, null, 5)];
        $service->preview($warehouse, $rows, 'paste', 'partial', null, null, $user);

        $this->assertDatabaseMissing('products', ['sku' => 'AUTO-CREATE-TEST']);
    }

    // ──────────────────────────────────────────
    // Multi-lot aggregation
    // ──────────────────────────────────────────

    public function test_multi_lot_same_sku_aggregates_by_putaway_date(): void
    {
        [$warehouse, $product] = $this->seedWarehouseAndProduct('SKU-MULTILOT');
        $service = app(InventoryBatchSyncService::class);
        $user    = $this->userWith(['inventory.operate']);

        // Same SKU, same status, different putaway dates → 2 lots
        $rows = [
            new NormalizedRow(1, 'SKU-MULTILOT', 'sellable', null, '2026-06-01', 10),
            new NormalizedRow(2, 'SKU-MULTILOT', 'sellable', null, '2026-07-01', 20),
        ];

        $batch = $service->preview($warehouse, $rows, 'paste', 'partial', null, null, $user);
        $service->confirm($batch, $user);

        $lots = WarehouseInventoryLot::where('warehouse_id', $warehouse->getKey())
            ->where('product_id', $product->getKey())->orderBy('putaway_date')->get();

        $this->assertCount(2, $lots);
        $this->assertSame(10, $lots[0]->quantity);
        $this->assertSame(20, $lots[1]->quantity);

        // canonical QoH = sum of sellable lots = 30
        $inv = WarehouseInventory::where('warehouse_id', $warehouse->getKey())
            ->where('product_id', $product->getKey())->first();
        $this->assertSame(30, $inv->quantity_on_hand);
        // inbound_at = earliest sellable putaway
        $this->assertSame('2026-06-01', $inv->inbound_at->toDateString());
    }

    public function test_multi_lot_same_sku_same_putaway_date_sums_quantity(): void
    {
        [$warehouse, $product] = $this->seedWarehouseAndProduct('SKU-SAMEDAY');
        $service = app(InventoryBatchSyncService::class);
        $user    = $this->userWith(['inventory.operate']);

        $rows = [
            new NormalizedRow(1, 'SKU-SAMEDAY', 'sellable', null, '2026-07-01', 256),
            new NormalizedRow(2, 'SKU-SAMEDAY', 'sellable', null, '2026-07-01', 49),
        ];

        $batch = $service->preview($warehouse, $rows, 'paste', 'partial', null, null, $user);
        $service->confirm($batch, $user);

        $lots = WarehouseInventoryLot::where('warehouse_id', $warehouse->getKey())
            ->where('product_id', $product->getKey())->get();

        $this->assertCount(1, $lots);
        $this->assertSame(305, $lots[0]->quantity);

        $inv = WarehouseInventory::where('warehouse_id', $warehouse->getKey())
            ->where('product_id', $product->getKey())->first();
        $this->assertSame(305, $inv->quantity_on_hand);
    }

    // ──────────────────────────────────────────
    // Defective stock
    // ──────────────────────────────────────────

    public function test_defective_stock_stored_separately_and_excluded_from_qoh(): void
    {
        [$warehouse, $product] = $this->seedWarehouseAndProduct('SKU-DEF');
        $service = app(InventoryBatchSyncService::class);
        $user    = $this->userWith(['inventory.operate']);

        $rows = [
            new NormalizedRow(1, 'SKU-DEF', 'sellable',  null, '2026-07-01', 100),
            new NormalizedRow(2, 'SKU-DEF', 'defective', null, '2026-07-01', 1),
        ];

        $batch = $service->preview($warehouse, $rows, 'paste', 'partial', null, null, $user);
        $service->confirm($batch, $user);

        $lots = WarehouseInventoryLot::where('warehouse_id', $warehouse->getKey())
            ->where('product_id', $product->getKey())->get();

        $this->assertCount(2, $lots);

        $inv = WarehouseInventory::where('warehouse_id', $warehouse->getKey())
            ->where('product_id', $product->getKey())->first();
        // QoH = sellable only = 100
        $this->assertSame(100, $inv->quantity_on_hand);
    }

    // ──────────────────────────────────────────
    // Reserved preservation
    // ──────────────────────────────────────────

    public function test_confirm_preserves_existing_quantity_reserved(): void
    {
        [$warehouse, $product] = $this->seedWarehouseAndProduct('SKU-RESERVED');
        // Pre-set a reserved qty
        WarehouseInventory::updateOrCreate(
            ['warehouse_id' => $warehouse->getKey(), 'product_id' => $product->getKey()],
            ['quantity_on_hand' => 50, 'quantity_reserved' => 15],
        );

        $service = app(InventoryBatchSyncService::class);
        $user    = $this->userWith(['inventory.operate']);

        $rows = [new NormalizedRow(1, 'SKU-RESERVED', 'sellable', null, '2026-07-01', 80)];
        $batch = $service->preview($warehouse, $rows, 'paste', 'partial', null, null, $user);
        $service->confirm($batch, $user);

        $inv = WarehouseInventory::where('warehouse_id', $warehouse->getKey())
            ->where('product_id', $product->getKey())->first();
        $this->assertSame(80, $inv->quantity_on_hand);
        $this->assertSame(15, $inv->quantity_reserved); // preserved
    }

    // ──────────────────────────────────────────
    // Partial vs full snapshot semantics
    // ──────────────────────────────────────────

    public function test_partial_sync_leaves_untouched_skus_unchanged(): void
    {
        [$warehouse, $p1] = $this->seedWarehouseAndProduct('SKU-P1');
        [$warehouse, $p2] = $this->seedWarehouseAndProduct('SKU-P2', $warehouse);

        // Seed existing inventory for p2
        WarehouseInventory::updateOrCreate(
            ['warehouse_id' => $warehouse->getKey(), 'product_id' => $p2->getKey()],
            ['quantity_on_hand' => 99, 'quantity_reserved' => 0],
        );

        $service = app(InventoryBatchSyncService::class);
        $user    = $this->userWith(['inventory.operate']);

        // Only sync p1; p2 should remain unchanged
        $rows = [new NormalizedRow(1, 'SKU-P1', 'sellable', null, '2026-07-01', 50)];
        $batch = $service->preview($warehouse, $rows, 'paste', 'partial', null, null, $user);
        $service->confirm($batch, $user);

        $inv2 = WarehouseInventory::where('warehouse_id', $warehouse->getKey())
            ->where('product_id', $p2->getKey())->first();
        $this->assertSame(99, $inv2->quantity_on_hand);
    }

    public function test_full_sync_zeros_skus_absent_from_source(): void
    {
        [$warehouse, $p1] = $this->seedWarehouseAndProduct('SKU-FULL1');
        [$warehouse, $p2] = $this->seedWarehouseAndProduct('SKU-FULL2', $warehouse);

        // Seed lots for both
        WarehouseInventoryLot::create(['warehouse_id' => $warehouse->getKey(), 'product_id' => $p2->getKey(), 'stock_status' => 'sellable', 'putaway_date' => '2026-06-01', 'quantity' => 99]);
        WarehouseInventory::updateOrCreate(
            ['warehouse_id' => $warehouse->getKey(), 'product_id' => $p2->getKey()],
            ['quantity_on_hand' => 99, 'quantity_reserved' => 0],
        );

        $service = app(InventoryBatchSyncService::class);
        $user    = $this->userWith(['inventory.operate']);

        // Full sync: only p1 in source; p2 should be zeroed
        $rows = [new NormalizedRow(1, 'SKU-FULL1', 'sellable', null, '2026-07-01', 30)];
        $batch = $service->preview($warehouse, $rows, 'paste', 'full', null, null, $user);
        $service->confirm($batch, $user);

        $inv2 = WarehouseInventory::where('warehouse_id', $warehouse->getKey())
            ->where('product_id', $p2->getKey())->first();
        $this->assertSame(0, $inv2->quantity_on_hand);
    }

    // ──────────────────────────────────────────
    // Blocking parser/preview edge cases
    // ──────────────────────────────────────────

    public function test_unknown_nonblank_stock_status_blocks_preview(): void
    {
        [$warehouse] = $this->seedWarehouseAndProduct('SKU-UNKNOWN-STATUS');
        $rows = (new PasteAdapter())->parse("SKU\tStock properties/库存属性\tQty/数量\nSKU-UNKNOWN-STATUS\t未知\t5");
        $this->assertNull($rows[0]->stockStatus);

        $batch = app(InventoryBatchSyncService::class)->preview($warehouse, $rows, 'paste', 'partial', null, null, $this->userWith(['inventory.operate']));
        $this->assertSame(1, $batch->preview_error_rows);
        $this->assertStringContainsString('库存属性未知', (string) $batch->rows->first()->validation_message);
    }

    public function test_negative_quantity_is_not_sanitized_and_blocks_preview(): void
    {
        [$warehouse] = $this->seedWarehouseAndProduct('SKU-NEGATIVE');
        $rows = (new PasteAdapter())->parse("SKU\tQty/数量\nSKU-NEGATIVE\t-5");
        $this->assertSame(-5, $rows[0]->quantity);

        $batch = app(InventoryBatchSyncService::class)->preview($warehouse, $rows, 'paste', 'partial', null, null, $this->userWith(['inventory.operate']));
        $this->assertSame(1, $batch->preview_error_rows);
        $this->assertStringContainsString('数量不能为负', (string) $batch->rows->first()->validation_message);
    }

    public function test_full_preview_explicitly_lists_absent_sku_that_will_be_zeroed(): void
    {
        [$warehouse] = $this->seedWarehouseAndProduct('SKU-FULL-PREVIEW-SOURCE');
        [, $missing] = $this->seedWarehouseAndProduct('SKU-FULL-PREVIEW-MISSING', $warehouse);
        WarehouseInventory::updateOrCreate(
            ['warehouse_id' => $warehouse->getKey(), 'product_id' => $missing->getKey()],
            ['quantity_on_hand' => 99, 'quantity_reserved' => 4],
        );

        $response = $this->actingAs($this->userWith(['inventory.operate']))->postJson('/inventory/sync/preview', [
            'warehouse_id' => $warehouse->getKey(),
            'source_type' => 'paste',
            'sync_mode' => 'full',
            'paste_text' => "SKU\tQty/数量\nSKU-FULL-PREVIEW-SOURCE\t30",
        ])->assertOk()->assertJsonFragment(['preview_zeroed_skus' => 1]);

        $zero = collect($response->json('full_snapshot_zero_rows'))->firstWhere('sku', 'SKU-FULL-PREVIEW-MISSING');
        $this->assertNotNull($zero);
        $this->assertSame('zero', $zero['action']);
        $this->assertSame(99, $zero['current_sellable_qty']);
        $this->assertSame(4, $zero['quantity_reserved']);
        $this->assertSame(0, $zero['quantity_available_after']);
    }

    // ──────────────────────────────────────────
    // Double-confirm prevention
    // ──────────────────────────────────────────

    public function test_double_confirm_is_rejected_with_422(): void
    {
        [$warehouse, $product] = $this->seedWarehouseAndProduct('SKU-DOUBLE');
        $service = app(InventoryBatchSyncService::class);
        $user    = $this->userWith(['inventory.operate']);

        $rows  = [new NormalizedRow(1, 'SKU-DOUBLE', 'sellable', null, '2026-07-01', 5)];
        $batch = $service->preview($warehouse, $rows, 'paste', 'partial', null, null, $user);
        $service->confirm($batch, $user);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->confirm($batch->fresh(), $user);
    }

    // ──────────────────────────────────────────
    // Audit evidence
    // ──────────────────────────────────────────

    public function test_confirm_records_audit_entry(): void
    {
        [$warehouse, $product] = $this->seedWarehouseAndProduct('SKU-AUDIT');
        $service = app(InventoryBatchSyncService::class);
        $user    = $this->userWith(['inventory.operate']);

        $rows  = [new NormalizedRow(1, 'SKU-AUDIT', 'sellable', null, '2026-07-01', 10)];
        $batch = $service->preview($warehouse, $rows, 'paste', 'partial', null, null, $user);
        $service->confirm($batch, $user);

        $this->assertDatabaseHas('audit_records', ['action' => 'inventory.batch_sync_confirmed']);
    }

    // ──────────────────────────────────────────
    // HTTP routes
    // ──────────────────────────────────────────

    public function test_preview_http_endpoint_returns_batch_json(): void
    {
        [$warehouse, $product] = $this->seedWarehouseAndProduct('SKU-HTTP');
        $user = $this->userWith(['inventory.operate']);

        $pasteText = "SKU\tQty/数量\n" . "SKU-HTTP\t42";

        $this->actingAs($user)->postJson('/inventory/sync/preview', [
            'warehouse_id' => $warehouse->getKey(),
            'source_type'  => 'paste',
            'sync_mode'    => 'partial',
            'paste_text'   => $pasteText,
        ])->assertOk()
          ->assertJsonFragment(['preview_total_rows' => 1, 'preview_sellable_qty' => 42]);
    }

    public function test_confirm_http_endpoint_transitions_batch_to_confirmed(): void
    {
        [$warehouse, $product] = $this->seedWarehouseAndProduct('SKU-CONFIRM');
        $user = $this->userWith(['inventory.operate']);

        $pasteText = "SKU\tQty/数量\n" . "SKU-CONFIRM\t7";

        $preview = $this->actingAs($user)->postJson('/inventory/sync/preview', [
            'warehouse_id' => $warehouse->getKey(),
            'source_type'  => 'paste',
            'sync_mode'    => 'partial',
            'paste_text'   => $pasteText,
        ])->assertOk()->json();

        $this->actingAs($user)->postJson("/inventory/sync/{$preview['id']}/confirm")
            ->assertOk()
            ->assertJsonFragment(['status' => 'confirmed']);

        $this->assertDatabaseHas('inventory_sync_batches', ['id' => $preview['id'], 'status' => 'confirmed']);
    }

    public function test_double_confirm_http_returns_422(): void
    {
        [$warehouse, $product] = $this->seedWarehouseAndProduct('SKU-DC-HTTP');
        $user = $this->userWith(['inventory.operate']);

        $pasteText = "SKU\tQty/数量\n" . "SKU-DC-HTTP\t3";

        $preview = $this->actingAs($user)->postJson('/inventory/sync/preview', [
            'warehouse_id' => $warehouse->getKey(),
            'source_type'  => 'paste',
            'sync_mode'    => 'partial',
            'paste_text'   => $pasteText,
        ])->assertOk()->json();

        $this->actingAs($user)->postJson("/inventory/sync/{$preview['id']}/confirm")->assertOk();
        $this->actingAs($user)->postJson("/inventory/sync/{$preview['id']}/confirm")->assertStatus(422);
    }

    public function test_csv_preview_endpoint_accepts_file_upload(): void
    {
        [$warehouse, $product] = $this->seedWarehouseAndProduct('SKU-CSV');
        $user = $this->userWith(['inventory.operate']);

        $csv = "SKU,Qty/数量\nSKU-CSV,55\n";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

        $this->actingAs($user)->post('/inventory/sync/preview', [
            'warehouse_id' => $warehouse->getKey(),
            'source_type'  => 'csv',
            'sync_mode'    => 'partial',
            'file'         => $file,
        ])->assertOk()
          ->assertJsonFragment(['preview_sellable_qty' => 55]);
    }

    public function test_sync_history_endpoint_returns_paginated_batches(): void
    {
        $user = $this->userWith(['inventory.operate']);

        // Confirm the user actually has the permission before the HTTP assertion
        $this->assertTrue($user->hasPermission('inventory.operate'), 'User should have inventory.operate');

        $response = $this->actingAs($user)->getJson('/inventory/sync/history');
        $this->assertSame(200, $response->getStatusCode(), 'History endpoint body: '.$response->getContent());
        $response->assertJsonStructure(['data', 'total']);
    }

    // ──────────────────────────────────────────
    // Menu check
    // ──────────────────────────────────────────

    public function test_menu_includes_inventory_sync_entry(): void
    {
        $nav = config('erp_shell_navigation');
        $inventory = collect($nav)->firstWhere('key', 'inventory');
        $this->assertNotNull($inventory);
        $sync = collect($inventory['children'] ?? [])->firstWhere('key', 'inventory-sync');
        $this->assertNotNull($sync, 'inventory-sync missing from runtime navigation config');
        $this->assertSame('/inventory/sync', $sync['path']);
        $this->assertSame('inventory.operate', $sync['permission']);
    }

    // ──────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────

    private function seedWarehouseAndProduct(string $sku, ?Warehouse $existingWarehouse = null): array
    {
        $warehouse = $existingWarehouse ?? Warehouse::where('slug', 'de')->firstOrFail();
        $product = Product::firstOrCreate(['sku' => $sku], [
            'name' => $sku . ' Test', 'source_type' => 'manual', 'product_type' => 'simple',
        ]);
        return [$warehouse, $product];
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'sync-test-' . uniqid(), 'label' => 'Sync Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);
        return $user;
    }
}
