<?php

namespace Tests\Feature;

use App\Modules\Finance\Application\CostMaterializationService;
use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLotConsumption;
use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseLotConsumptionService;
use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseStorageAgingAccrualService;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class InventoryWarehouseStorageAgingP9OperationalTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_runner_is_disabled_by_default_and_only_adds_new_due_months_when_enabled(): void
    {
        $warehouse = $this->warehouse('us-la', 'US-LA', 'US');
        $product = $this->product('P9-MONTHLY');
        $batch = $this->batch($warehouse, '2026-05-02 00:00:00');

        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-01-01',
            'quantity' => 5,
            'sync_batch_id' => $batch->id,
        ]);

        $aging = $this->app->make(WarehouseStorageAgingAccrualService::class);
        $aging->apply('2026-05-02', 'us-la', true);
        self::assertDatabaseCount('warehouse_storage_aging_accruals', 2);

        config(['warehouse_storage_aging.monthly_enabled' => false]);
        $this->artisan('inventory:run-storage-aging-monthly', ['--as-of' => '2026-06-01'])->assertSuccessful();
        self::assertDatabaseCount('warehouse_storage_aging_accruals', 2);

        config(['warehouse_storage_aging.monthly_enabled' => true]);
        $this->artisan('inventory:run-storage-aging-monthly', ['--as-of' => '2026-06-01'])->assertSuccessful();
        self::assertDatabaseCount('warehouse_storage_aging_accruals', 3);
        $this->assertDatabaseHas('warehouse_storage_aging_accruals', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'aging_month_index' => 3,
            'quantity_snapshot' => 5,
            'amount_cny' => '5.00',
        ]);

        $this->artisan('inventory:run-storage-aging-monthly', ['--as-of' => '2026-06-01'])->assertSuccessful();
        self::assertDatabaseCount('warehouse_storage_aging_accruals', 3);
    }

    public function test_fifo_consumption_allocates_oldest_sellable_lot_and_freezes_storage_cost_per_unit(): void
    {
        $warehouse = $this->warehouse('us-la', 'US-LA', 'US');
        $product = $this->product('P9-FIFO');
        $batch = $this->batch($warehouse, '2026-06-01 00:00:00');

        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-01-01',
            'quantity' => 1,
            'sync_batch_id' => $batch->id,
        ]);
        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-02-01',
            'quantity' => 3,
            'sync_batch_id' => $batch->id,
        ]);

        $aging = $this->app->make(WarehouseStorageAgingAccrualService::class);
        $aging->apply('2026-06-01', 'us-la', true);

        $order = $this->order($product, 2, 'USD');
        $shipment = $order->shipment()->create([
            'warehouse_id' => $warehouse->id,
            'shipment_status' => 'shipped',
            'carrier' => 'USPS',
            'shipped_at' => '2026-06-01 08:00:00',
        ]);

        $captured = $this->app->make(WarehouseLotConsumptionService::class)->captureForShipment($shipment);
        self::assertSame('captured', $captured['status']);
        self::assertSame(2, $captured['total_quantity']);
        self::assertDatabaseCount('warehouse_inventory_lot_consumptions', 2);

        $rows = WarehouseInventoryLotConsumption::query()->orderBy('putaway_date')->get();
        self::assertSame(['2026-01-01', '2026-02-01'], $rows->pluck('putaway_date')->map->toDateString()->all());
        self::assertSame([1, 1], $rows->pluck('quantity')->all());
        self::assertSame([3, 1], $rows->pluck('aging_months_charged')->all());
        self::assertSame(['3.00', '1.00'], $rows->pluck('unit_storage_cost_cny')->all());
        self::assertSame(['3.00', '1.00'], $rows->pluck('allocated_storage_cost_cny')->all());

        $replay = $this->app->make(WarehouseLotConsumptionService::class)->captureForShipment($shipment->fresh());
        self::assertSame('noop', $replay['status']);
        self::assertDatabaseCount('warehouse_inventory_lot_consumptions', 2);
    }

    public function test_fifo_consumption_accepts_direct_inbound_lots_without_sync_batch_identity(): void
    {
        $warehouse = $this->warehouse('us-sm', 'US-SM', 'US');
        $product = $this->product('P9-INBOUND-DIRECT');

        $firstLot = WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-09-19',
            'quantity' => 1,
            'sync_batch_id' => null,
        ]);
        $secondLot = WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-09-19',
            'quantity' => 1,
            'sync_batch_id' => null,
        ]);

        $order = $this->order($product, 2, 'USD');
        $shipment = $order->shipment()->create([
            'warehouse_id' => $warehouse->id,
            'shipment_status' => 'shipped',
            'carrier' => 'USPS',
            'shipped_at' => '2026-09-19 08:00:00',
        ]);

        $captured = $this->app->make(WarehouseLotConsumptionService::class)->captureForShipment($shipment);

        self::assertSame('captured', $captured['status']);
        self::assertSame(2, $captured['total_quantity']);
        self::assertDatabaseCount('warehouse_inventory_lot_consumptions', 2);

        $rows = WarehouseInventoryLotConsumption::query()->orderBy('source_inventory_lot_id')->get();
        self::assertSame([$firstLot->id, $secondLot->id], $rows->pluck('source_inventory_lot_id')->all());
        self::assertSame([null, null], $rows->pluck('source_sync_batch_id')->all());
        self::assertSame([1, 1], $rows->pluck('quantity')->all());
        self::assertNotSame($rows[0]->lot_business_key, $rows[1]->lot_business_key);
        self::assertStringContainsString(':source-lot:', $rows[0]->source_reference);
        self::assertStringContainsString(':source-lot:', $rows[1]->source_reference);
    }

    public function test_fifo_preview_does_not_double_allocate_same_lot_across_duplicate_product_lines(): void
    {
        $warehouse = $this->warehouse('us-la', 'US-LA', 'US');
        $product = $this->product('P9-DUP-LINES');
        $batch = $this->batch($warehouse, '2026-09-18 00:00:00');
        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-09-01',
            'quantity' => 1,
            'sync_batch_id' => $batch->id,
        ]);

        $order = $this->order($product, 1, 'USD');
        $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'resolved_fulfillment_sku' => $product->sku,
            'product_summary' => $product->name,
            'quantity' => 1,
            'unit_price' => '10.00',
            'line_revenue' => '10.00',
        ]);
        $shipment = $order->shipment()->create([
            'warehouse_id' => $warehouse->id,
            'shipment_status' => 'shipped',
            'carrier' => 'USPS',
            'shipped_at' => '2026-09-18 08:00:00',
        ]);

        $preview = $this->app->make(WarehouseLotConsumptionService::class)->previewForShipment($shipment);

        self::assertSame('blocked', $preview['status']);
        self::assertSame(1, $preview['total_quantity']);
        self::assertSame('fifo_sellable_quantity_insufficient', $preview['blocked'][0]['reason']);
    }

    public function test_mark_shipped_captures_fifo_consumption_and_keeps_first_90_days_free(): void
    {
        config(['warehouse_storage_aging.fifo_consumption_enabled' => true]);

        $warehouse = $this->warehouse('us-la', 'US-LA', 'US');
        $product = $this->product('P9-SHIP-HOOK');
        $batch = $this->batch($warehouse, '2026-09-18 00:00:00');
        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-09-01',
            'quantity' => 2,
            'sync_batch_id' => $batch->id,
        ]);

        $order = $this->order($product, 1, 'USD');
        $order->shipment()->create([
            'warehouse_id' => $warehouse->id,
            'shipment_status' => 'pending',
        ]);

        $this->app->make(ShipmentService::class)->markShipped($order, [
            'warehouse_id' => $warehouse->id,
            'carrier' => 'USPS',
            'service' => 'Ground',
            'shipped_at' => '2026-09-18 08:00:00',
        ], null);

        self::assertSame('shipped', $order->fresh()->order_status->value);
        $row = WarehouseInventoryLotConsumption::query()->sole();
        self::assertSame(1, $row->quantity);
        self::assertSame(0, $row->aging_months_charged);
        self::assertSame('0.00', $row->allocated_storage_cost_cny);
        $this->assertDatabaseHas('audit_records', ['action' => 'inventory.warehouse_lot_consumption.captured']);

        try {
            $this->app->make(ShipmentService::class)->reopen($order->fresh(), null, 'correction');
            self::fail('Expected immutable FIFO reset guard.');
        } catch (RuntimeException $e) {
            self::assertSame('warehouse_fifo_consumption_already_captured_manual_reconciliation_required', $e->getMessage());
        }
        self::assertSame('shipped', $order->fresh()->order_status->value);
        self::assertDatabaseCount('warehouse_inventory_lot_consumptions', 1);
    }

    public function test_mark_shipped_rolls_back_when_fifo_sellable_inventory_is_insufficient(): void
    {
        config(['warehouse_storage_aging.fifo_consumption_enabled' => true]);

        $warehouse = $this->warehouse('us-ny', 'US-NY', 'US');
        $product = $this->product('P9-ROLLBACK');
        $batch = $this->batch($warehouse, '2026-09-18 00:00:00');

        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-09-01',
            'quantity' => 1,
            'sync_batch_id' => $batch->id,
        ]);

        $order = $this->order($product, 2, 'USD');
        $order->shipment()->create([
            'warehouse_id' => $warehouse->id,
            'shipment_status' => 'pending',
        ]);

        try {
            $this->app->make(ShipmentService::class)->markShipped($order, [
                'warehouse_id' => $warehouse->id,
                'carrier' => 'USPS',
                'service' => 'Ground',
                'shipped_at' => '2026-09-18 08:00:00',
            ], null);
            self::fail('Expected FIFO blocker.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('warehouse_fifo_consumption_blocked', $e->getMessage());
        }

        self::assertSame('pending_shipment', $order->fresh()->order_status->value);
        self::assertSame('pending', $order->shipment()->firstOrFail()->shipment_status);
        self::assertDatabaseCount('warehouse_inventory_lot_consumptions', 0);
    }

    public function test_p9_consumption_materializes_confirmed_warehouse_cost_in_order_currency(): void
    {
        config([
            'warehouse_storage_aging.order_cost_enabled' => true,
            'warehouse_storage_aging.canonical_from' => '2026-01-01',
        ]);
        $this->app->make(FxReferenceSettingsService::class)->save(
            ['USD' => 7.0, 'CNY' => 1.0],
            ['USD' => false, 'CNY' => false],
            null,
        );

        $warehouse = $this->warehouse('us-la', 'US-LA', 'US');
        $product = $this->product('P9-FIN');
        $batch = $this->batch($warehouse, '2026-06-01 00:00:00');
        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-01-01',
            'quantity' => 10,
            'sync_batch_id' => $batch->id,
        ]);
        $this->app->make(WarehouseStorageAgingAccrualService::class)->apply('2026-06-01', 'us-la', true);

        $order = $this->order($product, 2, 'USD');
        $shipment = $order->shipment()->create([
            'warehouse_id' => $warehouse->id,
            'shipment_status' => 'shipped',
            'carrier' => 'USPS',
            'shipped_at' => '2026-06-01 08:00:00',
        ]);
        $this->app->make(WarehouseLotConsumptionService::class)->captureForShipment($shipment);

        $service = $this->app->make(CostMaterializationService::class);
        $candidate = $service->previewWarehouseCostGate($order->fresh())['candidates'][0];

        self::assertSame('apply', $candidate['materialization_status']);
        self::assertSame('CNY', $candidate['source_currency']);
        self::assertSame('6.00', $candidate['source_amount']);
        self::assertSame('p9_fifo_lot_consumption_v1', $candidate['allocation_rule']);
        self::assertStringStartsWith('p9-storage-aging-allocation:v1:order:', $candidate['source_reference']);

        $service->applyWarehouseCostGate($order->fresh());
        $entry = $order->costEntries()->where('cost_type', 'warehouse_cost')->firstOrFail();
        self::assertSame('allocated_actual', $entry->provenance);
        self::assertSame('confirmed', $entry->status);
        self::assertStringStartsWith('p9-storage-aging-allocation:v1:order:', $entry->source_reference);
    }

    public function test_parent_order_profit_aggregates_p9_consumption_from_fulfillment_child(): void
    {
        config([
            'warehouse_storage_aging.order_cost_enabled' => true,
            'warehouse_storage_aging.canonical_from' => '2026-01-01',
        ]);
        $this->app->make(FxReferenceSettingsService::class)->save(
            ['USD' => 7.0, 'CNY' => 1.0],
            ['USD' => false, 'CNY' => false],
            null,
        );

        $warehouse = $this->warehouse('us-la', 'US-LA', 'US');
        $product = $this->product('P9-CHILD');
        $batch = $this->batch($warehouse, '2026-06-01 00:00:00');
        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-01-01',
            'quantity' => 10,
            'sync_batch_id' => $batch->id,
        ]);
        $this->app->make(WarehouseStorageAgingAccrualService::class)->apply('2026-06-01', 'us-la', true);

        $parent = $this->order($product, 1, 'USD');
        $parent->forceFill(['erp_order_id' => 'P9-PARENT', 'order_status' => 'shipped'])->save();
        $child = Order::query()->create([
            'fulfillment_parent_id' => $parent->id,
            'fulfillment_suffix' => 'A',
            'internal_order_number' => 'P9-CHILD-A',
            'erp_order_id' => 'P9-PARENTA',
            'source_type' => 'manual_other',
            'order_kind' => 'fulfillment_derived',
            'order_date' => '2026-06-01',
            'order_status' => 'shipped',
            'currency' => 'USD',
        ]);
        $childItem = $child->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'resolved_fulfillment_sku' => $product->sku,
            'product_summary' => $product->name,
            'quantity' => 1,
            'unit_price' => '10.00',
            'line_revenue' => '10.00',
        ]);
        $shipment = $child->shipment()->create([
            'warehouse_id' => $warehouse->id,
            'shipment_status' => 'shipped',
            'carrier' => 'USPS',
            'shipped_at' => '2026-06-01 08:00:00',
        ]);
        $this->app->make(WarehouseLotConsumptionService::class)->captureForShipment($shipment);

        $candidate = $this->app->make(CostMaterializationService::class)
            ->previewWarehouseCostGate($parent->fresh())['candidates'][0];

        self::assertSame('apply', $candidate['materialization_status']);
        self::assertSame('3.00', $candidate['source_amount']);
        self::assertContains($shipment->id, $candidate['shipment_ids']);
        self::assertCount(1, $candidate['consumption_ids']);
        self::assertSame($childItem->id, WarehouseInventoryLotConsumption::query()->sole()->order_item_id);
    }

    private function warehouse(string $slug, string $code, string $country): Warehouse
    {
        return Warehouse::query()->updateOrCreate(
            ['code' => $code],
            [
                'slug' => $slug,
                'name' => $code,
                'country_code' => $country,
                'type' => 'warehouse',
                'is_active' => true,
                'priority' => 10,
            ],
        );
    }

    private function product(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $sku,
            'source_type' => 'manual',
            'product_type' => 'simple',
            'is_active' => true,
        ]);
    }

    private function batch(Warehouse $warehouse, string $at): InventorySyncBatch
    {
        return InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id,
            'source_type' => 'api',
            'sync_mode' => 'full',
            'checksum' => hash('sha256', $warehouse->id.'|'.$at.'|'.uniqid('', true)),
            'stock_count_at' => $at,
            'status' => 'confirmed',
            'confirmed_at' => $at,
        ]);
    }

    private function order(Product $product, int $quantity, string $currency): Order
    {
        $order = Order::query()->create([
            'internal_order_number' => 'P9-'.strtoupper(uniqid()),
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'order_date' => '2026-06-01',
            'order_status' => 'pending_shipment',
            'currency' => $currency,
        ]);
        $order->financials()->create([
            'currency' => $currency,
            'completeness_status' => 'INCOMPLETE',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'resolved_fulfillment_sku' => $product->sku,
            'product_summary' => $product->name,
            'quantity' => $quantity,
            'unit_price' => '10.00',
            'line_revenue' => number_format($quantity * 10, 2, '.', ''),
        ]);

        return $order->fresh(['items', 'financials']);
    }
}
