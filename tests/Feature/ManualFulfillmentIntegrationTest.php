<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Infrastructure\Models\ShipmentItemPackagingSnapshot;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use App\Modules\Products\Infrastructure\Models\ProductWeightEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ManualFulfillmentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_logistics_shows_inventory_and_saves_manual_fulfillment(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $this->makeReservable($warehouse, $product, 12, 3);

        $this->actingAs($user)->get('/logistics/wait-stock')->assertInertia(
            fn (Assert $page) => $page
                ->where('orders.data.0.id', $order->id)
                ->where('orders.data.0.items.0.availability', fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['warehouse_id'] === $warehouse->id && $row['quantity_available'] === 9
                ))
        );

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/draft', [
            'warehouse_id' => $warehouse->id,
            'carrier' => 'UPS',
            'service' => 'Ground',
            'tracking_number' => 'DRAFT-TRACK',
        ])->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'pending',
            'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'manual',
            'carrier' => 'UPS',
            'service' => 'Ground',
            'carrier_selection_source' => 'manual',
            'tracking_number' => 'DRAFT-TRACK',
        ]);
        $this->assertDatabaseHas('audit_records', ['action' => 'shipment.fulfillment_draft_saved']);
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'reserved',
        ]);
        $this->assertSame(5, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_reserved'));

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/ship', [
            'warehouse_id' => $warehouse->id,
            'carrier' => 'UPS',
            'service' => 'Ground',
            'tracking_number' => 'SHIP-TRACK',
            'shipped_at' => '2026-08-27T05:00:00Z',
        ])->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'shipped',
            'warehouse_id' => $warehouse->id,
            'service' => 'Ground',
            'tracking_number' => 'SHIP-TRACK',
        ]);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id,
            'website_sync_status' => 'pending',
        ]);
    }

    public function test_pending_batches_external_references_and_packaging_cost_reads_across_rows(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 100, 'quantity_reserved' => 0],
        );
        $measurement = PackagingMeasurementRecord::query()->create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'status' => 'approved',
            'packaging_method_key' => 'bubble_wrap_cut', 'packaging_length_cm' => '32.00',
            'packaging_width_cm' => '18.00', 'packaging_height_cm' => '12.00', 'packaged_weight_kg' => '0.850',
            'measured_at' => now(), 'created_by' => $user->id, 'reviewed_by' => $user->id, 'reviewed_at' => now(),
        ]);
        ProductPackagingProfile::query()->create([
            'product_id' => $product->id, 'preset_key' => 'bubble_wrap_cut',
            'current_measurement_record_id' => $measurement->id, 'is_active' => true,
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($user)->post('/customers/orders', [
                'external_order_number' => 'P1-PERF-'.$i.'-'.uniqid(),
                'order_date' => '2026-09-08',
                'recipient_name' => 'P1 Perf',
                'country' => 'US',
                'items' => [[
                    'product_id' => $product->id,
                    'product_summary' => $product->name,
                    'quantity' => 1,
                    'unit_price' => '19.99',
                ]],
            ])->assertRedirect();
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($user)->get('/logistics/wait-stock')->assertOk();

        $externalReferenceLoads = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'from "order_external_references" where "order_external_references"."order_id" in')
            || str_contains($sql, 'from `order_external_references` where `order_external_references`.`order_id` in')
        )->count();
        $packagingStandardLoads = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'product_packaging_profiles')
            && str_contains($sql, 'packaging_measurement_records')
        )->count();
        $shippingWeightStandardLoads = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'product_weight_evidences')
            && str_contains($sql, 'shipping_weight_evidence_id')
        )->count();

        $this->assertSame(1, $externalReferenceLoads, 'Wait-stock page must eager-load external references once for the page.');
        $this->assertSame(1, $packagingStandardLoads, 'Wait-stock page must load packaging standards once for the page.');
        $this->assertSame(1, $shippingWeightStandardLoads, 'Wait-stock page must load shipping-weight standards once for the page.');
    }

    public function test_order_detail_exposes_inventory_and_selected_warehouse(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $item = $order->items()->firstOrFail();
        $this->makeReservable($warehouse, $product, 8, 2);
        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/draft', [
            'warehouse_id' => $warehouse->id,
        ])->assertRedirect();

        $this->actingAs($user)->get('/customers/orders/'.$order->id)->assertInertia(
            fn (Assert $page) => $page
                ->where('order.shipment.warehouse_id', $warehouse->id)
                ->where('order.shipment.warehouse_name', $warehouse->name)
                ->where("allocationUi.selected_warehouse_ids.{$item->id}", $warehouse->id)
                ->where('allocationUi.can_operate', true)
                ->where("inventoryAvailability.{$product->id}", fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['warehouse_id'] === $warehouse->id && $row['quantity_available'] === 4
                ))
        );
    }

    public function test_order_detail_quick_allocation_uses_the_same_manual_allocation_pipeline(): void
    {
        [$user, $order, $product] = $this->scenario();
        $item = $order->items()->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'US-LA')->firstOrFail();
        $this->makeReservable($warehouse, $product, 8, 0);

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/fulfillment-allocation/quick', [
            'assignments' => [[
                'order_item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
            ]],
        ])->assertRedirect();

        $this->assertDatabaseHas('fulfillment_allocations', [
            'order_id' => $order->id,
            'status' => 'submitted',
            'decision_source' => 'manual',
        ]);
        $this->actingAs($user)->get('/customers/orders/'.$order->id)->assertInertia(
            fn (Assert $page) => $page
                ->where('allocationUi.status', 'submitted')
                ->where('allocationUi.can_operate', false)
                ->where('allocationUi.can_reallocate', true)
                ->where('allocationUi.logistics_started', false)
                ->where('canOperateLogistics', true)
                ->where("allocationUi.selected_warehouse_ids.{$item->id}", $warehouse->id)
        );

        $order->shipment()->update([
            'shipment_status' => 'shipped',
            'carrier' => 'USPS',
            'service' => 'Ground',
            'tracking_number' => 'REALLOCATION-LOCKED',
            'shipped_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers/orders/'.$order->id)->assertInertia(
            fn (Assert $page) => $page
                ->where('allocationUi.status', 'submitted')
                ->where('allocationUi.can_reallocate', false)
                ->where('allocationUi.logistics_started', true)
        );
    }

    public function test_wait_stock_projects_inventory_context_without_persisting_a_shipment(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 5, 'quantity_reserved' => 1],
        );

        $this->actingAs($user)->get('/logistics/wait-stock')->assertInertia(
            fn (Assert $page) => $page
                ->component('Logistics/FulfillmentAllocation')
                ->where('orders.data.0.id', $order->id)
                ->where('orders.data.0.items.0.availability', fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['warehouse_id'] === $warehouse->id && $row['quantity_available'] === 4
                ))
        );

        $this->assertDatabaseMissing('shipments', ['order_id' => $order->id]);
    }

    public function test_order_uses_current_product_packaging_standard_and_fulfillment_freezes_snapshot(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $this->makeReservable($warehouse, $product, 10);
        $measurement = PackagingMeasurementRecord::query()->create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'status' => 'approved', 'packaging_method_key' => 'bubble_wrap_cut',
            'packaging_length_cm' => '32.00', 'packaging_width_cm' => '18.00', 'packaging_height_cm' => '12.00', 'packaged_weight_kg' => '0.850',
            'measured_at' => '2026-09-04 10:00:00', 'created_by' => $user->id, 'reviewed_by' => $user->id, 'reviewed_at' => now(),
        ]);
        $profile = ProductPackagingProfile::query()->create([
            'product_id' => $product->id, 'preset_key' => 'bubble_wrap_cut', 'current_measurement_record_id' => $measurement->id, 'is_active' => true,
        ]);
        $item = $order->items()->firstOrFail();

        $this->actingAs($user)->get('/customers/orders/'.$order->id)->assertInertia(
            fn (Assert $page) => $page
                ->where("packagingReferences.{$item->id}.source", 'current_product_standard')
                ->where("packagingReferences.{$item->id}.packaged_weight_kg", '0.850')
                ->where("packagingReferences.{$item->id}.packaging_length_cm", '32.00')
        );

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/draft', ['warehouse_id' => $warehouse->id])->assertRedirect();
        $shipment = $order->fresh()->shipment()->firstOrFail();
        $snapshot = ShipmentItemPackagingSnapshot::query()->where('shipment_id', $shipment->id)->where('order_item_id', $item->id)->firstOrFail();
        $this->assertSame('0.850', $snapshot->packaged_weight_kg);
        $this->assertSame($measurement->id, $snapshot->packaging_measurement_record_id);

        $newMeasurement = PackagingMeasurementRecord::query()->create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'status' => 'approved', 'packaging_method_key' => 'carton',
            'packaging_length_cm' => '30.00', 'packaging_width_cm' => '17.00', 'packaging_height_cm' => '11.00', 'packaged_weight_kg' => '0.720',
            'measured_at' => '2026-10-01 10:00:00', 'created_by' => $user->id, 'reviewed_by' => $user->id, 'reviewed_at' => now(),
        ]);
        $profile->update(['current_measurement_record_id' => $newMeasurement->id, 'preset_key' => 'carton']);

        $this->actingAs($user)->get('/customers/orders/'.$order->id)->assertInertia(
            fn (Assert $page) => $page
                ->where("packagingReferences.{$item->id}.source", 'shipment_snapshot')
                ->where("packagingReferences.{$item->id}.packaged_weight_kg", '0.850')
                ->where("packagingReferences.{$item->id}.packaging_method_label", '气泡袋裁剪')
        );
        $this->actingAs($user)->get('/logistics/orders/'.$order->id.'/payload')
            ->assertJsonPath('items.0.packaging_reference.source', 'shipment_snapshot')
            ->assertJsonPath('items.0.packaging_reference.packaged_weight_kg', '0.850');
    }

    public function test_adopting_recommendation_creates_reservation_and_recommended_shipment_draft_idempotently(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 5, 'quantity_reserved' => 0],
        );

        $url = '/logistics/orders/'.$order->id.'/adopt-recommendation';
        $this->actingAs($user)->post($url)->assertRedirect();
        $this->actingAs($user)->post($url)->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'pending',
            'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'recommended',
        ]);
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'reserved',
        ]);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertSame(2, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_reserved'));
    }

    public function test_recommended_reservation_commits_when_order_is_marked_shipped(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 5, 'quantity_reserved' => 0],
        );

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/adopt-recommendation')->assertRedirect();
        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/ship', [
            'warehouse_id' => $warehouse->id,
            'carrier' => 'UPS',
            'service' => 'Ground',
            'tracking_number' => 'ASSIST-TRACK',
            'shipped_at' => '2026-09-05T12:00:00Z',
        ])->assertRedirect();

        $inventory = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(3, $inventory->quantity_on_hand);
        $this->assertSame(0, $inventory->quantity_reserved);
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'status' => 'committed']);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'warehouse_selection_source' => 'recommended', 'shipment_status' => 'shipped']);
    }

    public function test_shipping_cannot_bypass_an_active_reservation_with_another_warehouse(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 5, 'quantity_reserved' => 0],
        );
        $other = Warehouse::query()->where('id', '!=', $warehouse->id)->where('is_active', true)->firstOrFail();

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/adopt-recommendation')->assertRedirect();
        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/ship', [
            'warehouse_id' => $other->id,
            'carrier' => 'UPS',
            'service' => 'Ground',
            'shipped_at' => '2026-09-05T12:00:00Z',
        ])->assertStatus(409);

        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'status' => 'reserved', 'warehouse_id' => $warehouse->id]);
        $this->assertSame(2, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_reserved'));
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'shipment_status' => 'pending', 'warehouse_id' => $warehouse->id]);
    }

    public function test_manual_warehouse_override_releases_recommended_reservation(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 5, 'quantity_reserved' => 0],
        );
        $other = Warehouse::query()->where('id', '!=', $warehouse->id)->where('is_active', true)->firstOrFail();
        $this->makeReservable($other, $product, 6);

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/adopt-recommendation')->assertRedirect();
        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/draft', [
            'warehouse_id' => $other->id,
            'carrier' => 'UPS',
            'service' => 'Ground',
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'status' => 'released', 'release_reason' => 'manual_warehouse_override']);
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'warehouse_id' => $other->id, 'status' => 'reserved']);
        $this->assertSame(0, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_reserved'));
        $this->assertSame(2, WarehouseInventory::query()->where('warehouse_id', $other->id)->where('product_id', $product->id)->value('quantity_reserved'));
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'warehouse_id' => $other->id, 'warehouse_selection_source' => 'manual']);
    }

    public function test_adopt_recommendation_returns_json_warehouse_when_accept_json(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 5, 'quantity_reserved' => 0],
        );

        $response = $this->actingAs($user)
            ->postJson('/logistics/orders/'.$order->id.'/adopt-recommendation');

        $response->assertOk()
            ->assertJsonStructure(['warehouse_id', 'warehouse_name'])
            ->assertJsonPath('warehouse_id', $warehouse->id)
            ->assertJsonPath('warehouse_name', $warehouse->name);

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'recommended',
        ]);
    }

    public function test_adopt_recommendation_json_non_redirect_does_not_navigate(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 5, 'quantity_reserved' => 0],
        );

        $response = $this->actingAs($user)
            ->postJson('/logistics/orders/'.$order->id.'/adopt-recommendation');

        $response->assertOk()->assertHeader('content-type', 'application/json');
        $this->assertFalse($response->isRedirect(), 'JSON path must not redirect (no Inertia navigation)');
    }

    public function test_manual_override_rolls_back_when_target_warehouse_cannot_reserve(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        $this->makeReservable($warehouse, $product, 5);
        $other = Warehouse::query()->where('id', '!=', $warehouse->id)->where('is_active', true)->firstOrFail();

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/adopt-recommendation')->assertRedirect();
        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/draft', [
            'warehouse_id' => $other->id,
            'carrier' => 'UPS',
            'service' => 'Ground',
        ])->assertStatus(409);

        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'reserved',
        ]);
        $this->assertDatabaseMissing('inventory_reservations', [
            'order_id' => $order->id,
            'warehouse_id' => $other->id,
            'status' => 'reserved',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'recommended',
        ]);
        $this->assertSame(2, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_reserved'));
    }

    private function makeReservable(Warehouse $warehouse, Product $product, int $quantityOnHand, int $quantityReserved = 0): void
    {
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id,
            'source_type' => 'xlsx',
            'sync_mode' => 'full',
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => $quantityOnHand, 'quantity_reserved' => $quantityReserved],
        );
    }

    private function scenario(): array
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'logistics.view', 'logistics.operate']);
        $product = Product::firstOrCreate(
            ['sku' => 'MVP1-LINK-1'],
            ['name' => 'MVP1 Linked Product', 'product_type' => 'simple', 'woocommerce_product_id' => 991001],
        );
        if ($product->shipping_weight_evidence_id === null) {
            $weightEvidence = ProductWeightEvidence::create([
                'product_id' => $product->id,
                'weight_kind' => 'shipping_reference_weight',
                'observed_weight_kg' => 0.5000,
                'raw_sku' => $product->sku,
                'source_type' => 'manual_entry',
                'source_reference' => 'manual-fulfillment-test-fixture',
                'status' => 'adopted',
                'reviewed_at' => now(),
            ]);
            $product->forceFill([
                'shipping_weight_kg' => 0.5000,
                'shipping_weight_evidence_id' => $weightEvidence->id,
            ])->save();
        }
        $warehouse = Warehouse::query()->where('is_active', true)->orderBy('priority')->firstOrFail();

        $this->actingAs($user)->post('/customers/orders', [
            'external_order_number' => 'MVP1-'.uniqid(),
            'order_date' => '2026-08-27',
            'recipient_name' => 'Manual Fulfillment UAT',
            'country' => 'US',
            'items' => [[
                'product_id' => $product->id,
                'product_summary' => $product->name,
                'quantity' => 2,
                'unit_price' => '19.99',
            ]],
        ])->assertRedirect();

        return [$user, Order::query()->latest('id')->firstOrFail(), $product, $warehouse];
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'manual-fulfillment-'.uniqid(), 'label' => 'Manual Fulfillment']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
