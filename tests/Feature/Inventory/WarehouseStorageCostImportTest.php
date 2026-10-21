<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseStorageCostImportService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WarehouseStorageCostImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_tianyun_like_storage_pool_allocates_29_76_over_48_units_and_excludes_kol_share(): void
    {
        $warehouse = $this->warehouse('de');
        $rows = [];
        for ($i = 1; $i <= 27; $i++) {
            $units = $i === 27 ? 6 : 1; // 26 + 6 = 32 customer units.
            $tracking = 'TY-C-'.$i;
            $this->customerShipment($warehouse, $tracking, $units);
            $rows[] = ['C'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), $tracking, $units];
        }
        for ($i = 1; $i <= 16; $i++) {
            $rows[] = ['KOL-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'TY-K-'.$i, 1];
        }

        $file = $this->csv($rows, 'de', '29.7600', 'EUR');
        $service = app(WarehouseStorageCostImportService::class);
        $preview = $service->preview($file, 'tianyun');

        $this->assertSame('0.62000000', $preview['allocation_rate_per_unit']);
        $this->assertSame(43, $preview['total_rows']);
        $this->assertSame(27, $preview['ready_count']);
        $this->assertSame(16, $preview['excluded_count']);
        $this->assertSame(0, $preview['review_count']);
        $this->assertSame(48, $preview['total_outbound_units']);
        $this->assertSame(32, $preview['ready_customer_units']);
        $this->assertSame(16, $preview['excluded_units']);
        $this->assertSame('19.8400', $preview['ready_customer_source_amount']);
        $this->assertSame('9.9200', $preview['excluded_source_amount']);
        $this->assertSame('0.0000', $preview['review_source_amount']);
        $this->assertDatabaseCount('warehouse_storage_cost_import_batches', 0);
        $this->assertDatabaseCount('warehouse_storage_cost_import_rows', 0);

        $applied = $service->apply($file, 'tianyun');
        $this->assertFalse($applied['already_applied']);
        $this->assertDatabaseCount('warehouse_storage_cost_import_batches', 1);
        $this->assertDatabaseCount('warehouse_storage_cost_import_rows', 43);
        $this->assertDatabaseCount('order_cost_entries', 0);

        $again = $service->apply($file, 'tianyun');
        $this->assertTrue($again['already_applied']);
        $this->assertDatabaseCount('warehouse_storage_cost_import_batches', 1);
        $this->assertDatabaseCount('warehouse_storage_cost_import_rows', 43);
    }

    public function test_unmatched_kol_is_excluded_but_unmatched_customer_and_warehouse_mismatch_are_review(): void
    {
        $de = $this->warehouse('de');
        $us = $this->warehouse('us-la');
        $this->customerShipment($us, 'WRONG-WH', 1);
        $file = $this->csv([
            ['KOL-001', 'KOL-NO-SHIP', 1],
            ['C99999', 'CUSTOMER-NO-SHIP', 2],
            ['C88888', 'WRONG-WH', 3],
        ], 'de', '6.0000', 'EUR');

        $preview = app(WarehouseStorageCostImportService::class)->preview($file, 'tianyun');
        $this->assertSame(0, $preview['ready_count']);
        $this->assertSame(1, $preview['excluded_count']);
        $this->assertSame(2, $preview['review_count']);
        $this->assertSame(6, $preview['total_outbound_units']);
        $reasons = collect($preview['rows'])->pluck('reason_code')->sort()->values()->all();
        $this->assertSame(['shipment_not_found', 'source_marked_kol', 'warehouse_mismatch'], $reasons);
    }

    public function test_non_customer_exact_shipment_is_excluded_and_never_becomes_customer_allocation(): void
    {
        $warehouse = $this->warehouse('de');
        $order = Order::query()->create([
            'internal_order_number' => 'KOL-ORDER-'.strtoupper(uniqid()), 'source_type' => 'kol', 'order_kind' => 'kol_fulfillment',
            'order_date' => '2026-01-10', 'order_status' => 'shipped', 'currency' => 'EUR',
        ]);
        $order->shipment()->create(['warehouse_id' => $warehouse->id, 'shipment_status' => 'shipped', 'tracking_number' => 'EXACT-KOL']);
        $file = $this->csv([['KOL-EXACT', 'EXACT-KOL', 4]], 'de', '2.4800', 'EUR');

        $preview = app(WarehouseStorageCostImportService::class)->preview($file, 'tianyun');
        $this->assertSame(1, $preview['excluded_count']);
        $this->assertSame(0, $preview['ready_count']);
        $this->assertSame('non_customer_order', $preview['rows'][0]['reason_code']);
    }

    public function test_invalid_duplicate_or_inconsistent_source_fails_closed_without_writes(): void
    {
        $warehouse = $this->warehouse('de');
        $this->customerShipment($warehouse, 'DUP-TRACK', 1);
        $file = $this->csv([
            ['C00001', 'DUP-TRACK', 1],
            ['C00001', 'DUP-TRACK', 1],
        ], 'de', '1.2400', 'EUR');

        $this->expectException(\InvalidArgumentException::class);
        app(WarehouseStorageCostImportService::class)->preview($file, 'tianyun');
    }

    public function test_command_is_dry_run_first_and_production_apply_requires_flag_and_exact_guards(): void
    {
        $warehouse = $this->warehouse('de');
        $this->customerShipment($warehouse, 'CMD-TY', 2);
        $file = $this->csv([['C12345', 'CMD-TY', 2]], 'de', '1.2400', 'EUR');
        $plan = app(WarehouseStorageCostImportService::class)->preview($file, 'tianyun');

        $this->artisan('inventory:import-warehouse-storage-cost', ['file'=>$file, '--provider'=>'tianyun'])->assertSuccessful();
        $this->assertDatabaseCount('warehouse_storage_cost_import_batches', 0);

        $old = $this->app['env']; $this->app['env'] = 'production'; putenv('ERP_WAREHOUSE_STORAGE_COST_IMPORT_PRODUCTION_APPLY_ENABLED');
        $args = ['file'=>$file, '--provider'=>'tianyun', '--apply'=>true,
            '--expect-source-sha'=>$plan['source_checksum'], '--expect-plan-sha'=>$plan['plan_checksum'],
            '--expect-ready'=>'1', '--expect-excluded'=>'0', '--expect-review'=>'0', '--expect-total-units'=>'2'];
        try {
            $this->artisan('inventory:import-warehouse-storage-cost', $args)->assertFailed();
            putenv('ERP_WAREHOUSE_STORAGE_COST_IMPORT_PRODUCTION_APPLY_ENABLED=true');
            $wrong = $args; $wrong['--expect-total-units'] = '3';
            $this->artisan('inventory:import-warehouse-storage-cost', $wrong)->assertFailed();
            $this->assertDatabaseCount('warehouse_storage_cost_import_batches', 0);
            $this->artisan('inventory:import-warehouse-storage-cost', $args)->assertSuccessful();
            $this->assertDatabaseCount('warehouse_storage_cost_import_batches', 1);
            $this->artisan('inventory:import-warehouse-storage-cost', $args)->assertSuccessful();
            $this->assertDatabaseCount('warehouse_storage_cost_import_batches', 1);
        } finally {
            putenv('ERP_WAREHOUSE_STORAGE_COST_IMPORT_PRODUCTION_APPLY_ENABLED'); $this->app['env'] = $old;
        }
    }

    private function warehouse(string $slug): Warehouse
    {
        return Warehouse::query()->firstOrCreate(['slug'=>$slug], [
            'code'=>strtoupper($slug).'-P7', 'name'=>'P7 '.$slug, 'type'=>'warehouse', 'ownership_type'=>'third_party', 'is_active'=>true, 'priority'=>10,
        ]);
    }

    private function customerShipment(Warehouse $warehouse, string $tracking, int $units): Order
    {
        $order = Order::query()->create([
            'internal_order_number'=>'P7-'.strtoupper(uniqid()), 'source_type'=>'manual', 'order_kind'=>'customer',
            'order_date'=>'2026-01-15', 'order_status'=>'shipped', 'currency'=>'EUR',
        ]);
        $order->items()->create(['product_summary'=>'P7 item','sku'=>'P7-'.$order->id,'quantity'=>$units,'unit_price'=>'1.00','line_revenue'=>(string)$units]);
        $order->shipment()->create(['warehouse_id'=>$warehouse->id,'shipment_status'=>'shipped','tracking_number'=>$tracking]);
        return $order;
    }

    private function csv(array $rows, string $warehouse, string $pool, string $currency): string
    {
        $path = tempnam(sys_get_temp_dir(), 'p7-storage-').'.csv';
        $h = fopen($path, 'wb');
        fputcsv($h, ['warehouse_slug','billing_period_start','billing_period_end','storage_pool_amount','currency','reference_number','tracking_number','outbound_units']);
        foreach ($rows as [$reference,$tracking,$units]) fputcsv($h, [$warehouse,'2026-01-01','2026-01-31',$pool,$currency,$reference,$tracking,$units]);
        fclose($h); return $path;
    }
}
