<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InventoryReservationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserve_aggregates_order_lines_and_replay_is_idempotent(): void
    {
        $user = $this->userWith(['inventory.operate']);
        [$order, $warehouse, $product] = $this->scenario(10, [1, 2]);
        $expires = now()->addHour();

        $first = app(InventoryReservationService::class)->reserve($order->id, $warehouse->id, 'res-001', $expires, $user);
        $second = app(InventoryReservationService::class)->reserve($order->id, $warehouse->id, 'res-001', $expires, $user);

        $this->assertSame('reserved', $first['status']);
        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame(3, $first['items'][0]['quantity']);
        $this->assertSame(3, WarehouseInventory::query()->where('product_id', $product->id)->value('quantity_reserved'));
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseCount('inventory_reservation_items', 1);
        $this->assertSame(1, $this->auditCount('inventory.reservation.reserved'));
    }

    public function test_same_order_same_payload_reuses_active_reservation_across_retry_keys(): void
    {
        $user = $this->userWith(['inventory.operate']);
        [$order, $warehouse] = $this->scenario(10, [2]);
        $service = app(InventoryReservationService::class);
        $first = $service->reserve($order->id, $warehouse->id, 'res-active-1', null, $user);
        $second = $service->reserve($order->id, $warehouse->id, 'res-active-2', null, $user);

        $this->assertSame($first['id'], $second['id']);
        $this->assertTrue($second['idempotent']);
        $this->assertDatabaseCount('inventory_reservations', 1);
    }

    public function test_same_key_with_changed_payload_conflicts(): void
    {
        $user = $this->userWith(['inventory.operate']);
        [$order, $warehouse] = $this->scenario(10, [2]);
        $service = app(InventoryReservationService::class);
        $service->reserve($order->id, $warehouse->id, 'res-payload', null, $user);
        $order->items()->firstOrFail()->update(['quantity' => 3]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('different payload');
        $service->reserve($order->id, $warehouse->id, 'res-payload', null, $user);
    }

    public function test_reservation_requires_confirmed_full_inventory_authority(): void
    {
        $user = $this->userWith(['inventory.operate']);
        [$order, $warehouse] = $this->scenario(10, [2], false);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('confirmed full inventory authority');
        app(InventoryReservationService::class)->reserve($order->id, $warehouse->id, 'res-no-authority', null, $user);
    }

    public function test_insufficient_multi_product_stock_rolls_back_entire_reservation(): void
    {
        $user = $this->userWith(['inventory.operate']);
        $warehouse = $this->authoritativeWarehouse();
        $p1 = $this->product('RES-A');
        $p2 = $this->product('RES-B');
        $order = $this->order([[$p1, 2], [$p2, 5]]);
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $p1->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $p2->id, 'quantity_on_hand' => 4, 'quantity_reserved' => 0]);

        try {
            app(InventoryReservationService::class)->reserve($order->id, $warehouse->id, 'res-insufficient', null, $user);
            $this->fail('Expected insufficient inventory exception.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Insufficient available inventory', $e->getMessage());
        }

        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertSame(0, WarehouseInventory::query()->sum('quantity_reserved'));
    }

    public function test_release_restores_reserved_quantity_and_is_idempotent(): void
    {
        $user = $this->userWith(['inventory.operate']);
        [$order, $warehouse, $product] = $this->scenario(10, [4]);
        $service = app(InventoryReservationService::class);
        $reserved = $service->reserve($order->id, $warehouse->id, 'res-release', null, $user);
        $released = $service->release($reserved['id'], $user, 'order_changed');
        $replay = $service->release($reserved['id'], $user, 'order_changed');

        $this->assertSame('released', $released['status']);
        $this->assertFalse($released['idempotent']);
        $this->assertTrue($replay['idempotent']);
        $this->assertSame(0, WarehouseInventory::query()->where('product_id', $product->id)->value('quantity_reserved'));
        $this->assertSame(1, $this->auditCount('inventory.reservation.released'));
    }

    public function test_commit_consumes_on_hand_and_reserved_once(): void
    {
        $user = $this->userWith(['inventory.operate']);
        [$order, $warehouse, $product] = $this->scenario(10, [4]);
        $service = app(InventoryReservationService::class);
        $reserved = $service->reserve($order->id, $warehouse->id, 'res-commit', now()->addHour(), $user);
        $committed = $service->commit($reserved['id'], $user);
        $replay = $service->commit($reserved['id'], $user);
        $inventory = WarehouseInventory::query()->where('product_id', $product->id)->firstOrFail();

        $this->assertSame('committed', $committed['status']);
        $this->assertTrue($replay['idempotent']);
        $this->assertSame(6, $inventory->quantity_on_hand);
        $this->assertSame(0, $inventory->quantity_reserved);
        $this->assertSame(1, $this->auditCount('inventory.reservation.committed'));
        $this->assertDatabaseHas('inventory_adjustments', ['warehouse_inventory_id' => $inventory->id, 'reason' => 'reservation_committed', 'quantity_before' => 10, 'quantity_after' => 6, 'reserved_before' => 4, 'reserved_after' => 0]);
    }

    public function test_expire_only_when_due_and_restores_reserved_quantity(): void
    {
        Carbon::setTestNow('2026-09-05 12:00:00');
        $user = $this->userWith(['inventory.operate']);
        [$order, $warehouse, $product] = $this->scenario(10, [2]);
        $service = app(InventoryReservationService::class);
        $reserved = $service->reserve($order->id, $warehouse->id, 'res-expire', now()->addMinutes(30), $user);

        try {
            $service->expire($reserved['id'], $user);
            $this->fail('Expected not-due exception.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('not due', $e->getMessage());
        }

        Carbon::setTestNow('2026-09-05 12:31:00');
        $expired = $service->expire($reserved['id'], $user);
        $replay = $service->expire($reserved['id'], $user);
        $this->assertSame('expired', $expired['status']);
        $this->assertTrue($replay['idempotent']);
        $this->assertSame(0, WarehouseInventory::query()->where('product_id', $product->id)->value('quantity_reserved'));
        $this->assertSame(1, $this->auditCount('inventory.reservation.expired'));
        Carbon::setTestNow();
    }

    public function test_unresolved_order_item_fails_closed_before_inventory_mutation(): void
    {
        $user = $this->userWith(['inventory.operate']);
        $warehouse = $this->authoritativeWarehouse();
        $order = Order::create(['internal_order_number' => 'RES-UNRESOLVED', 'source_type' => 'manual', 'order_kind' => 'customer', 'order_date' => now()->toDateString(), 'order_status' => 'pending_shipment']);
        $order->items()->create(['product_id' => null, 'sku' => 'OLD', 'product_summary' => 'Historical', 'quantity' => 1, 'unit_price' => '1.00', 'line_revenue' => '1.00']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('canonical product identity');
        app(InventoryReservationService::class)->reserve($order->id, $warehouse->id, 'res-unresolved', null, $user);
    }

    public function test_logistics_operator_can_reserve_for_assisted_fulfillment(): void
    {
        $user = $this->userWith(['logistics.operate']);
        [$order, $warehouse, $product] = $this->scenario(10, [2]);
        $result = app(InventoryReservationService::class)->reserve($order->id, $warehouse->id, 'res-logistics', null, $user);

        $this->assertSame('reserved', $result['status']);
        $this->assertSame(2, WarehouseInventory::query()->where('product_id', $product->id)->value('quantity_reserved'));
    }

    public function test_inventory_operate_permission_is_required(): void
    {
        $user = $this->userWith(['inventory.view']);
        [$order, $warehouse] = $this->scenario(10, [1]);
        $this->expectException(AuthorizationException::class);
        app(InventoryReservationService::class)->reserve($order->id, $warehouse->id, 'res-forbidden', null, $user);
    }

    /** @return array{0:Order,1:Warehouse,2:Product} */
    private function scenario(int $qoh, array $lineQuantities, bool $authoritative = true): array
    {
        $warehouse = Warehouse::query()->where('slug', 'us-la')->firstOrFail();
        if ($authoritative) {
            $this->confirmFull($warehouse);
        }
        $product = $this->product('RES-'.uniqid());
        $order = $this->order(array_map(fn (int $q): array => [$product, $q], $lineQuantities));
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_on_hand' => $qoh, 'quantity_reserved' => 0]);

        return [$order, $warehouse, $product];
    }

    private function authoritativeWarehouse(): Warehouse
    {
        $warehouse = Warehouse::query()->where('slug', 'us-la')->firstOrFail();
        $this->confirmFull($warehouse);

        return $warehouse;
    }

    private function confirmFull(Warehouse $warehouse): void
    {
        InventorySyncBatch::create(['warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full', 'status' => 'confirmed', 'confirmed_at' => now(), 'stock_count_at' => now()]);
    }

    private function product(string $sku): Product
    {
        return Product::create(['sku' => $sku, 'name' => $sku, 'source_type' => 'manual', 'product_type' => 'simple']);
    }

    /** @param list<array{0:Product,1:int}> $rows */
    private function order(array $rows): Order
    {
        $order = Order::create(['internal_order_number' => 'RES-'.uniqid(), 'source_type' => 'manual', 'order_kind' => 'customer', 'order_date' => now()->toDateString(), 'order_status' => 'pending_shipment']);
        foreach ($rows as [$product, $quantity]) {
            $order->items()->create(['product_id' => $product->id, 'sku' => $product->sku, 'product_summary' => $product->name, 'quantity' => $quantity, 'unit_price' => '1.00', 'line_revenue' => number_format($quantity, 2, '.', '')]);
        }

        return $order;
    }

    private function auditCount(string $action): int
    {
        return (int) DB::table('audit_records')->where('action', $action)->count();
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'reservation-'.uniqid(), 'label' => 'Reservation Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);

        return $user;
    }
}
