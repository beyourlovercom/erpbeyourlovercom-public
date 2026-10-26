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
