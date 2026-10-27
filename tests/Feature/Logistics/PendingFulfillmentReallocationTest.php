<?php

namespace Tests\Feature\Logistics;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PendingFulfillmentReallocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_pending_child_can_move_after_sibling_has_shipped_and_parent_snapshot_is_updated(): void
    {
        $user = $this->userWith(['orders.view', 'orders.edit', 'logistics.operate']);
        [$parent, $a, $b, $target] = $this->splitScenario();
        $aBefore = $a->shipment()->firstOrFail()->only(['warehouse_id', 'shipment_status', 'tracking_number', 'shipped_at']);

        $this->actingAs($user)->postJson('/logistics/orders/'.$b->id.'/reallocate-pending-fulfillment', [
            'warehouse_id' => $target->id,
            'note' => 'A already shipped; move only B.',
        ])->assertOk()
            ->assertJsonPath('status', 'reallocated')
            ->assertJsonPath('suffix', 'B')
            ->assertJsonPath('warehouse_id', $target->id);

        $a->refresh();
        $b->refresh();
        $this->assertSame($aBefore['warehouse_id'], $a->shipment()->value('warehouse_id'));
        $this->assertSame($aBefore['shipment_status'], $a->shipment()->value('shipment_status'));
        $this->assertSame($aBefore['tracking_number'], $a->shipment()->value('tracking_number'));
        $this->assertSame($target->id, $b->shipment()->value('warehouse_id'));
        $this->assertSame('pending', $b->shipment()->value('shipment_status'));
        $this->assertNull($b->shipment()->value('tracking_number'));

        $allocation = $parent->fulfillmentAllocation()->firstOrFail();
        $snapshot = $allocation->plan_snapshot;
        $this->assertSame('US-NY', $snapshot['segments'][1]['source_code']);
        $this->assertSame($target->id, $snapshot['segments'][1]['warehouse_id']);
        $this->assertSame('B', $snapshot['derived_orders'][1]['suffix']);
        $this->assertSame($target->id, $snapshot['derived_orders'][1]['warehouse_id']);
        $this->assertSame('partial_pending_fulfillment_reallocated', $snapshot['execution_status']);
        $this->assertStringStartsWith('partial:', $allocation->selected_plan_key);
    }

    public function test_pending_child_with_reserved_stock_cannot_move_warehouse_without_atomic_replan(): void
    {
        $user = $this->userWith(['orders.view', 'orders.edit', 'logistics.operate']);
        [$parent, $a, $b, $target] = $this->splitScenario();
        $originalWarehouseId = $b->shipment()->value('warehouse_id');
        InventoryReservation::query()->create([
            'reservation_key' => 'partial-held-'.$b->id,
            'payload_hash' => str_repeat('c', 64),
            'order_id' => $b->id,
            'warehouse_id' => $originalWarehouseId,
            'status' => 'reserved',
        ]);

        $this->actingAs($user)->postJson('/logistics/orders/'.$b->id.'/reallocate-pending-fulfillment', [
            'warehouse_id' => $target->id,
            'note' => 'Should be rejected because stock held at old warehouse',
        ])->assertStatus(409);

        $this->assertSame($originalWarehouseId, $b->shipment()->value('warehouse_id'));
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $b->id, 'warehouse_id' => $originalWarehouseId, 'status' => 'reserved',
        ]);
        $this->assertSame('FACTORY', $parent->fresh()->fulfillmentAllocation?->plan_snapshot['segments'][1]['source_code']);
    }

    public function test_pending_child_with_committed_stock_cannot_move_or_rewrite_history(): void
    {
        $user = $this->userWith(['orders.view', 'orders.edit', 'logistics.operate']);
        [$parent, $a, $b, $target] = $this->splitScenario();
        $warehouseId = $b->shipment()->value('warehouse_id');
        InventoryReservation::query()->create([
            'reservation_key' => 'partial-committed-'.$b->id,
            'payload_hash' => str_repeat('d', 64),
            'order_id' => $b->id, 'warehouse_id' => $warehouseId, 'status' => 'committed',
        ]);

        $this->actingAs($user)->postJson('/logistics/orders/'.$b->id.'/reallocate-pending-fulfillment', [
            'warehouse_id' => $target->id,
        ])->assertStatus(409);
        $this->assertSame($warehouseId, $b->shipment()->value('warehouse_id'));
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $b->id, 'warehouse_id' => $warehouseId, 'status' => 'committed',
        ]);
    }

    public function test_shipped_child_cannot_be_reallocated(): void
    {
        $user = $this->userWith(['orders.view', 'orders.edit', 'logistics.operate']);
        [, $a, , $target] = $this->splitScenario();

        $this->actingAs($user)->postJson('/logistics/orders/'.$a->id.'/reallocate-pending-fulfillment', [
            'warehouse_id' => $target->id,
        ])->assertStatus(409);
    }

    public function test_pending_child_requires_a_started_sibling_otherwise_full_reallocation_is_used(): void
    {
        $user = $this->userWith(['orders.view', 'orders.edit', 'logistics.operate']);
        [, $a, $b, $target] = $this->splitScenario();
        $a->shipment()->update(['shipment_status' => 'pending', 'tracking_number' => null, 'shipped_at' => null]);
        $a->forceFill(['order_status' => 'pending_shipment'])->save();

        $this->actingAs($user)->postJson('/logistics/orders/'.$b->id.'/reallocate-pending-fulfillment', [
            'warehouse_id' => $target->id,
        ])->assertStatus(409);
    }

    private function splitScenario(): array
    {
        $productA = Product::query()->create(['sku' => 'PARTIAL-A', 'name' => 'Partial A', 'product_type' => 'simple']);
        $productB = Product::query()->create(['sku' => 'PARTIAL-B', 'name' => 'Partial B', 'product_type' => 'simple']);
        $la = Warehouse::query()->updateOrCreate(['code' => 'US-LA'], ['slug' => 'partial-la', 'name' => '美西 MorePlus 仓', 'country_code' => 'US', 'is_active' => true]);
        $factory = Warehouse::query()->updateOrCreate(['code' => 'FACTORY'], ['slug' => 'partial-factory', 'name' => '国内工厂', 'country_code' => 'CN', 'is_active' => true]);
        $ny = Warehouse::query()->updateOrCreate(['code' => 'US-NY'], ['slug' => 'partial-ny', 'name' => '美东纽约仓', 'country_code' => 'US', 'is_active' => true]);

        $parent = Order::query()->create([
            'internal_order_number' => 'PARTIAL-ROOT-'.uniqid(),
            'erp_order_id' => '007-0919-cPARTIAL'.random_int(100, 999),
            'source_type' => 'manual', 'order_kind' => 'customer', 'external_order_number' => 'PARTIAL-BIZ-'.uniqid(),
            'order_date' => '2026-09-19', 'order_status' => 'pending_shipment', 'currency' => 'USD',
        ]);
        $parent->address()->create(['recipient_name' => 'Partial Customer', 'country' => 'US', 'province_state' => 'NY', 'city' => 'New York', 'address_line_1' => '100 Partial St', 'postal_code' => '10001']);
        $itemA = $parent->items()->create(['product_id' => $productA->id, 'product_summary' => $productA->name, 'sku' => $productA->sku, 'quantity' => 1, 'unit_price' => '20.00', 'line_revenue' => '20.00']);
        $itemB = $parent->items()->create(['product_id' => $productB->id, 'product_summary' => $productB->name, 'sku' => $productB->sku, 'quantity' => 2, 'unit_price' => '30.00', 'line_revenue' => '60.00']);

        $a = $this->child($parent, 'A', $itemA, $la, 'shipped', 'TRACK-A');
        $b = $this->child($parent, 'B', $itemB, $factory, 'pending', null);

        $parent->fulfillmentAllocation()->create([
            'status' => 'submitted', 'decision_source' => 'manual', 'selected_plan_key' => 'manual:original',
            'plan_snapshot' => [
                'plan_key' => 'manual:original', 'plan_type' => 'split', 'is_split' => true, 'shipment_count' => 2,
                'segments' => [
                    ['source_code' => 'US-LA', 'warehouse_id' => $la->id, 'warehouse_name' => $la->name, 'carrier' => 'USPS', 'service' => 'Ground', 'items' => [['order_item_id' => $itemA->id, 'quantity' => 1]]],
                    ['source_code' => 'FACTORY', 'warehouse_id' => $factory->id, 'warehouse_name' => $factory->name, 'carrier' => '4PX', 'service' => '普通', 'items' => [['order_item_id' => $itemB->id, 'quantity' => 2]]],
                ],
                'derived_orders' => [
                    ['order_id' => $a->id, 'suffix' => 'A', 'warehouse_id' => $la->id, 'warehouse_code' => 'US-LA', 'warehouse_name' => $la->name, 'carrier' => 'USPS', 'service' => 'Ground'],
                    ['order_id' => $b->id, 'suffix' => 'B', 'warehouse_id' => $factory->id, 'warehouse_code' => 'FACTORY', 'warehouse_name' => $factory->name, 'carrier' => '4PX', 'service' => '普通'],
                ],
            ],
            'manual_assignments' => [
                ['order_item_id' => $itemA->id, 'source_code' => 'US-LA', 'carrier' => 'USPS', 'service' => 'Ground'],
                ['order_item_id' => $itemB->id, 'source_code' => 'FACTORY', 'carrier' => '4PX', 'service' => '普通'],
            ],
        ]);

        return [$parent->fresh(), $a->fresh(), $b->fresh(), $ny];
    }

    private function child(Order $parent, string $suffix, $sourceItem, Warehouse $warehouse, string $status, ?string $tracking): Order
    {
        $child = Order::query()->create([
            'fulfillment_parent_id' => $parent->id, 'fulfillment_suffix' => $suffix, 'fulfillment_role' => 'original',
            'internal_order_number' => 'FULF-'.$parent->id.'-'.$suffix, 'erp_order_id' => $parent->erp_order_id.$suffix,
            'source_type' => 'manual_other', 'order_kind' => 'fulfillment_derived', 'order_date' => $parent->order_date,
            'order_status' => $status === 'shipped' ? 'shipped' : 'pending_shipment', 'currency' => 'USD',
        ]);
        $child->items()->create([
            'product_id' => $sourceItem->product_id, 'product_summary' => $sourceItem->product_summary, 'sku' => $sourceItem->sku,
            'source_sku' => $sourceItem->sku, 'resolved_fulfillment_sku' => $sourceItem->sku,
            'quantity' => $sourceItem->quantity, 'unit_price' => $sourceItem->unit_price, 'line_revenue' => $sourceItem->line_revenue,
        ]);
        $child->shipment()->create([
            'shipment_status' => $status, 'warehouse_id' => $warehouse->id, 'warehouse_selection_source' => 'allocation',
            'carrier' => $status === 'shipped' ? 'USPS' : '4PX', 'service' => $status === 'shipped' ? 'Ground' : '普通',
            'tracking_number' => $tracking, 'shipped_at' => $tracking ? now() : null,
        ]);
        return $child;
    }

    private function userWith(array $permissions): User
    {
        $role = Role::query()->create(['key' => 'partial-reallocation-'.uniqid(), 'label' => 'Partial Reallocation']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
