<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KolOrderOwnerScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_specialist_sees_only_own_kol_orders_and_cannot_bypass_via_generic_routes(): void
    {
        $emma = $this->userWith(['orders.view', 'orders.edit', 'kol.view', 'kol.operate']);
        $other = $this->userWith(['orders.view', 'kol.view', 'kol.operate']);
        $own = $this->order($emma, 'kol_fulfillment', 'own');
        $foreign = $this->order($other, 'kol_fulfillment', 'foreign');
        $customer = $this->order($emma, 'customer', 'customer');

        $this->actingAs($emma)->get('/kol/orders')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.total', 1)->where('orders.data.0.id', $own->id));
        $this->actingAs($emma)->get('/orders')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.total', 1));
        $this->actingAs($emma)->get('/kol/orders?search=dbid:'.$foreign->id)->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 0));
        $this->actingAs($emma)->get('/kol/orders/'.$own->id)->assertOk();
        $this->actingAs($emma)->get('/kol/orders/'.$foreign->id)->assertForbidden();
        $this->actingAs($emma)->patch('/kol/orders/'.$foreign->id, ['order_note' => 'unauthorized'])->assertForbidden();
        $this->actingAs($emma)->get('/orders/'.$foreign->id)->assertForbidden();
        $this->actingAs($emma)->get('/orders/'.$customer->id)->assertForbidden();
        $this->actingAs($emma)->get('/customers/orders/'.$customer->id)->assertForbidden();
        $this->actingAs($emma)->get('/customers/orders/create')->assertForbidden();
        $this->actingAs($emma)->post('/customers/orders', [])->assertForbidden();
    }

    public function test_manager_sees_all_kol_orders(): void
    {
        $emma = $this->userWith(['orders.view', 'kol.view']);
        $manager = $this->userWith(['orders.view', 'kol.view', 'kol.views.manage']);
        $this->order($emma, 'kol_fulfillment', 'emma');
        $this->order($manager, 'kol_fulfillment', 'manager');
        $customer = $this->order($manager, 'customer', 'customer-manager');

        $this->actingAs($manager)->get('/kol/orders')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 2)->where('orders.total', 2));
        $this->actingAs($manager)->get('/orders')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 2)->where('orders.total', 2));
        $this->actingAs($manager)->get('/orders/'.$customer->id)->assertForbidden();
        $this->actingAs($manager)->get('/customers/orders/create')->assertForbidden();
        $this->actingAs($manager)->post('/customers/orders', [])->assertForbidden();
    }

    public function test_manual_kol_order_is_allocated_canonical_erp_identity(): void
    {
        $actor = $this->userWith(['orders.view']);
        $order = app(OrderService::class)->create([
            'order_kind' => 'kol_fulfillment',
            'source_type' => 'manual',
            'external_order_number' => 'KOLMANUAL-TEST-007',
            'order_date' => '2026-10-08',
            'currency' => 'USD',
            'owner_id' => $actor->id,
        ], [], $actor, true);
        self::assertMatchesRegularExpression('/^007erp-1008-k9[0-9]+$/', $order->erp_order_id);
        self::assertSame($order->erp_order_id, $order->fresh()->erp_order_id);
        self::assertSame('KOLMANUAL-TEST-007', $order->external_order_number);
    }

    private function userWith(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'kol_uat_'.uniqid(), 'label' => 'KOL UAT']);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function order(User $owner, string $kind, string $suffix): Order
    {
        return Order::create([
            'internal_order_number' => 'KOL-'.strtoupper($suffix),
            'erp_order_id' => '007erp-1008-k'.random_int(100000, 999999),
            'source_type' => 'manual',
            'order_kind' => $kind,
            'external_order_number' => 'KOL-UAT-'.$suffix,
            'order_date' => '2026-10-08',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
            'owner_id' => $owner->id,
        ]);
    }
}
