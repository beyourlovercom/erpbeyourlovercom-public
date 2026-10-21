<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KolLogisticsReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_specialist_sees_only_own_kol_tracking_and_no_customer_pii(): void
    {
        $emma = $this->userWith(['kol.view', 'orders.view']);
        $other = $this->userWith(['kol.view', 'orders.view']);
        $own = $this->order($emma, 'kol_fulfillment', 'emma');
        $foreign = $this->order($other, 'kol_fulfillment', 'foreign');
        $customer = $this->order($emma, 'customer', 'customer');

        $own->shipment()->create(['shipment_status' => 'shipped', 'carrier' => 'DHL', 'tracking_number' => 'EMMA-TRACK-1']);
        $foreign->shipment()->create(['shipment_status' => 'shipped', 'carrier' => 'DHL', 'tracking_number' => 'OTHER-SECRET-TRACK']);
        $customer->shipment()->create(['shipment_status' => 'shipped', 'carrier' => 'DHL', 'tracking_number' => 'CUSTOMER-SECRET-TRACK']);
        $own->address()->create(['recipient_name' => 'PRIVATE-RECIPIENT', 'country' => 'US']);

        $response = $this->actingAs($emma)->get('/kol/logistics');
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Orders/KolLogistics')
            ->where('isManager', false)
            ->where('orders.total', 1)
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $own->id)
            ->where('orders.data.0.tracking_number', 'EMMA-TRACK-1')
            ->missing('orders.data.0.address')
            ->missing('orders.data.0.recipient_name')
        );
        $response->assertDontSee('OTHER-SECRET-TRACK');
        $response->assertDontSee('CUSTOMER-SECRET-TRACK');
        $response->assertDontSee('PRIVATE-RECIPIENT');

        $this->actingAs($emma)->get('/kol/logistics?search=dbid:'.$foreign->id)
            ->assertOk()->assertInertia(fn ($page) => $page->where('orders.total', 0));
        $this->actingAs($emma)->get('/kol/logistics?search='.$foreign->erp_order_id)
            ->assertOk()->assertInertia(fn ($page) => $page->where('orders.total', 0));
        $this->actingAs($emma)->get('/kol/logistics?status=shipped')
            ->assertOk()->assertInertia(fn ($page) => $page->where('orders.total', 1));
        $this->actingAs($emma)->get('/logistics/wait-stock')->assertForbidden();
        $this->actingAs($emma)->get('/logistics/carriers')->assertForbidden();
        $this->actingAs($emma)->get('/logistics/orders/'.$foreign->id.'/payload')->assertForbidden();
        $this->actingAs($emma)->get('/logistics/china/export/4px')->assertForbidden();
    }

    public function test_manager_can_read_all_kol_fulfillments_without_customers(): void
    {
        $emma = $this->userWith(['kol.view', 'orders.view']);
        $manager = $this->userWith(['kol.view', 'orders.view', 'kol.views.manage']);
        $one = $this->order($emma, 'kol_fulfillment', 'emma');
        $two = $this->order($manager, 'kol_fulfillment', 'manager');
        $this->order($manager, 'customer', 'customer');

        $this->actingAs($manager)->get('/kol/logistics')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Orders/KolLogistics')
                ->where('isManager', true)
                ->where('orders.total', 2)
                ->has('orders.data', 2));
        $this->actingAs($manager)->get('/kol/logistics?search=dbid:'.$one->id)
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('orders.total', 1)
                ->where('orders.data.0.id', $one->id));
        $this->actingAs($manager)->get('/kol/logistics?search=dbid:'.$two->id)
            ->assertOk()->assertInertia(fn ($page) => $page->where('orders.total', 1));
        $this->actingAs($manager)->get('/logistics/china')->assertForbidden();
    }

    public function test_mixed_kol_and_logistics_rights_do_not_expand_owner_scope(): void
    {
        $logistics = $this->userWith(['logistics.view', 'orders.view']);
        $mixed = $this->userWith(['kol.view', 'logistics.view', 'orders.view']);
        $other = $this->userWith(['kol.view']);
        $stranger = $this->userWith(['dashboard.view']);
        $own = $this->order($mixed, 'kol_fulfillment', 'mixed-own');
        $foreign = $this->order($other, 'kol_fulfillment', 'mixed-foreign');

        $this->actingAs($logistics)->get('/kol/logistics')->assertForbidden();
        // Holding logistics.view does not make a KOL specialist a team manager.
        $this->actingAs($mixed)->get('/kol/logistics')->assertOk()
            ->assertInertia(fn ($page) => $page->where('isManager', false)
                ->where('orders.total', 1)
                ->where('orders.data.0.id', $own->id));
        $this->actingAs($mixed)->get('/kol/logistics?search=dbid:'.$foreign->id)
            ->assertOk()->assertInertia(fn ($page) => $page->where('orders.total', 0));
        $this->actingAs($stranger)->get('/kol/logistics')->assertForbidden();
        auth()->logout();
        $this->get('/kol/logistics')->assertRedirect('/login');
    }

    public function test_operations_role_can_see_kol_team_but_not_customer_orders(): void
    {
        $operations = $this->userWith(['kol.view', 'kol.views.manage', 'logistics.view']);
        $emma = $this->userWith(['kol.view']);
        $this->order($emma, 'kol_fulfillment', 'ops-emma');
        $this->order($operations, 'customer', 'ops-customer');
        $this->actingAs($operations)->get('/kol/logistics')->assertOk()
            ->assertInertia(fn ($page) => $page->where('isManager', true)
                ->where('orders.total', 1));
    }

    public function test_search_and_status_cannot_leak_foreign_order_counts(): void
    {
        $emma = $this->userWith(['kol.view']);
        $other = $this->userWith(['kol.view']);
        $own = $this->order($emma, 'kol_fulfillment', 'own');
        $foreign = $this->order($other, 'kol_fulfillment', 'foreign');
        $own->shipment()->create(['shipment_status' => 'pending']);
        $foreign->shipment()->create(['shipment_status' => 'exception', 'tracking_number' => 'FOREIGN-EXCEPTION']);

        $this->actingAs($emma)->get('/kol/logistics?status=exception')
            ->assertOk()->assertInertia(fn ($page) => $page->where('orders.total', 0));
        $this->actingAs($emma)->get('/kol/logistics?status=pending')
            ->assertOk()->assertInertia(fn ($page) => $page->where('orders.total', 1));
        $this->actingAs($emma)->get('/kol/logistics?search='.$foreign->external_order_number)
            ->assertOk()->assertInertia(fn ($page) => $page->where('orders.total', 0));
    }

    private function userWith(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'kol_logistics_uat_'.uniqid(), 'label' => 'KOL logistics test']);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function order(User $owner, string $kind, string $suffix): Order
    {
        return Order::create([
            'internal_order_number' => 'LOGI-'.strtoupper($suffix),
            'erp_order_id' => '007erp-1008-k'.random_int(100000, 999999),
            'source_type' => 'manual',
            'order_kind' => $kind,
            'external_order_number' => 'KOL-LOGISTICS-'.$suffix,
            'order_date' => '2026-10-08',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
            'owner_id' => $owner->id,
        ]);
    }
}
