<?php

namespace Tests\Feature;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Inventory\Infrastructure\Models\WarehouseStorageAgingAccrual;
use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseStorageAgingAccrualService;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InventoryWarehouseStorageAgingP9Test extends TestCase
{
    use RefreshDatabase;

    public function test_initial_old_inventory_requires_explicit_backfill_gate_and_then_accrues_per_unit(): void
    {
        $this->travelTo('2026-05-02 12:00:00');
        $warehouse = $this->warehouse('us-la', 'US-LA', 'US');
        $product = $this->product('P9-BACKFILL');

        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-01-01',
            'quantity' => 17,
        ]);

        $service = $this->app->make(WarehouseStorageAgingAccrualService::class);

        $normal = $service->preview('2026-05-02');
        self::assertSame(1, $normal['eligible_lots']);
        self::assertSame(17, $normal['eligible_units']);
        self::assertSame(0, $normal['new_accrual_rows']);
        self::assertSame(1, $normal['blocked_total']);
        self::assertSame(1, $normal['blocked']['historical_cycles_require_backfill_gate']);

        $backfill = $service->preview('2026-05-02', null, true);
        self::assertSame(2, $backfill['new_accrual_rows']);
        self::assertSame('34.00', $backfill['new_amount_cny']);
        self::assertSame('34.00', $backfill['cumulative_current_amount_cny']);
        self::assertSame(0, $backfill['blocked_total']);

        $applied = $service->apply('2026-05-02', null, true);
        self::assertSame(2, $applied['applied_rows']);
        self::assertDatabaseCount('warehouse_storage_aging_accruals', 2);

        $rows = WarehouseStorageAgingAccrual::query()->orderBy('aging_month_index')->get();
        self::assertSame([1, 2], $rows->pluck('aging_month_index')->all());
        self::assertSame(['17.00', '17.00'], $rows->pluck('amount_cny')->all());
        self::assertSame([17, 17], $rows->pluck('quantity_snapshot')->all());
        self::assertSame(['2026-04-02', '2026-05-02'], $rows->pluck('cycle_started_on')->map->toDateString()->all());
        self::assertTrue($rows->every(fn ($row) => $row->is_backfill));

        $replay = $service->preview('2026-05-02', null, true);
        self::assertSame(0, $replay['new_accrual_rows']);
        self::assertSame(2, $replay['noop_accrual_rows']);
        self::assertSame('0.00', $replay['new_amount_cny']);
    }

    public function test_domestic_factory_is_never_charged_even_when_inventory_is_old(): void
    {
        $warehouse = $this->warehouse('factory', 'FACTORY', 'CN');
        $product = $this->product('P9-FACTORY');

        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2025-01-01',
            'quantity' => 99,
        ]);

        $preview = $this->app->make(WarehouseStorageAgingAccrualService::class)
            ->preview('2026-09-18', null, true);

        self::assertSame(1, $preview['lots_seen']);
        self::assertSame(0, $preview['overseas_lots_seen']);
        self::assertSame(1, $preview['excluded_non_overseas_lots']);
        self::assertSame(0, $preview['eligible_lots']);
        self::assertSame(0, $preview['new_accrual_rows']);
        self::assertSame('0.00', $preview['new_amount_cny']);
    }

    public function test_missing_putaway_date_fails_closed(): void
    {
        $warehouse = $this->warehouse('us-ny', 'US-NY', 'US');
        $product = $this->product('P9-NODATE');

        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => null,
            'quantity' => 8,
        ]);

        $preview = $this->app->make(WarehouseStorageAgingAccrualService::class)
            ->preview('2026-09-18', null, true);

        self::assertSame(1, $preview['blocked_total']);
        self::assertSame(1, $preview['blocked']['putaway_date_missing']);
        self::assertSame(0, $preview['new_accrual_rows']);
    }

    public function test_lot_row_rebuild_does_not_duplicate_already_accrued_business_months(): void
    {
        $warehouse = $this->warehouse('de', 'DE', 'DE');
        $product = $this->product('P9-STABLE');
        $service = $this->app->make(WarehouseStorageAgingAccrualService::class);

        $lot = WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-01-01',
            'quantity' => 5,
        ]);

        $first = $service->apply('2026-05-02', 'de', true);
        self::assertSame(2, $first['applied_rows']);
        $firstLotId = $lot->id;

        $lot->delete();
        $rebuilt = WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-01-01',
            'quantity' => 5,
        ]);
        self::assertNotSame($firstLotId, $rebuilt->id);

        $replay = $service->preview('2026-05-02', 'de', true);
        self::assertSame(0, $replay['new_accrual_rows']);
        self::assertSame(2, $replay['noop_accrual_rows']);
        self::assertDatabaseCount('warehouse_storage_aging_accruals', 2);
    }

    public function test_quantity_change_only_changes_future_month_accrual(): void
    {
        $warehouse = $this->warehouse('ca', 'CA', 'CA');
        $product = $this->product('P9-QTY');
        $service = $this->app->make(WarehouseStorageAgingAccrualService::class);

        $lot = WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-01-01',
            'quantity' => 17,
        ]);

        $service->apply('2026-05-02', 'ca', true);
        $lot->update(['quantity' => 10]);

        $preview = $service->preview('2026-06-01', 'ca');
        self::assertSame(3, $preview['noop_accrual_rows'] + $preview['new_accrual_rows']);
        self::assertSame(1, $preview['new_accrual_rows']);
        self::assertSame('10.00', $preview['new_amount_cny']);

        $service->apply('2026-06-01', 'ca');
        $rows = WarehouseStorageAgingAccrual::query()->orderBy('aging_month_index')->get();
        self::assertSame(['17.00', '17.00', '10.00'], $rows->pluck('amount_cny')->all());
        self::assertSame([17, 17, 10], $rows->pluck('quantity_snapshot')->all());
    }

    public function test_plan_sha_is_deterministic_and_command_is_dry_run_first(): void
    {
        $warehouse = $this->warehouse('us-la', 'US-LA', 'US');
        $product = $this->product('P9-PLAN');

        WarehouseInventoryLot::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'sellable',
            'putaway_date' => '2026-01-01',
            'quantity' => 2,
        ]);

        $service = $this->app->make(WarehouseStorageAgingAccrualService::class);
        $one = $service->preview('2026-05-02', 'us-la', true);
        $two = $service->preview('2026-05-02', 'us-la', true);

        self::assertSame($one['plan_sha'], $two['plan_sha']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $one['plan_sha']);

        $this->artisan('inventory:accrue-storage-aging-cost', [
            '--as-of' => '2026-05-02',
            '--warehouse' => 'us-la',
            '--backfill-current-remaining' => true,
        ])->assertSuccessful();

        self::assertDatabaseCount('warehouse_storage_aging_accruals', 0);
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
                'priority' => 100,
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
}
