<?php

namespace Tests\Feature\Mvp1;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Finance\Application\OrderAnalysisService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Sync\Application\ManualSyncStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class Mvp1WorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_order_fulfillment_and_sync_are_atomic_and_audited(): void
    {
        $user = $this->userWith(['dashboard.view', 'orders.view', 'orders.create', 'orders.edit', 'logistics.view', 'logistics.operate', 'sync.view', 'sync.operate']);
        $payload = $this->orderPayload();

        $response = $this->actingAs($user)->post('/customers/orders', $payload);
        $order = Order::query()->firstOrFail();

        $response->assertRedirect('/customers/orders/'.$order->id);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'source_type' => 'manual', 'order_status' => 'pending_shipment']);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 2, 'line_revenue' => '39.98']);
        $this->assertDatabaseHas('audit_records', ['action' => 'order.created', 'entity_id' => (string) $order->id]);

        $carrier = Carrier::query()->firstOrCreate(['name' => 'Synthetic Carrier'], ['is_active' => true]);
        $warehouse = Warehouse::query()->where('is_active', true)->orderBy('priority')->firstOrFail();
        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/ship', [
            'warehouse_id' => $warehouse->id, 'carrier' => $carrier->name, 'service' => 'Ground',
            'tracking_number' => 'SYNTH-001', 'shipped_at' => '2026-08-05T08:00:00Z',
        ])->assertRedirect();
        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/ship', [
            'warehouse_id' => $warehouse->id, 'carrier' => $carrier->name, 'service' => 'Ground',
            'tracking_number' => 'SYNTH-002', 'shipped_at' => '2026-08-05T09:00:00Z',
        ])->assertRedirect();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_status' => 'shipped']);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'tracking_number' => 'SYNTH-002']);
        $this->assertDatabaseCount('manual_syncs', 1);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'pending']);

        $this->actingAs($user)->post('/sync/orders/'.$order->id.'/complete', ['sync_note' => 'Synthetic external update'])->assertRedirect();
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'completed', 'synced_by' => $user->id]);
        $this->assertDatabaseHas('audit_records', ['action' => 'manual_sync.completed']);

        $this->actingAs($user)->post('/sync/orders/'.$order->id.'/reopen', ['sync_note' => 'Correction'])->assertRedirect();
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'pending', 'synced_by' => null]);
        $this->actingAs($user)->post('/sync/orders/'.$order->id.'/return', ['sync_note' => 'Returned for correction'])->assertRedirect();
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'returned']);
        $this->assertDatabaseHas('audit_records', ['action' => 'manual_sync.reopened']);
        $this->assertDatabaseHas('audit_records', ['action' => 'manual_sync.returned']);
    }

    public function test_order_correction_updates_items_and_address_in_one_audited_request(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload())->assertRedirect();
        $order = Order::query()->firstOrFail();

        $corrProduct = Product::firstOrCreate(['sku' => 'COR-1'], ['name' => 'Corrected Product', 'product_type' => 'simple', 'woocommerce_product_id' => 7002]);
        $this->actingAs($user)->patch('/customers/orders/'.$order->id, [
            'recipient_name' => 'Corrected Recipient',
            'address_line_1' => 'Corrected Address',
            'items' => [['product_id' => $corrProduct->id, 'product_summary' => 'Corrected Product', 'sku' => 'COR-1', 'quantity' => 3, 'unit_price' => '12.00']],
        ])->assertRedirect();

        $order->refresh()->load(['address', 'items']);
        $this->assertSame('Corrected Recipient', $order->address->recipient_name);
        $this->assertSame('Corrected Product', $order->items->first()->product_summary);
        $this->assertSame(3, $order->items->first()->quantity);
        $this->assertSame('36.00', $order->items->first()->line_revenue);
        $this->assertDatabaseHas('audit_records', ['action' => 'order.updated', 'entity_id' => (string) $order->id]);
    }

    public function test_analysis_requires_all_costs_before_confirming_profit_and_exports(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'analysis.view']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload())->assertRedirect();
        $order = Order::query()->firstOrFail();
        $this->actingAs($user)->get('/accounting/order-costs')->assertInertia(fn (Assert $page) => $page->where('orders.0.summary.profit_status', 'not_confirmed')->where('orders.0.summary.completeness', 'incomplete'));

        $costs = array_fill_keys(['product_cost', 'warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost', 'kol_commission', 'payment_fee', 'customs_tax', 'refund_replacement_loss', 'other_variable_cost'], '1.00');
        $this->actingAs($user)->put('/accounting/order-costs/'.$order->id, ['amount_paid' => '100.00', 'refund_amount' => '0.00', ...$costs])->assertRedirect();
        $this->actingAs($user)->get('/accounting/order-costs')->assertInertia(fn (Assert $page) => $page->where('orders.0.summary.profit_status', 'confirmed')->where('orders.0.summary.contribution_profit', '90.00'));
        $this->actingAs($user)->get('/accounting/order-costs/export')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_order_profit_visibility_exposes_current_stage_profit_without_promoting_it_to_final_profit(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'analysis.view']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload())->assertRedirect();
        $order = Order::query()->firstOrFail();

        $this->actingAs($user)->put('/accounting/order-costs/'.$order->id, [
            'amount_paid' => '100.00',
            'refund_amount' => '0.00',
            'product_cost' => '20.00',
            'last_mile_cost' => '10.00',
            'payment_fee' => '3.00',
        ])->assertRedirect();

        $this->actingAs($user)->get('/customers/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page
            ->where('canViewProfit', true)
            ->missing('profitVisibility')
        );

        $this->actingAs($user)->get('/accounting/order-costs')->assertInertia(fn (Assert $page) => $page
            ->where('orders.0.summary.net_revenue', '100.00')
            ->where('orders.0.summary.known_cost', '33.00')
            ->where('orders.0.summary.current_stage_profit', '67.00')
            ->where('orders.0.summary.current_stage_margin', '0.6700')
            ->where('orders.0.summary.posted_cost_count', 3)
            ->where('orders.0.summary.required_cost_count', 10)
            ->where('orders.0.summary.profit_status', 'not_confirmed')
            ->where('orders.0.summary.contribution_profit', null)
        );
    }

    public function test_restricted_role_cannot_mutate_orders_fulfillment_or_sync(): void
    {
        $owner = $this->userWith(['orders.view', 'orders.create', 'orders.edit', 'logistics.view', 'logistics.operate', 'sync.view', 'sync.operate', 'analysis.view']);
        $this->actingAs($owner)->post('/customers/orders', $this->orderPayload())->assertRedirect();
        $order = Order::query()->firstOrFail();
        $restricted = $this->userWith(['orders.view']);
        $this->actingAs($restricted)->get('/customers/orders')->assertOk();
        $this->actingAs($restricted)->get('/customers/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('canViewProfit', false)->missing('profitVisibility'));
        $this->actingAs($restricted)->post('/customers/orders', $this->orderPayload())->assertForbidden();
        $this->actingAs($restricted)->get('/logistics/pending')->assertForbidden();
        $this->actingAs($restricted)->post('/logistics/orders/'.$order->id.'/reopen')->assertForbidden();
        $this->actingAs($restricted)->get('/sync')->assertForbidden();
        $this->actingAs($restricted)->get('/accounting/order-costs')->assertForbidden();
    }

    public function test_repeated_fulfillment_and_sync_operations_are_idempotent_and_reopen_is_audited(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.edit', 'logistics.view', 'logistics.operate', 'sync.view', 'sync.operate']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload())->assertRedirect();
        $order = Order::query()->firstOrFail();
        $carrier = Carrier::query()->firstOrCreate(['name' => 'Synthetic Carrier'], ['is_active' => true]);
        $warehouse = Warehouse::query()->where('is_active', true)->orderBy('priority')->firstOrFail();
        $shipmentPayload = [
            'warehouse_id' => $warehouse->id, 'carrier' => $carrier->name, 'service' => 'Ground',
            'tracking_number' => 'SYNTH-IDEMPOTENT', 'shipped_at' => '2026-08-05T08:00:00Z',
        ];

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/ship', $shipmentPayload)->assertRedirect();
        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/ship', $shipmentPayload)->assertRedirect();
        $this->assertDatabaseCount('manual_syncs', 1);
        $this->assertSame(1, \App\Modules\Audit\Infrastructure\Models\AuditRecord::query()->where('action', 'shipment.marked_shipped')->count());

        $this->actingAs($user)->post('/sync/orders/'.$order->id.'/complete', ['sync_note' => 'Same note'])->assertRedirect();
        $this->actingAs($user)->post('/sync/orders/'.$order->id.'/complete', ['sync_note' => 'Same note'])->assertRedirect();
        $this->assertSame(1, \App\Modules\Audit\Infrastructure\Models\AuditRecord::query()->where('action', 'manual_sync.completed')->count());

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/reopen', ['logistics_note' => 'Correction'])->assertRedirect();
        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/reopen', ['logistics_note' => 'Correction'])->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_status' => 'pending_shipment']);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'shipment_status' => 'pending', 'tracking_number' => null]);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'pending']);
        $this->assertSame(1, \App\Modules\Audit\Infrastructure\Models\AuditRecord::query()->where('action', 'shipment.reopened')->count());
        $this->assertSame(1, \App\Modules\Audit\Infrastructure\Models\AuditRecord::query()->where('action', 'manual_sync.reopened')->count());
    }

    public function test_analysis_preserves_decimal_precision_and_uses_shared_filters_for_csv(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'analysis.view']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload())->assertRedirect();
        $order = Order::query()->firstOrFail();
        $costs = array_fill_keys(['product_cost', 'warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost', 'kol_commission', 'payment_fee', 'customs_tax', 'refund_replacement_loss', 'other_variable_cost'], '0.01');

        $this->actingAs($user)->put('/accounting/order-costs/'.$order->id, ['amount_paid' => '0.30', 'refund_amount' => '0.00', ...$costs])->assertRedirect();
        $this->actingAs($user)->get('/accounting/order-costs?completeness=complete')->assertInertia(fn (Assert $page) => $page->where('orders.0.summary.net_revenue', '0.30')->where('orders.0.summary.total_cost', '0.10')->where('orders.0.summary.contribution_profit', '0.20')->where('orders.0.summary.margin', '0.6666'));
        $csv = $this->actingAs($user)->get('/accounting/order-costs/export?completeness=complete')->streamedContent();
        $this->assertStringContainsString('Margin', $csv);
        $this->assertStringContainsString('0.20', $csv);
    }

    public function test_manual_sync_cannot_complete_an_unshipped_order(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'sync.view', 'sync.operate']);
        $this->actingAs($user)->post('/customers/orders', $this->orderPayload())->assertRedirect();
        $order = Order::query()->firstOrFail();

        $this->actingAs($user)->post('/sync/orders/'.$order->id.'/complete', ['sync_note' => 'Should fail'])->assertStatus(500);
        $this->assertDatabaseMissing('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'completed']);
        $this->assertDatabaseMissing('audit_records', ['action' => 'manual_sync.completed']);
    }

    private function orderPayload(): array
    {
        $product = Product::firstOrCreate(['sku' => 'SYN-1'], ['name' => 'Synthetic Product', 'product_type' => 'simple', 'woocommerce_product_id' => 7001]);

        return [
            'order_kind' => 'customer',
            'source_type' => 'website', 'external_order_number' => 'SYNTH-WEB-'.uniqid(), 'order_date' => '2026-08-05',
            'recipient_name' => 'Synthetic Recipient', 'phone' => '+1 555 0100', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Los Angeles', 'address_line_1' => '100 Synthetic Street',
            'postal_code' => '90001', 'items' => [
                ['product_id' => $product->id, 'product_summary' => 'Synthetic Product', 'sku' => 'SYN-1', 'quantity' => 2, 'unit_price' => '19.99'],
            ],
        ];
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'mvp1-'.uniqid(), 'label' => 'MVP1 Test Role']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
