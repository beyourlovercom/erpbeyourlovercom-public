<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Procurement\Infrastructure\Models\ProcurementOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PurchaseOsV1ClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_os_v1_closes_the_full_procurement_lifecycle(): void
    {
        $creator = $this->userWith(['orders.view', 'procurement.view', 'procurement.create'], 'purchase-v1-creator');
        $operator = $this->userWith(['dashboard.view', 'procurement.view', 'procurement.operate'], 'purchase-v1-operator');
        $order = $this->order($creator);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $creator->id, 'marked_at' => now()]);

        $this->actingAs($creator)->post('/purchase/orders/from-order/'.$order->id, [
            'items' => [$order->items[0]->id => [
                'purchase_url' => 'https://supplier.test/item',
                'purchase_size' => 'M',
                'purchase_quantity' => 2,
            ]],
        ])->assertRedirect();

        $procurement = ProcurementOrder::query()->sole();
        $notification = $operator->notifications()->sole();
        $this->assertSame('/purchase/orders/'.$procurement->id, $notification->data['action_url']);
        $this->assertNull($notification->read_at);

        $this->actingAs($operator)->post('/notifications/'.$notification->id.'/open')
            ->assertRedirect('/purchase/orders/'.$procurement->id);
        $this->assertNotNull($notification->fresh()->read_at);

        $this->actingAs($operator)->patch('/purchase/orders/'.$procurement->id, [
            'status' => 'ordered',
            'carrier' => 'SF Express',
            'tracking_number' => 'SF-PURCHASE-V1',
        ])->assertRedirect();

        $procurement->refresh();
        $this->assertSame('ordered', $procurement->status);
        $this->assertSame('SF Express', $procurement->carrier);
        $this->assertSame('SF-PURCHASE-V1', $procurement->tracking_number);

        $this->actingAs($operator)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('procurementProgress.active', 1)
            ->where('procurementProgress.counts.ordered', 1)
            ->where('procurementProgress.counts.completed', 0));

        $this->app->make(ShipmentService::class)->markShipped($order, [
            'carrier' => 'DHL',
            'tracking_number' => 'SHIP-PURCHASE-V1',
            'shipped_at' => now(),
        ], $creator);

        $procurement->refresh();
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->order_status);
        $this->assertSame('completed', $procurement->status);
        $this->assertSame('source_order_shipped', $procurement->completion_reason);
        $this->assertNotNull($procurement->completed_at);

        $this->actingAs($operator)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('procurementProgress.active', 0)
            ->where('procurementProgress.counts.ordered', 0)
            ->where('procurementProgress.counts.completed', 1));
    }

    private function order(User $owner): Order
    {
        $order = Order::query()->create([
            'internal_order_number' => 'INT-CLOSURE-'.uniqid(),
            'source_type' => OrderSourceType::Manual,
            'order_kind' => OrderKind::Customer,
            'external_order_number' => 'ORDER-CLOSURE-'.uniqid(),
            'order_date' => now()->toDateString(),
            'owner_id' => $owner->id,
            'order_status' => OrderStatus::PendingShipment,
        ]);
        $order->items()->create([
            'sku' => 'SKU-CLOSURE',
            'product_summary' => 'Purchase OS Closure Product',
            'quantity' => 1,
            'unit_price' => '10.00',
            'line_revenue' => '10.00',
        ]);
        return $order;
    }

    private function userWith(array $permissions, string $roleKey): User
    {
        $role = Role::query()->create(['key' => $roleKey.'-'.uniqid(), 'label' => 'Purchase OS V1 Closure']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
