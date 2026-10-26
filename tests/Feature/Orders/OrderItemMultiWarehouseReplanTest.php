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
use App\Modules\Orders\Application\OrderItemMultiWarehouseReplanService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class OrderItemMultiWarehouseReplanTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User,Order,array<string,Product>,array<string,Warehouse>,array<int,InventoryReservation>} */
    private function setupSplit(int $targetStock = 10): array
    {
        $role = Role::query()->create(['key' => 'multireplan-'.uniqid(), 'label' => 'Multi replan operator']);
        foreach (['orders.edit', 'logistics.operate', 'inventory.operate'] as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach($role);
        $warehouses = [];
        foreach (['US-LA', 'US-NY', 'US-SM'] as $code) {
            $wh = Warehouse::query()->where('code', $code)->firstOrFail();
            InventorySyncBatch::query()->create([
                'warehouse_id' => $wh->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
                'status' => 'confirmed', 'confirmed_at' => now(), 'stock_count_at' => now(),
            ]);
            $warehouses[$code] = $wh;
        }
        $products = [];
        foreach (['A', 'B', 'C'] as $suffix) {
            $product = Product::query()->create([
                'sku' => 'MULTI-'.$suffix, 'name' => 'Multi '.$suffix,
                'source_type' => 'manual', 'product_type' => 'simple', 'is_active' => true,
            ]);
            $products[$suffix] = $product;
        }
        foreach ([['US-LA', 'A', 10], ['US-NY', 'B', 10], ['US-SM', 'C', $targetStock]] as [$warehouseCode, $productCode, $quantity]) {
            WarehouseInventory::query()->create([
                'warehouse_id' => $warehouses[$warehouseCode]->id,
                'product_id' => $products[$productCode]->id,
                'quantity_on_hand' => $quantity, 'quantity_reserved' => 0,
            ]);
        }
        $order = Order::query()->create([
            'internal_order_number' => 'MUL-'.uniqid(),
            'erp_order_id' => '007-1014-'.random_int(10000, 99999),
            'source_type' => 'manual', 'order_kind' => 'customer',
            'order_date' => '2026-10-26', 'order_status' => 'pending_shipment', 'currency' => 'USD',
        ]);
        $order->address()->create([
            'recipient_name' => 'Buyer', 'phone' => '123456789', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Los Angeles', 'address_line_1' => '12 Test St',
            'postal_code' => '90001',
        ]);
        $lineA = $order->items()->create([
            'product_id' => $products['A']->id, 'sku' => $products['A']->sku,
            'product_summary' => $products['A']->name, 'quantity' => 2,
            'unit_price' => '9.99', 'line_revenue' => '19.98',
        ]);
        $lineB = $order->items()->create([
            'product_id' => $products['B']->id, 'sku' => $products['B']->sku,
            'product_summary' => $products['B']->name, 'quantity' => 1,
            'unit_price' => '19.99', 'line_revenue' => '19.99',
        ]);
        app(FulfillmentAllocationSubmissionService::class)->submitManual(
            $order->fresh(['items', 'address']),
            [
                ['order_item_id' => $lineA->id, 'source_code' => 'US-LA'],
                ['order_item_id' => $lineB->id, 'source_code' => 'US-NY'],
            ], 'Initial two-warehouse order', $actor,
        );
        $holds = [];
        foreach ($order->fresh()->originalFulfillmentChildren()->with('shipment')->get() as $child) {
            $info = app(InventoryReservationService::class)->reserve(
                $child->id, $child->shipment->warehouse_id,
                'multi-before-'.$child->id, null, $actor,
            );
            $holds[$child->id] = InventoryReservation::query()->findOrFail($info['id']);
        }
        return [$actor, $order->fresh(['items', 'fulfillmentChildren']), $products, $warehouses, $holds];
    }

    /** @return array<string,mixed> */
    private function runReplacement(User $actor, Order $order, Product $replacement, string $revision): array
    {
        $lines = $order->items()->orderBy('id')->get();
        return app(OrderItemMultiWarehouseReplanService::class)->commit(
            $order, [
                ['id' => $lines[0]->id, 'product_id' => $lines[0]->product_id,
                    'quantity' => 2, 'unit_price' => '9.99'],
                ['id' => $lines[1]->id, 'product_id' => $replacement->id,
                    'quantity' => 3, 'unit_price' => '19.99'],
            ],
            [
                ['item_index' => 0, 'source_code' => 'US-LA'],
                ['item_index' => 1, 'source_code' => 'US-SM'],
            ], $revision, 'Customer changed the second product color', $actor
        );
    }

    public function test_multileg_preflight_reports_atomic_eligibility_without_mutations(): void
    {
        [$actor, $order, $products, $warehouses, $holds] = $this->setupSplit();
        $items = $order->items()->orderBy('id')->get();
        $revision = app(OrderAmendmentRevision::class)->forOrder($order);
        $this->actingAs($actor)->postJson("/customers/orders/{$order->id}/items/preview", [
            'items' => [
                ['id' => $items[0]->id, 'product_id' => $products['A']->id,
                    'quantity' => 2, 'unit_price' => '9.99'],
                ['id' => $items[1]->id, 'product_id' => $products['C']->id,
                    'quantity' => 3, 'unit_price' => '19.99'],
            ],
        ])->assertOk()
            ->assertJsonPath('can_atomic_multi_replan', true)
            ->assertJsonPath('can_atomic_single_replan', false)
            ->assertJsonPath('expected_revision', $revision)
            ->assertJsonPath('no_writes', true);
        foreach ($holds as $hold) {
            $this->assertDatabaseHas('inventory_reservations', ['id' => $hold->id, 'status' => 'reserved']);
        }
    }

    public function test_authenticated_multi_replan_http_routes_to_atomic_transaction(): void
    {
        [$actor, $order, $products, $warehouses, $holds] = $this->setupSplit();
        $items = $order->items()->orderBy('id')->get();
        $this->actingAs($actor)->postJson("/customers/orders/{$order->id}/items/replan-multi", [
            'items' => [
                ['id' => $items[0]->id, 'product_id' => $products['A']->id,
                    'quantity' => 2, 'unit_price' => '9.99'],
                ['id' => $items[1]->id, 'product_id' => $products['C']->id,
                    'quantity' => 3, 'unit_price' => '19.99'],
            ],
            'assignments' => [
                ['item_index' => 0, 'source_code' => 'US-LA'],
                ['item_index' => 1, 'source_code' => 'US-SM'],
            ],
            'expected_revision' => app(OrderAmendmentRevision::class)->forOrder($order),
            'reason' => 'Customer requested replacement color',
        ])->assertOk()->assertJsonPath('state', 'pending_multi_leg_review')
            ->assertJsonCount(2, 'new_reservations');
        $this->assertDatabaseHas('order_items', ['id' => $items[1]->id, 'sku' => 'MULTI-C']);
    }

    public function test_unauthorized_http_replan_keeps_all_child_reservations_untouched(): void
    {
        [$actor, $order, $products, $warehouses, $holds] = $this->setupSplit();
        $viewer = User::factory()->create(['is_active' => true]);
        $this->actingAs($viewer)->postJson("/customers/orders/{$order->id}/items/replan-multi", [
            'items' => [], 'assignments' => [],
            'expected_revision' => app(OrderAmendmentRevision::class)->forOrder($order),
            'reason' => 'Not authorized to amend order',
        ])->assertForbidden();
        foreach ($holds as $hold) {
            $this->assertDatabaseHas('inventory_reservations', ['id' => $hold->id, 'status' => 'reserved']);
        }
    }

    public function test_two_warehouse_replan_swaps_every_child_hold_without_changing_parent_item_ids(): void
    {
        [$actor, $order, $products, $warehouses, $holds] = $this->setupSplit();
        $oldIds = $order->items()->orderBy('id')->pluck('id')->all();
        $revision = app(OrderAmendmentRevision::class)->forOrder($order);

        $result = $this->runReplacement($actor, $order, $products['C'], $revision);
        $this->assertSame('pending_multi_leg_review', $result['state']);
        $this->assertCount(2, $result['previous_reservation_ids']);
        $this->assertCount(2, $result['new_reservations']);
        $this->assertSame($oldIds, $order->items()->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseHas('order_items', [
            'id' => $oldIds[1], 'sku' => 'MULTI-C', 'quantity' => 3,
        ]);
        foreach ($holds as $hold) {
            $this->assertDatabaseHas('inventory_reservations', ['id' => $hold->id, 'status' => 'released']);
        }
        foreach ($result['new_reservations'] as $reservation) {
            $this->assertDatabaseHas('inventory_reservations', [
                'id' => $reservation['reservation_id'], 'order_id' => $reservation['order_id'],
                'warehouse_id' => $reservation['warehouse_id'], 'status' => 'reserved',
            ]);
        }
        $this->assertSame(0, (int) WarehouseInventory::query()
            ->where('product_id', $products['B']->id)->value('quantity_reserved'));
        $this->assertSame(3, (int) WarehouseInventory::query()
            ->where('product_id', $products['C']->id)->value('quantity_reserved'));
        $this->assertNotSame($revision, app(OrderAmendmentRevision::class)->forOrder($order));
    }

    public function test_adding_zero_price_gift_creates_third_reserved_pending_warehouse_leg(): void
    {
        [$actor, $order, $products, $warehouses, $holds] = $this->setupSplit();
        $gift = Product::query()->create([
            'sku' => 'MULTI-GIFT', 'name' => 'Multi free gift', 'source_type' => 'manual',
            'product_type' => 'simple', 'is_active' => true,
        ]);
        WarehouseInventory::query()->create([
            'warehouse_id' => $warehouses['US-SM']->id, 'product_id' => $gift->id,
            'quantity_on_hand' => 5, 'quantity_reserved' => 0,
        ]);
        $items = $order->items()->orderBy('id')->get();
        $result = app(OrderItemMultiWarehouseReplanService::class)->commit(
            $order, [
                ['id' => $items[0]->id, 'product_id' => $products['A']->id, 'quantity' => 2, 'unit_price' => '9.99'],
                ['id' => $items[1]->id, 'product_id' => $products['B']->id, 'quantity' => 1, 'unit_price' => '19.99'],
                ['id' => null, 'product_id' => $gift->id, 'quantity' => 1, 'unit_price' => '0.00'],
            ],
            [
                ['item_index' => 0, 'source_code' => 'US-LA'],
                ['item_index' => 1, 'source_code' => 'US-NY'],
                ['item_index' => 2, 'source_code' => 'US-SM'],
            ],
            app(OrderAmendmentRevision::class)->forOrder($order),
            'Customer confirmed a free product gift', $actor,
        );
        $this->assertCount(3, $result['new_reservations']);
        $this->assertCount(3, $order->fresh()->originalFulfillmentChildren);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id, 'sku' => 'MULTI-GIFT',
            'quantity' => 1, 'unit_price' => '0.00',
        ]);
        $this->assertSame(1, (int) WarehouseInventory::query()
            ->where('warehouse_id', $warehouses['US-SM']->id)
            ->where('product_id', $gift->id)->value('quantity_reserved'));
        foreach ($holds as $hold) {
            $this->assertDatabaseHas('inventory_reservations', ['id' => $hold->id, 'status' => 'released']);
        }
        foreach ($result['new_reservations'] as $reservation) {
            $this->assertDatabaseHas('inventory_reservations', [
                'id' => $reservation['reservation_id'], 'status' => 'reserved',
            ]);
        }
    }

    public function test_reducing_three_legs_to_two_is_blocked_to_preserve_released_reservation_history(): void
    {
        [$actor, $order, $products, $warehouses, $oldHolds] = $this->setupSplit();
        $gift = Product::query()->create([
            'sku' => 'MULTI-AUDIT-GIFT', 'name' => 'Gift with history', 'source_type' => 'manual',
            'product_type' => 'simple', 'is_active' => true,
        ]);
        WarehouseInventory::query()->create([
            'warehouse_id' => $warehouses['US-SM']->id, 'product_id' => $gift->id,
            'quantity_on_hand' => 5, 'quantity_reserved' => 0,
        ]);
        $items = $order->items()->orderBy('id')->get();
        $first = app(OrderItemMultiWarehouseReplanService::class)->commit(
            $order, [
                ['id' => $items[0]->id, 'product_id' => $products['A']->id, 'quantity' => 2, 'unit_price' => '9.99'],
                ['id' => $items[1]->id, 'product_id' => $products['B']->id, 'quantity' => 1, 'unit_price' => '19.99'],
                ['id' => null, 'product_id' => $gift->id, 'quantity' => 1, 'unit_price' => '0.00'],
            ],
            [
                ['item_index' => 0, 'source_code' => 'US-LA'],
                ['item_index' => 1, 'source_code' => 'US-NY'],
                ['item_index' => 2, 'source_code' => 'US-SM'],
            ],
            app(OrderAmendmentRevision::class)->forOrder($order),
            'Customer approved a complimentary gift', $actor,
        );
        $this->assertCount(3, $first['new_reservations']);
        $itemsNow = $order->items()->orderBy('id')->get();
        $revision = app(OrderAmendmentRevision::class)->forOrder($order);
        try {
            app(OrderItemMultiWarehouseReplanService::class)->commit(
                $order, [
                    ['id' => $itemsNow[0]->id, 'product_id' => $products['A']->id, 'quantity' => 2, 'unit_price' => '9.99'],
                    ['id' => $itemsNow[1]->id, 'product_id' => $products['B']->id, 'quantity' => 1, 'unit_price' => '19.99'],
                ],
                [
                    ['item_index' => 0, 'source_code' => 'US-LA'],
                    ['item_index' => 1, 'source_code' => 'US-NY'],
                ], $revision, 'Customer no longer wants the extra gift', $actor,
            );
            $this->fail('Retiring an existing reserved child needs explicit historical evidence retention');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertCount(3, $order->fresh()->originalFulfillmentChildren);
        $this->assertSame($revision, app(OrderAmendmentRevision::class)->forOrder($order));
        foreach ($first['new_reservations'] as $r) {
            $this->assertDatabaseHas('inventory_reservations', [
                'id' => $r['reservation_id'], 'status' => 'reserved',
            ]);
        }
    }

    public function test_duplicate_item_assignments_fail_before_any_reservation_release(): void
    {
        [$actor, $order, $products, $warehouses, $holds] = $this->setupSplit();
        $items = $order->items()->orderBy('id')->get();
        try {
            app(OrderItemMultiWarehouseReplanService::class)->commit(
                $order, [
                    ['id' => $items[0]->id, 'product_id' => $products['A']->id, 'quantity' => 2, 'unit_price' => '9.99'],
                    ['id' => $items[1]->id, 'product_id' => $products['B']->id, 'quantity' => 1, 'unit_price' => '19.99'],
                ],
                [
                    ['item_index' => 0, 'source_code' => 'US-LA'],
                    ['item_index' => 0, 'source_code' => 'US-NY'],
                ],
                app(OrderAmendmentRevision::class)->forOrder($order),
                'Customer requested color changes', $actor
            );
            $this->fail('Duplicated item assignment must be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assignments', $e->errors());
        }
        foreach ($holds as $hold) {
            $this->assertDatabaseHas('inventory_reservations', ['id' => $hold->id, 'status' => 'reserved']);
        }
    }

    public function test_second_warehouse_stock_shortage_rolls_back_first_release_and_all_children(): void
    {
        [$actor, $order, $products, $warehouses, $holds] = $this->setupSplit(1);
        $revision = app(OrderAmendmentRevision::class)->forOrder($order);
        $originalItems = $order->items()->orderBy('id')->pluck('sku')->all();
        try {
            $this->runReplacement($actor, $order, $products['C'], $revision);
            $this->fail('New SKU stock shortage must roll back all warehouses');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }
        $this->assertSame($originalItems, $order->items()->orderBy('id')->pluck('sku')->all());
        $this->assertSame($revision, app(OrderAmendmentRevision::class)->forOrder($order));
        foreach ($holds as $hold) {
            $this->assertDatabaseHas('inventory_reservations', ['id' => $hold->id, 'status' => 'reserved']);
        }
        $this->assertSame(2, (int) WarehouseInventory::query()
            ->where('product_id', $products['A']->id)->value('quantity_reserved'));
        $this->assertSame(1, (int) WarehouseInventory::query()
            ->where('product_id', $products['B']->id)->value('quantity_reserved'));
    }

    public function test_changed_child_item_invalidates_revision_before_releasing_any_hold(): void
    {
        [$actor, $order, $products, $warehouses, $holds] = $this->setupSplit();
        $revision = app(OrderAmendmentRevision::class)->forOrder($order);
        $child = $order->fulfillmentChildren()->firstOrFail();
        $child->items()->firstOrFail()->update(['quantity' => 8]);
        try {
            $this->runReplacement($actor, $order, $products['C'], $revision);
            $this->fail('Changed child SKU or quantity should invalidate preview');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        foreach ($holds as $hold) {
            $this->assertDatabaseHas('inventory_reservations', ['id' => $hold->id, 'status' => 'reserved']);
        }
    }

    public function test_committed_leg_blocks_every_pending_leg_without_releasing_other_stock(): void
    {
        [$actor, $order, $products, $warehouses, $holds] = $this->setupSplit();
        $committed = reset($holds);
        app(InventoryReservationService::class)->commit($committed->id, $actor);
        $revision = app(OrderAmendmentRevision::class)->forOrder($order);
        try {
            $this->runReplacement($actor, $order, $products['C'], $revision);
            $this->fail('A committed leg must never be rewritten');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseHas('inventory_reservations', ['id' => $committed->id, 'status' => 'committed']);
        foreach ($holds as $hold) {
            if ($hold->id === $committed->id) continue;
            $this->assertDatabaseHas('inventory_reservations', ['id' => $hold->id, 'status' => 'reserved']);
        }
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'sku' => 'MULTI-B']);
    }

    public function test_shipped_child_is_immutable_even_if_another_child_remains_pending(): void
    {
        [$actor, $order, $products, $warehouses, $holds] = $this->setupSplit();
        $child = $order->fulfillmentChildren()->firstOrFail();
        $child->shipment()->firstOrFail()->forceFill([
            'shipment_status' => 'shipped', 'tracking_number' => '1234567890',
            'shipped_at' => now(),
        ])->save();
        $revision = app(OrderAmendmentRevision::class)->forOrder($order);
        try {
            $this->runReplacement($actor, $order, $products['C'], $revision);
            $this->fail('Shipped child must remain immutable');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        foreach ($holds as $hold) {
            $this->assertDatabaseHas('inventory_reservations', ['id' => $hold->id, 'status' => 'reserved']);
        }
    }
}
