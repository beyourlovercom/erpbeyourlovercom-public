<?php

namespace Tests\Feature\Orders;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\FulfillmentAllocationSubmissionService;
use App\Modules\Orders\Application\OrderAmendmentRevision;
use App\Modules\Orders\Application\OrderItemAtomicReplanService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class OrderItemAtomicReplanTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User,Order,Warehouse,Product,Product,InventoryReservation} */
    private function scenario(int $availableNew = 10): array
    {
        $role = Role::query()->create(['key' => 'amend-'.uniqid(), 'label' => 'Amend Test']);
        foreach (['orders.edit', 'logistics.operate', 'inventory.operate'] as $key) {
            $role->permissions()->firstOrCreate(['key' => $key], ['label' => $key]);
        }
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach($role);

        $wh = Warehouse::query()->where('code', 'US-LA')->firstOrFail();
        InventorySyncBatch::query()->create([
            'warehouse_id' => $wh->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(), 'stock_count_at' => now(),
        ]);
        $a = Product::query()->create(['sku' => 'ATOMIC-RED', 'name' => 'Red',
            'source_type' => 'manual', 'product_type' => 'simple', 'is_active' => true]);
        $b = Product::query()->create(['sku' => 'ATOMIC-BLUE', 'name' => 'Blue',
            'source_type' => 'manual', 'product_type' => 'simple', 'is_active' => true]);
        WarehouseInventory::query()->create([
            'warehouse_id' => $wh->id, 'product_id' => $a->id,
            'quantity_on_hand' => 10, 'quantity_reserved' => 0,
        ]);
        WarehouseInventory::query()->create([
            'warehouse_id' => $wh->id, 'product_id' => $b->id,
            'quantity_on_hand' => $availableNew, 'quantity_reserved' => 0,
        ]);
        $order = Order::query()->create([
            'internal_order_number' => 'AMEND-'.uniqid(),
            'erp_order_id' => '007-1014-'.random_int(10000, 99999),
            'source_type' => 'manual', 'order_kind' => 'customer',
            'order_date' => '2026-10-26', 'order_status' => 'pending_shipment', 'currency' => 'USD',
        ]);
        $order->address()->create([
            'recipient_name' => 'Buyer', 'phone' => '123456789', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Los Angeles',
            'address_line_1' => '123 Test Road', 'postal_code' => '90001',
        ]);
        $line = $order->items()->create([
            'product_id' => $a->id, 'sku' => $a->sku, 'product_summary' => $a->name,
            'quantity' => 2, 'unit_price' => '19.99', 'line_revenue' => '39.98',
        ]);
        app(FulfillmentAllocationSubmissionService::class)->submitManual(
            $order->fresh(['items', 'address']),
            [['order_item_id' => $line->id, 'source_code' => 'US-LA']],
            'Initial manual allocation', $actor,
        );
        $hold = app(InventoryReservationService::class)->reserve(
            $order->id, $wh->id, 'atomic-old-'.$order->id, null, $actor,
        );
        $reservation = InventoryReservation::query()->findOrFail($hold['id']);
        return [$actor, $order->fresh(['items', 'shipment', 'fulfillmentAllocation']), $wh, $a, $b, $reservation];
    }

    private function submit(User $actor, Order $order, Product $blue, string $revision): array
    {
        return app(OrderItemAtomicReplanService::class)->commit(
            $order, [[
                'id' => $order->items()->firstOrFail()->id,
                'product_id' => $blue->id, 'quantity' => 3, 'unit_price' => '19.99',
            ]],
            [['item_index' => 0, 'source_code' => 'US-LA']],
            $revision, 'Customer requested blue version', $actor,
        );
    }

    public function test_authorized_http_replan_endpoint_commits_only_after_explicit_revision_and_reason(): void
    {
        [$actor, $order, $warehouse, $red, $blue, $old] = $this->scenario();
        $revision = app(OrderAmendmentRevision::class)->forOrder($order);
        $line = $order->items()->firstOrFail();
        $this->actingAs($actor)->postJson("/customers/orders/{$order->id}/items/replan", [
            'items' => [[
                'id' => $line->id, 'product_id' => $blue->id,
                'quantity' => 3, 'unit_price' => '19.99',
            ]],
            'assignments' => [['item_index' => 0, 'source_code' => 'US-LA']],
            'expected_revision' => $revision,
            'reason' => 'Customer requested blue version',
        ])->assertOk()->assertJsonPath('warehouse_id', $warehouse->id)
            ->assertJsonPath('previous_reservation_id', $old->id)
            ->assertJsonPath('state', 'pending_manual_fulfillment');

        $this->assertDatabaseHas('order_items', [
            'id' => $line->id, 'sku' => 'ATOMIC-BLUE', 'quantity' => 3,
        ]);
        $this->assertDatabaseHas('inventory_reservations', ['id' => $old->id, 'status' => 'released']);
    }

    public function test_http_replan_requires_permission_and_does_not_touch_held_stock(): void
    {
        [$actor, $order, $warehouse, $red, $blue, $old] = $this->scenario();
        $viewer = User::factory()->create(['is_active' => true]);
        $revision = app(OrderAmendmentRevision::class)->forOrder($order);
        $this->actingAs($viewer)->postJson("/customers/orders/{$order->id}/items/replan", [
            'items' => [['id' => $order->items()->firstOrFail()->id,
                'product_id' => $blue->id, 'quantity' => 3, 'unit_price' => '19.99']],
            'assignments' => [['item_index' => 0, 'source_code' => 'US-LA']],
            'expected_revision' => $revision, 'reason' => 'Not authorized to change order',
        ])->assertForbidden();
        $this->assertDatabaseHas('inventory_reservations', ['id' => $old->id, 'status' => 'reserved']);
        $this->assertSame($revision, app(OrderAmendmentRevision::class)->forOrder($order));
    }

    public function test_adding_zero_price_gift_creates_a_real_reserved_inventory_line(): void
    {
        [$actor, $order, $wh, $red, $blue, $old] = $this->scenario();
        $gift = Product::query()->create([
            'sku' => 'ATOMIC-GIFT', 'name' => 'Gift',
            'source_type' => 'manual', 'product_type' => 'simple', 'is_active' => true,
        ]);
        WarehouseInventory::query()->create([
            'warehouse_id' => $wh->id, 'product_id' => $gift->id,
            'quantity_on_hand' => 6, 'quantity_reserved' => 0,
        ]);
        $beforeId = $order->items()->firstOrFail()->id;
        $result = app(OrderItemAtomicReplanService::class)->commit(
            $order, [
                ['id' => $beforeId, 'product_id' => $red->id, 'quantity' => 2, 'unit_price' => '19.99'],
                ['id' => null, 'product_id' => $gift->id, 'quantity' => 1, 'unit_price' => '0.00'],
            ],
            [
                ['item_index' => 0, 'source_code' => 'US-LA'],
                ['item_index' => 1, 'source_code' => 'US-LA'],
            ],
            app(OrderAmendmentRevision::class)->forOrder($order),
            'Customer requested a complimentary gift', $actor,
        );
        $this->assertCount(2, $result['order_item_ids']);
        $this->assertContains($beforeId, $result['order_item_ids']);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id, 'sku' => 'ATOMIC-GIFT',
            'quantity' => 1, 'unit_price' => '0.00', 'line_revenue' => '0.00',
        ]);
        $this->assertSame(2, (int) WarehouseInventory::query()->where('product_id', $red->id)->value('quantity_reserved'));
        $this->assertSame(1, (int) WarehouseInventory::query()->where('product_id', $gift->id)->value('quantity_reserved'));
        $this->assertDatabaseHas('inventory_reservations', ['id' => $old->id, 'status' => 'released']);
    }

    public function test_migrating_held_stock_between_warehouses_reserves_only_new_warehouse(): void
    {
        [$actor, $order, $oldWh, $red, $blue, $old] = $this->scenario();
        $newWh = Warehouse::query()->where('code', 'US-NY')->firstOrFail();
        InventorySyncBatch::query()->create([
            'warehouse_id' => $newWh->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(), 'stock_count_at' => now(),
        ]);
        WarehouseInventory::query()->create([
            'warehouse_id' => $newWh->id, 'product_id' => $blue->id,
            'quantity_on_hand' => 10, 'quantity_reserved' => 0,
        ]);
        $lineId = $order->items()->firstOrFail()->id;
        $result = app(OrderItemAtomicReplanService::class)->commit(
            $order, [['id' => $lineId, 'product_id' => $blue->id, 'quantity' => 3, 'unit_price' => '19.99']],
            [['item_index' => 0, 'source_code' => 'US-NY']],
            app(OrderAmendmentRevision::class)->forOrder($order),
            'Customer prefers stock from New York warehouse', $actor,
        );
        $this->assertSame($newWh->id, $result['warehouse_id']);
        $this->assertSame($newWh->id, $order->fresh('shipment')->shipment?->warehouse_id);
        $this->assertSame(0, (int) WarehouseInventory::query()
            ->where('warehouse_id', $oldWh->id)->where('product_id', $red->id)->value('quantity_reserved'));
        $this->assertSame(3, (int) WarehouseInventory::query()
            ->where('warehouse_id', $newWh->id)->where('product_id', $blue->id)->value('quantity_reserved'));
        $this->assertDatabaseHas('inventory_reservations', [
            'id' => $result['new_reservation_id'], 'warehouse_id' => $newWh->id, 'status' => 'reserved',
        ]);
        $this->assertDatabaseHas('inventory_reservations', ['id' => $old->id, 'status' => 'released']);
    }

    public function test_invalid_new_carrier_rolls_back_old_warehouse_reservation_and_items(): void
    {
        [$actor, $order, $wh, $red, $blue, $old] = $this->scenario();
        $lineId = $order->items()->firstOrFail()->id;
        try {
            app(OrderItemAtomicReplanService::class)->commit(
                $order,
                [['id' => $lineId, 'product_id' => $blue->id, 'quantity' => 3, 'unit_price' => '19.99']],
                [['item_index' => 0, 'source_code' => 'US-LA', 'carrier' => 'CARRIER-NOT-ENABLED']],
                app(OrderAmendmentRevision::class)->forOrder($order),
                'Customer requested the blue version instead', $actor,
            );
            $this->fail('Invalid carrier must reject full transaction');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertDatabaseHas('order_items', [
            'id' => $lineId, 'sku' => 'ATOMIC-RED', 'quantity' => 2,
        ]);
        $this->assertDatabaseHas('inventory_reservations', ['id' => $old->id, 'status' => 'reserved']);
        $this->assertSame(2, (int) WarehouseInventory::query()->where('product_id', $red->id)->value('quantity_reserved'));
        $this->assertSame(0, (int) WarehouseInventory::query()->where('product_id', $blue->id)->value('quantity_reserved'));
    }

    public function test_success_replaces_single_hold_and_order_item_in_one_transaction(): void
    {
        [$actor, $order, $wh, $red, $blue, $old] = $this->scenario();
        $id = $order->items()->firstOrFail()->id;
        $revision = app(OrderAmendmentRevision::class)->forOrder($order);
        $result = $this->submit($actor, $order, $blue, $revision);
        $this->assertSame($id, $result['order_item_ids'][0]);
        $this->assertNotSame($old->id, $result['new_reservation_id']);
        $this->assertDatabaseHas('inventory_reservations', ['id' => $old->id, 'status' => 'released']);
        $this->assertDatabaseHas('inventory_reservations', [
            'id' => $result['new_reservation_id'], 'status' => 'reserved', 'warehouse_id' => $wh->id,
        ]);
        $this->assertDatabaseHas('order_items', [
            'id' => $id, 'order_id' => $order->id, 'sku' => 'ATOMIC-BLUE', 'quantity' => 3,
        ]);
        $this->assertSame(0, (int) WarehouseInventory::query()->where('product_id', $red->id)->value('quantity_reserved'));
        $this->assertSame(3, (int) WarehouseInventory::query()->where('product_id', $blue->id)->value('quantity_reserved'));
        $this->assertSame('pending', $order->fresh()->shipment?->shipment_status);
        $this->assertNotSame($revision, app(OrderAmendmentRevision::class)->forOrder($order));
    }

    public function test_insufficient_new_stock_rolls_back_release_items_and_allocation(): void
    {
        [$actor, $order, $wh, $red, $blue, $old] = $this->scenario(1);
        $id = $order->items()->firstOrFail()->id;
        $revision = app(OrderAmendmentRevision::class)->forOrder($order);
        try {
            $this->submit($actor, $order, $blue, $revision);
            $this->fail('Insufficient stock must roll back the whole amendment');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }
        $this->assertDatabaseHas('inventory_reservations', ['id' => $old->id, 'status' => 'reserved']);
        $this->assertDatabaseHas('order_items', ['id' => $id, 'sku' => 'ATOMIC-RED', 'quantity' => 2]);
        $this->assertSame(2, (int) WarehouseInventory::query()->where('product_id', $red->id)->value('quantity_reserved'));
        $this->assertSame(0, (int) WarehouseInventory::query()->where('product_id', $blue->id)->value('quantity_reserved'));
        $this->assertSame($revision, app(OrderAmendmentRevision::class)->forOrder($order));
    }

    public function test_stale_revision_rejects_before_releasing_old_reservation(): void
    {
        [$actor, $order, $wh, $red, $blue, $old] = $this->scenario();
        $revision = app(OrderAmendmentRevision::class)->forOrder($order);
        $order->items()->firstOrFail()->forceFill(['quantity' => 4])->save();
        try {
            $this->submit($actor, $order, $blue, $revision);
            $this->fail('Stale revision must be blocked');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseHas('inventory_reservations', ['id' => $old->id, 'status' => 'reserved']);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id, 'sku' => 'ATOMIC-RED', 'quantity' => 4,
        ]);
    }

    public function test_committed_inventory_cannot_be_released_for_replan(): void
    {
        [$actor, $order, $wh, $red, $blue, $old] = $this->scenario();
        app(InventoryReservationService::class)->commit($old->id, $actor);
        $revision = app(OrderAmendmentRevision::class)->forOrder($order);
        try {
            $this->submit($actor, $order, $blue, $revision);
            $this->fail('Committed stock is immutable');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseHas('inventory_reservations', ['id' => $old->id, 'status' => 'committed']);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'sku' => 'ATOMIC-RED']);
    }
}
