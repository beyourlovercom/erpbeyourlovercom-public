<?php

namespace Tests\Feature;

use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InventoryDataFreshnessM11Test extends TestCase
{
    use RefreshDatabase;

    public function test_never_imported_is_unknown_not_numeric_zero(): void
    {
        $warehouse = $this->warehouseSummary('us-la');

        $this->assertSame('never_imported', $warehouse['inventory_state']);
        $this->assertSame('库存未导入', $warehouse['inventory_state_label']);
        $this->assertNull($warehouse['product_count']);
        $this->assertNull($warehouse['quantity_on_hand']);
        $this->assertFalse($warehouse['can_auto_allocate']);
    }

    public function test_manual_zero_is_known_but_not_auto_allocatable(): void
    {
        $warehouse = Warehouse::query()->where('slug', 'us-la')->firstOrFail();
        $product = Product::create(['sku'=>'MANUAL-ZERO','name'=>'Manual Zero','source_type'=>'manual','product_type'=>'simple']);
        WarehouseInventory::create(['warehouse_id'=>$warehouse->id,'product_id'=>$product->id,'quantity_on_hand'=>0,'quantity_reserved'=>0]);

        $summary = $this->warehouseSummary('us-la');
        $this->assertSame('manual', $summary['inventory_state']);
        $this->assertSame(0, $summary['quantity_on_hand']);
        $this->assertSame(1, $summary['product_count']);
        $this->assertFalse($summary['can_auto_allocate']);
        $this->assertNotNull($summary['last_inventory_at']);
    }

    public function test_confirmed_full_zero_remains_real_zero_and_is_auto_allocatable(): void
    {
        $warehouse = Warehouse::query()->where('slug', 'us-la')->firstOrFail();
        $this->confirmedBatch($warehouse->id, 'full', '2026-09-01 08:00:00', '2026-09-01 07:00:00');

        $summary = $this->warehouseSummary('us-la');
        $this->assertSame('confirmed', $summary['inventory_state']);
        $this->assertSame(0, $summary['quantity_on_hand']);
        $this->assertSame(0, $summary['product_count']);
        $this->assertTrue($summary['can_auto_allocate']);
    }

    public function test_partial_only_is_known_but_not_auto_allocatable(): void
    {
        $warehouse = Warehouse::query()->where('slug', 'de')->firstOrFail();
        $product = Product::create(['sku'=>'PARTIAL-1','name'=>'Partial','source_type'=>'manual','product_type'=>'simple']);
        WarehouseInventory::create(['warehouse_id'=>$warehouse->id,'product_id'=>$product->id,'quantity_on_hand'=>7,'quantity_reserved'=>0]);
        $this->confirmedBatch($warehouse->id, 'partial', '2026-09-01 09:00:00', '2026-09-01 08:30:00');

        $summary = $this->warehouseSummary('de');
        $this->assertSame('partial_only', $summary['inventory_state']);
        $this->assertSame(7, $summary['quantity_on_hand']);
        $this->assertFalse($summary['can_auto_allocate']);
    }

    public function test_later_partial_does_not_remove_existing_full_baseline(): void
    {
        $warehouse = Warehouse::query()->where('slug', 'de')->firstOrFail();
        $this->confirmedBatch($warehouse->id, 'full', '2026-08-31 09:00:00', '2026-08-31 08:00:00');
        $latest = $this->confirmedBatch($warehouse->id, 'partial', '2026-09-01 10:00:00', '2026-09-01 09:30:00');

        $summary = $this->warehouseSummary('de');
        $this->assertSame('confirmed', $summary['inventory_state']);
        $this->assertSame('partial', $summary['latest_sync_mode']);
        $this->assertSame($latest->id, $summary['latest_batch_id']);
        $this->assertTrue($summary['can_auto_allocate']);
        $this->assertStringStartsWith('2026-09-01', $summary['last_inventory_at']);
    }

    public function test_dashboard_counts_never_imported_warehouses_separately(): void
    {
        $warehouse = Warehouse::query()->where('slug', 'us-ny')->firstOrFail();
        $this->confirmedBatch($warehouse->id, 'full', '2026-09-01 11:00:00', '2026-09-01 10:00:00');

        $dashboard = app(InventoryService::class)->dashboard();
        $this->assertSame(1, $dashboard['warehouse_confirmed_count']);
        $this->assertSame(5, $dashboard['warehouse_data_gap_count']);
    }

    private function warehouseSummary(string $slug): array
    {
        $warehouse = collect(app(InventoryService::class)->warehouses())->firstWhere('slug', $slug);
        $this->assertIsArray($warehouse);
        return $warehouse;
    }

    private function confirmedBatch(int $warehouseId, string $mode, string $confirmedAt, string $stockCountAt): InventorySyncBatch
    {
        return InventorySyncBatch::create([
            'warehouse_id'=>$warehouseId,'source_type'=>'xlsx','sync_mode'=>$mode,
            'status'=>'confirmed','confirmed_at'=>$confirmedAt,'stock_count_at'=>$stockCountAt,
        ]);
    }
}
