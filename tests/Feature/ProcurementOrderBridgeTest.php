<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Procurement\Infrastructure\Models\ProcurementOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ProcurementOrderBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_stockout_order_can_create_one_procurement_order_with_multiple_lines(): void
    {
        $user = $this->userWith(['orders.view', 'procurement.view', 'procurement.create']);
        $order = $this->order($user, 2);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $user->id, 'marked_at' => now()]);

        $response = $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, ['items' => [
            $order->items[0]->id => ['purchase_url' => 'https://shop.test/a', 'purchase_size' => 'M', 'purchase_quantity' => 3],
            $order->items[1]->id => ['purchase_url' => null, 'purchase_size' => null, 'purchase_quantity' => 1],
        ]]);

        $procurement = ProcurementOrder::query()->firstOrFail();
        $response->assertRedirect('/purchase/orders/'.$procurement->id);
        $this->assertMatchesRegularExpression('/^PO-\d{8}-\d{4}$/', $procurement->procurement_number);
        $this->assertSame($order->id, $procurement->source_order_id);
        $this->assertCount(2, $procurement->items);
        $this->assertDatabaseHas('procurement_order_items', ['source_order_item_id' => $order->items[0]->id, 'purchase_url' => 'https://shop.test/a', 'purchase_size' => 'M', 'purchase_quantity' => 3]);
    }

    public function test_non_stockout_order_cannot_create_procurement_order(): void
    {
        $user = $this->userWith(['orders.view', 'procurement.view', 'procurement.create']);
        $order = $this->order($user);
        $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, ['items' => [
            $order->items[0]->id => ['purchase_quantity' => 1],
        ]])->assertStatus(422);
        $this->assertDatabaseCount('procurement_orders', 0);
    }

    public function test_duplicate_submission_is_idempotent_and_create_requires_permission(): void
    {
        $user = $this->userWith(['orders.view', 'procurement.view', 'procurement.create']);
        $restricted = $this->userWith(['orders.view', 'procurement.view'], 'restricted-procurement');
        $order = $this->order($user);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $user->id, 'marked_at' => now()]);
        $payload = ['items' => [$order->items[0]->id => ['purchase_quantity' => 1]]];
        $this->actingAs($restricted)->post('/purchase/orders/from-order/'.$order->id, $payload)->assertForbidden();
        $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, $payload)->assertRedirect();
        $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, $payload)->assertRedirect();
        $this->assertDatabaseCount('procurement_orders', 1);
    }

    public function test_submitted_lines_must_belong_to_the_source_order_and_cover_all_items(): void
    {
        $user = $this->userWith(['orders.view', 'procurement.view', 'procurement.create']);
        $order = $this->order($user, 2);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $user->id, 'marked_at' => now()]);

        $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, ['items' => [
            $order->items[0]->id => ['purchase_quantity' => 1],
        ]])->assertStatus(422);

        $this->assertDatabaseCount('procurement_orders', 0);
    }

    public function test_procurement_detail_requires_view_permission(): void
    {
        $creator = $this->userWith(['orders.view', 'procurement.view', 'procurement.create']);
        $restricted = $this->userWith(['orders.view'], 'restricted-procurement-read');
        $order = $this->order($creator);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $creator->id, 'marked_at' => now()]);
        $this->actingAs($creator)->post('/purchase/orders/from-order/'.$order->id, ['items' => [
            $order->items[0]->id => ['purchase_quantity' => 1],
        ]]);
        $procurement = ProcurementOrder::query()->firstOrFail();

        $this->actingAs($restricted)->get('/purchase/orders/'.$procurement->id)->assertForbidden();
    }

    public function test_purchase_index_and_legacy_urls_use_the_canonical_purchase_contract(): void
    {
        $user = $this->userWith(['orders.view', 'procurement.view', 'procurement.create']);
        $order = $this->order($user);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $user->id, 'marked_at' => now()]);
        $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, ['items' => [
            $order->items[0]->id => ['purchase_quantity' => 1],
        ]]);
        $procurement = ProcurementOrder::query()->firstOrFail();

        $this->actingAs($user)->get('/purchase')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/Orders/Index')
            ->where('orders.0.id', $procurement->id)
            ->where('orders.0.procurement_number', $procurement->procurement_number)
            ->where('orders.0.source_order_erp_id', $order->erp_order_id));
        $this->actingAs($user)->get('/purchase/orders')->assertOk();
        $this->actingAs($user)->get('/procurement')->assertRedirect('/purchase');
        $this->actingAs($user)->get('/procurement-orders')->assertRedirect('/purchase/orders');
        $this->actingAs($user)->get('/procurement-orders/'.$procurement->id)->assertRedirect('/purchase/orders/'.$procurement->id);
        $this->actingAs($user)->get('/orders/'.$order->id.'/procurement/create')->assertRedirect('/purchase/orders/from-order/'.$order->id.'/create');
    }

    public function test_new_procurement_notifies_procurement_operator_once(): void
    {
        $creator = $this->userWith(['orders.view', 'procurement.view', 'procurement.create'], 'procurement-creator');
        $operator = $this->userWith(['procurement.view', 'procurement.operate'], 'procurement-operator');
        $order = $this->order($creator);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $creator->id, 'marked_at' => now()]);
        $payload = ['items' => [$order->items[0]->id => ['purchase_quantity' => 1]]];
        $this->actingAs($creator)->post('/purchase/orders/from-order/'.$order->id, $payload)->assertRedirect();
        $this->actingAs($creator)->post('/purchase/orders/from-order/'.$order->id, $payload)->assertRedirect();
        $notification = $operator->notifications()->sole();
        $this->assertSame('新的采购订单', $notification->data['title']);
        $this->assertSame('/purchase/orders/1', $notification->data['action_url']);
        $this->assertSame('procurement.operate', $notification->data['required_permission']);
    }

    public function test_procurement_operator_can_update_status_and_tracking_but_viewer_cannot(): void
    {
        $creator = $this->userWith(['orders.view', 'procurement.view', 'procurement.create']);
        $viewer = $this->userWith(['procurement.view'], 'procurement-viewer');
        $operator = $this->userWith(['procurement.view', 'procurement.operate'], 'procurement-operator-update');
        $order = $this->order($creator);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $creator->id, 'marked_at' => now()]);
        $this->actingAs($creator)->post('/purchase/orders/from-order/'.$order->id, ['items' => [$order->items[0]->id => ['purchase_quantity' => 1]]]);
        $procurement = ProcurementOrder::query()->firstOrFail();
        $payload = ['status' => 'ordered', 'carrier' => 'SF Express', 'tracking_number' => 'SF123456789'];
        $this->actingAs($viewer)->patch('/purchase/orders/'.$procurement->id, $payload)->assertForbidden();
        $this->actingAs($operator)->patch('/purchase/orders/'.$procurement->id, $payload)->assertRedirect();
        $procurement->refresh();
        $this->assertSame('ordered', $procurement->status);
        $this->assertSame('SF Express', $procurement->carrier);
        $this->assertSame('SF123456789', $procurement->tracking_number);
        $this->assertSame($operator->id, $procurement->updated_by);
        $this->assertNotNull($procurement->status_updated_at);
    }

    public function test_operator_can_set_all_frozen_statuses_and_invalid_status_is_rejected(): void
    {
        $creator = $this->userWith(['orders.view', 'procurement.view', 'procurement.create']);
        $operator = $this->userWith(['procurement.view', 'procurement.operate'], 'procurement-status-operator');
        $order = $this->order($creator);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $creator->id, 'marked_at' => now()]);
        $this->actingAs($creator)->post('/purchase/orders/from-order/'.$order->id, ['items' => [$order->items[0]->id => ['purchase_quantity' => 1]]]);
        $procurement = ProcurementOrder::query()->firstOrFail();
        foreach (['pending', 'purchasing', 'ordered', 'completed', 'cancelled'] as $status) {
            $this->actingAs($operator)->patch('/purchase/orders/'.$procurement->id, ['status' => $status])->assertRedirect();
            $this->assertSame($status, $procurement->fresh()->status);
        }
        $this->actingAs($operator)->patch('/purchase/orders/'.$procurement->id, ['status' => 'in_transit'])->assertSessionHasErrors('status');
    }

    public function test_procurement_only_user_login_lands_on_purchase_workspace(): void
    {
        $user = $this->userWith(['procurement.view', 'procurement.operate']);
        $user->forceFill(['email' => 'procurement-login@example.test', 'password' => bcrypt('password')])->save();

        $this->post('/login', ['email' => 'procurement-login@example.test', 'password' => 'password'])
            ->assertRedirect('/purchase');
        $this->assertAuthenticatedAs($user);
    }

    public function test_purchase_index_supports_status_and_search_filters(): void
    {
        $user = $this->userWith(['orders.view', 'procurement.view', 'procurement.create', 'procurement.operate']);
        foreach ([['MATCH-ORDER', 'SPECIAL-SKU'], ['OTHER-ORDER', 'OTHER-SKU']] as [$number, $sku]) {
            $order = $this->order($user); $order->external_order_number = $number; $order->save();
            $order->items()->first()->update(['sku' => $sku]);
            $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $user->id, 'marked_at' => now()]);
            $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, ['items' => [$order->items[0]->id => ['purchase_quantity' => 1]]]);
        }
        $second = ProcurementOrder::query()->orderBy('id')->skip(1)->firstOrFail();
        $this->actingAs($user)->patch('/purchase/orders/'.$second->id, ['status' => 'ordered']);
        $this->actingAs($user)->get('/purchase?search=SPECIAL-SKU')->assertInertia(fn (Assert $page) => $page->has('orders', 1)->where('orders.0.source_order_erp_id', fn ($value): bool => is_string($value) && str_starts_with($value, '007-')));
        $this->actingAs($user)->get('/purchase?status=ordered')->assertInertia(fn (Assert $page) => $page->has('orders', 1)->where('orders.0.id', $second->id));
    }

    public function test_source_order_marked_shipped_auto_completes_active_procurement(): void
    {
        $user = $this->userWith(['orders.view', 'procurement.view', 'procurement.create', 'procurement.operate']);
        $order = $this->order($user);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $user->id, 'marked_at' => now()]);
        $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, ['items' => [$order->items[0]->id => ['purchase_quantity' => 1]]]);
        $procurement = ProcurementOrder::query()->firstOrFail();
        $this->actingAs($user)->patch('/purchase/orders/'.$procurement->id, ['status' => 'ordered']);

        $this->app->make(ShipmentService::class)->markShipped($order, [
            'carrier' => 'DHL', 'tracking_number' => 'SHIP-PO-1', 'shipped_at' => now(),
        ], $user);

        $procurement->refresh();
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->order_status);
        $this->assertSame('completed', $procurement->status);
        $this->assertSame('source_order_shipped', $procurement->completion_reason);
        $this->assertNotNull($procurement->completed_at);
        $this->assertNotNull($procurement->status_updated_at);
    }

    public function test_shipped_source_order_preserves_cancelled_and_manual_completed_procurement(): void
    {
        $user = $this->userWith(['orders.view', 'procurement.view', 'procurement.create', 'procurement.operate']);
        foreach ([['cancelled', null], ['completed', 'manual']] as [$targetStatus, $expectedReason]) {
            $order = $this->order($user);
            $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $user->id, 'marked_at' => now()]);
            $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, ['items' => [$order->items[0]->id => ['purchase_quantity' => 1]]]);
            $procurement = ProcurementOrder::query()->where('source_order_id', $order->id)->firstOrFail();
            $this->actingAs($user)->patch('/purchase/orders/'.$procurement->id, ['status' => $targetStatus]);
            $beforeCompletedAt = $procurement->fresh()->completed_at;
            $order->forceFill(['order_status' => OrderStatus::Shipped])->save();
            $procurement->refresh();
            $this->assertSame($targetStatus, $procurement->status);
            $this->assertSame($expectedReason, $procurement->completion_reason);
            $this->assertEquals($beforeCompletedAt, $procurement->completed_at);
        }
    }

    public function test_manual_completion_fields_are_set_and_cleared_when_reopened(): void
    {
        $user = $this->userWith(['orders.view', 'procurement.view', 'procurement.create', 'procurement.operate']);
        $order = $this->order($user);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $user->id, 'marked_at' => now()]);
        $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, ['items' => [$order->items[0]->id => ['purchase_quantity' => 1]]]);
        $procurement = ProcurementOrder::query()->firstOrFail();
        $this->actingAs($user)->patch('/purchase/orders/'.$procurement->id, ['status' => 'completed']);
        $procurement->refresh();
        $this->assertSame('manual', $procurement->completion_reason);
        $this->assertNotNull($procurement->completed_at);
        $this->actingAs($user)->patch('/purchase/orders/'.$procurement->id, ['status' => 'purchasing']);
        $procurement->refresh();
        $this->assertNull($procurement->completion_reason);
        $this->assertNull($procurement->completed_at);
    }

    public function test_dashboard_procurement_progress_is_permission_scoped(): void
    {
        $viewer = $this->userWith(['dashboard.view', 'procurement.view'], 'dashboard-procurement-viewer');
        $dashboardOnly = $this->userWith(['dashboard.view'], 'dashboard-only-viewer');
        foreach ([['pending', 'PO-DASH-1'], ['ordered', 'PO-DASH-2'], ['completed', 'PO-DASH-3'], ['cancelled', 'PO-DASH-4']] as [$status, $number]) {
            $order = $this->order($viewer);
            ProcurementOrder::create(['procurement_number' => $number, 'source_order_id' => $order->id, 'status' => $status, 'created_by' => $viewer->id]);
        }
        $this->actingAs($viewer)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('procurementProgress.active', 2)
            ->where('procurementProgress.counts.pending', 1)
            ->where('procurementProgress.counts.ordered', 1)
            ->where('procurementProgress.counts.completed', 1)
            ->where('procurementProgress.counts.cancelled', 1)
            ->has('procurementProgress.recent', 4));
        $this->actingAs($dashboardOnly)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('procurementProgress', null));
    }

    private function order(User $owner, int $count = 1): Order
    {
        $order = Order::create(['internal_order_number' => 'INT-'.uniqid(), 'erp_order_id' => sprintf('007-0908-c%04d', Order::query()->count() + 1), 'source_type' => OrderSourceType::Manual, 'order_kind' => OrderKind::Customer, 'external_order_number' => 'ORDER-'.uniqid(), 'order_date' => now()->toDateString(), 'owner_id' => $owner->id, 'order_status' => OrderStatus::PendingShipment]);
        for ($i = 1; $i <= $count; $i++) {
            $quantity = $i;
            $unitPrice = '10.00';
            $order->items()->create(['sku' => 'SKU-'.$i, 'product_summary' => 'Product '.$i, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'line_revenue' => number_format((float) $unitPrice * $quantity, 2, '.', '')]);
        }
        return $order;
    }

    private function userWith(array $permissions, ?string $roleKey = null): User
    {
        $role = Role::create(['key' => $roleKey ?? 'procurement-'.uniqid(), 'label' => 'Procurement Test Role']);
        foreach ($permissions as $permission) $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
