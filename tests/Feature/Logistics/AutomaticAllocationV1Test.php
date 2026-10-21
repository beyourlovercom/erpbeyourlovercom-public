<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\AutomaticAllocationService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductWeightEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AutomaticAllocationV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_preview_allows_only_de_to_de_without_writing(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario('DE', 'DE');
        $this->authoritative($warehouse, $product, 'DE', 5);
        $this->configure(false, true);

        $result = app(AutomaticAllocationService::class)->preview($order);

        $this->assertSame('READY_AUTO_ALLOCATE', $result['state']);
        $this->assertSame('DE', $result['warehouse']['code']);
        $this->assertFalse($result['mutated']);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('shipments', 0);
        $inventory = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(5, $inventory->quantity_on_hand);
        $this->assertSame(0, $inventory->quantity_reserved);
    }

    public function test_us_remains_assisted_only_even_when_us_inventory_is_recommendable(): void
    {
        [, $order, $product, $warehouse] = $this->scenario('US', 'US-LA');
        $this->authoritative($warehouse, $product, 'US', 5);
        $this->configure(false, true);

        $result = app(AutomaticAllocationService::class)->preview($order);

        $this->assertSame('COUNTRY_NOT_AUTO_ENABLED', $result['state']);
        $this->assertFalse($result['can_apply']);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_apply_creates_one_automatic_reservation_and_draft_without_changing_qoh(): void
    {
        [, $order, $product, $warehouse] = $this->scenario('DE', 'DE');
        $this->authoritative($warehouse, $product, 'DE', 5);
        $this->configure(true, false);

        $service = app(AutomaticAllocationService::class);
        $first = $service->allocate($order);
        $second = $service->allocate($order->fresh());
        $this->assertTrue($first['mutated']);
        $this->assertTrue($second['idempotent']);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'reserved',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'shipment_status' => 'pending',
            'warehouse_selection_source' => 'automatic',
        ]);
        $inventory = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(5, $inventory->quantity_on_hand);
        $this->assertSame(2, $inventory->quantity_reserved);
    }

    public function test_historical_order_is_permanently_excluded(): void
    {
        [, $order, $product, $warehouse] = $this->scenario('DE', 'DE');
        $this->authoritative($warehouse, $product, 'DE', 5);
        $order->externalReferences()->create([
            'provider' => 'historical_logistics',
            'connection_key' => 'test-history',
            'reference' => 'row-'.$order->id,
        ]);
        $this->configure(false, true);
        $result = app(AutomaticAllocationService::class)->preview($order->fresh());

        $this->assertSame('HISTORICAL_EXCLUDED', $result['state']);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_manual_override_releases_automatic_reservation_and_becomes_manual(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario('DE', 'DE');
        $this->authoritative($warehouse, $product, 'DE', 5);
        $this->configure(true, false);
        app(AutomaticAllocationService::class)->allocate($order);

        $other = Warehouse::query()->where('id', '!=', $warehouse->id)->where('is_active', true)->orderBy('id')->firstOrFail();
        $this->authoritative($other, $product, 'US', 6);
        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/draft', [
            'warehouse_id' => $other->id,
            'carrier' => 'UPS',
            'service' => 'Ground',
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'status' => 'released',
            'release_reason' => 'manual_warehouse_override',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => $other->id,
            'warehouse_selection_source' => 'manual',
        ]);
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'warehouse_id' => $other->id,
            'status' => 'reserved',
        ]);
        $this->assertSame(2, WarehouseInventory::query()
            ->where('warehouse_id', $other->id)
            ->where('product_id', $product->id)
            ->value('quantity_reserved'));
        $this->assertSame(0, WarehouseInventory::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity_reserved'));
    }

    public function test_shipping_commits_automatic_reservation_and_preserves_source(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario('DE', 'DE');
        $this->authoritative($warehouse, $product, 'DE', 5);
        $this->configure(true, false);
        app(AutomaticAllocationService::class)->allocate($order);

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/ship', [
            'warehouse_id' => $warehouse->id,
            'carrier' => 'UPS',
            'service' => 'Ground',
            'tracking_number' => 'AUTO-TRACK',
            'shipped_at' => '2026-09-06T12:00:00Z',
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'status' => 'committed']);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'shipped',
            'warehouse_selection_source' => 'automatic',
        ]);
        $inventory = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(3, $inventory->quantity_on_hand);
        $this->assertSame(0, $inventory->quantity_reserved);
    }

    public function test_cli_explicit_dry_run_never_mutates_ready_order(): void
    {
        [, $order, $product, $warehouse] = $this->scenario('DE', 'DE');
        $this->authoritative($warehouse, $product, 'DE', 5);
        $this->configure(false, true);

        $this->artisan('inventory:auto-allocate', ['--order' => $order->id, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertSame(5, WarehouseInventory::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity_on_hand'));
    }

    public function test_cli_limit_is_applied_after_country_allowlist_filter(): void
    {
        [, $usOrder] = $this->scenario('US', 'US-LA');
        [, $deOrder, $product, $warehouse] = $this->scenario('DE', 'DE');
        $this->authoritative($warehouse, $product, 'DE', 5);
        $this->configure(true, false);

        $this->assertLessThan($deOrder->id, $usOrder->id);

        $this->artisan('inventory:auto-allocate', ['--limit' => 1, '--apply' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('inventory_reservations', ['order_id' => $usOrder->id]);
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $deOrder->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'reserved',
        ]);
    }

    public function test_cli_apply_is_blocked_by_default_runtime_gate(): void
    {
        [, $order, $product, $warehouse] = $this->scenario('DE', 'DE');
        $this->authoritative($warehouse, $product, 'DE', 5);
        $this->configure(false, true);

        $this->artisan('inventory:auto-allocate', ['--order' => $order->id, '--apply' => true])
            ->assertFailed();

        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('shipments', 0);
    }

    private function scenario(string $country, string $warehouseCode): array
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'logistics.view', 'logistics.operate']);
        $product = Product::firstOrCreate(
            ['sku' => 'AUTO-ALLOC-1'],
            ['name' => 'Automatic Allocation Product', 'product_type' => 'simple', 'woocommerce_product_id' => 991101],
        );
        if ($product->shipping_weight_evidence_id === null) {
            $weightEvidence = ProductWeightEvidence::create([
                'product_id' => $product->id,
                'weight_kind' => 'shipping_reference_weight',
                'observed_weight_kg' => 0.5000,
                'raw_sku' => $product->sku,
                'source_type' => 'manual_entry',
                'source_reference' => 'automatic-allocation-test-fixture',
                'status' => 'adopted',
                'reviewed_at' => now(),
            ]);
            $product->forceFill([
                'shipping_weight_kg' => 0.5000,
                'shipping_weight_evidence_id' => $weightEvidence->id,
            ])->save();
        }
        $warehouse = Warehouse::query()->where('code', $warehouseCode)->firstOrFail();

        $this->actingAs($user)->post('/customers/orders', [
            'external_order_number' => 'AUTO-'.uniqid(),
            'order_date' => '2026-09-06',
            'recipient_name' => 'Automatic Allocation UAT',
            'country' => $country,
            'items' => [[
                'product_id' => $product->id,
                'product_summary' => $product->name,
                'quantity' => 2,
                'unit_price' => '19.99',
            ]],
        ])->assertRedirect();

        return [$user, Order::query()->latest('id')->firstOrFail(), $product, $warehouse];
    }

    private function authoritative(Warehouse $warehouse, Product $product, string $country, int $qoh): void
    {
        $warehouse->serviceRegions()->create(['country_code' => $country, 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id,
            'source_type' => 'xlsx',
            'sync_mode' => 'full',
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => $qoh, 'quantity_reserved' => 0],
        );
    }

    private function configure(bool $enabled, bool $dryRun): void
    {
        config([
            'inventory_auto_allocation.enabled' => $enabled,
            'inventory_auto_allocation.dry_run' => $dryRun,
            'warehouse_routing.countries.DE' => [
                'automatic' => true,
                'strategy' => 'fixed',
                'warehouses' => [['code' => 'DE', 'priority' => 1, 'role' => 'primary']],
            ],
            'warehouse_routing.countries.US' => [
                'automatic' => false,
                'strategy' => 'discovery',
                'warehouses' => [
                    ['code' => 'US-LA'],
                    ['code' => 'US-NY'],
                    ['code' => 'SMGJ'],
                ],
            ],
        ]);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'auto-allocation-'.uniqid(), 'label' => 'Automatic Allocation']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
