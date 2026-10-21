<?php

namespace Tests\Feature\Orders;

use App\Models\Role;
use App\Modules\Finance\Application\Contracts\ConfirmedLossReader;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Kol\Infrastructure\Models\SavedView;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class OrderSavedViewsTest extends TestCase
{
    use RefreshDatabase;

    private const COST_TYPES = [
        'product_cost', 'warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost',
        'kol_commission', 'payment_fee', 'customs_tax', 'refund_replacement_loss', 'other_variable_cost',
    ];

    public function test_default_status_and_operational_marker_views_use_canonical_order_plus_explicit_markers(): void
    {
        $user = $this->userWith(['orders.view', 'orders.edit']);
        $pending = $this->order($user, 'PENDING', 'pending_shipment');
        $shipped = $this->order($user, 'SHIPPED', 'shipped');
        $exception = $this->order($user, 'EXCEPTION', 'pending_shipment');
        $transit = $this->order($user, 'TRANSIT', 'shipped');
        $returned = $this->order($user, 'RETURN', 'shipped');

        $this->mark($user, $exception, 'exception');
        $this->mark($user, $transit, 'in_transit');
        $this->mark($user, $returned, 'return');

        $this->assertViewContainsOnly($user, 'exception', 'ORDER-EXCEPTION');
        $this->assertViewContainsOnly($user, 'in_transit', 'ORDER-TRANSIT');
        $this->assertViewContainsOnly($user, 'return', 'ORDER-RETURN');

        $this->actingAs($user)->get('/orders?view=pending')->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 2)
            ->where('filters.view', 'pending'));
        $this->actingAs($user)->get('/orders?view=shipped')->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 3)
            ->where('filters.view', 'shipped'));

        $this->assertSame('pending_shipment', $pending->fresh()->order_status->value);
        $this->assertSame('shipped', $shipped->fresh()->order_status->value);
    }

    public function test_loss_view_reads_complete_finance_authority_and_does_not_create_loss_status(): void
    {
        $user = $this->userWith(['orders.view', 'logistics.view']);
        $loss = $this->order($user, 'LOSS', 'shipped');
        $profit = $this->order($user, 'PROFIT', 'shipped');
        $incomplete = $this->order($user, 'INCOMPLETE', 'shipped');
        $estimated = $this->order($user, 'ESTIMATED', 'shipped');

        $this->finance($loss, 10.00, 0.00, 2.00); // 10 revenue - 20 confirmed costs = -10
        $this->finance($profit, 100.00, 0.00, 2.00); // +80
        $incomplete->financials()->create(['amount_paid' => '1.00', 'refund_amount' => '0.00', 'currency' => 'USD']);
        $this->finance($estimated, 10.00, 0.00, 2.00);
        $estimated->costEntries()->where('cost_type', 'product_cost')->update(['provenance' => 'estimated_reference', 'status' => 'estimated']);

        $this->assertViewContainsOnly($user, 'loss', 'ORDER-LOSS');
        // The canonical confirmed-loss list is the Orders saved view, not the
        // removed Logistics loss route/status-bar slot.
        $this->actingAs($user)->get('/orders?view=loss')->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)
            ->where('orders.total', 1)
            ->where('orders.data.0.external_order_number', 'ORDER-LOSS')
            ->where('filters.view', 'loss'));
        $this->assertDatabaseMissing('order_operational_markers', ['order_id' => $loss->id, 'marker' => 'loss']);
        $this->assertSame('shipped', $loss->fresh()->order_status->value);
    }

    public function test_confirmed_loss_reader_supports_authoritative_woo_revenue_and_excludes_unsettled_woo(): void
    {
        $user = $this->userWith(['orders.view']);
        $wooLoss = $this->order($user, 'WOO-LOSS', 'shipped');
        $unsettled = $this->order($user, 'WOO-UNSETTLED', 'shipped');

        $this->wooFinance($wooLoss, paidAt: true);
        $this->wooFinance($unsettled, paidAt: false);

        /** @var ConfirmedLossReader $reader */
        $reader = app(ConfirmedLossReader::class);
        $query = Order::query();
        $ids = $reader->applyConfirmedLossScope($query)->pluck('id')->all();

        $this->assertTrue($reader->isConfirmedLoss($wooLoss->fresh(['financials', 'costEntries'])));
        $this->assertFalse($reader->isConfirmedLoss($unsettled->fresh(['financials', 'costEntries'])));
        $this->assertContains($wooLoss->id, $ids);
        $this->assertNotContains($unsettled->id, $ids);
    }

    public function test_overweight_view_requires_confirmed_chargeable_weight_and_explicit_domestic_warehouse_config(): void
    {
        $user = $this->userWith(['orders.view']);
        $warehouses = Warehouse::query()->orderBy('id')->take(2)->get();
        $this->assertCount(2, $warehouses);
        $domestic = $warehouses[0];
        $foreign = $warehouses[1];
        $domestic->update(['is_domestic' => true]);
        $foreign->update(['is_domestic' => false]);

        $heavyDomestic = $this->order($user, 'HEAVY-DOM', 'shipped');
        $heavyForeign = $this->order($user, 'HEAVY-FOR', 'shipped');
        $lightDomestic = $this->order($user, 'LIGHT-DOM', 'shipped');
        $this->shipment($heavyDomestic, $domestic, 2.100);
        $this->shipment($heavyForeign, $foreign, 3.000);
        $this->shipment($lightDomestic, $domestic, 1.500);

        $this->assertViewContainsOnly($user, 'overweight', 'ORDER-HEAVY-DOM');
    }

    public function test_stockout_view_requires_manual_stockout_marker_and_selected_warehouse_shortage(): void
    {
        $user = $this->userWith(['orders.view', 'orders.edit']);
        $product = Product::create(['sku' => 'STOCKOUT-SKU', 'name' => 'Stockout Product', 'source_type' => 'manual']);
        $warehouses = Warehouse::query()->orderBy('id')->take(2)->get();
        $emptyWarehouse = $warehouses[0];
        $stockedWarehouse = $warehouses[1];
        WarehouseInventory::create(['warehouse_id' => $emptyWarehouse->id, 'product_id' => $product->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0]);
        WarehouseInventory::create(['warehouse_id' => $stockedWarehouse->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);

        $markedShort = $this->order($user, 'MARKED-SHORT', 'pending_shipment', $product);
        $unmarkedShort = $this->order($user, 'UNMARKED-SHORT', 'pending_shipment', $product);
        $markedStocked = $this->order($user, 'MARKED-STOCKED', 'pending_shipment', $product);
        $this->shipment($markedShort, $emptyWarehouse, null);
        $this->shipment($unmarkedShort, $emptyWarehouse, null);
        $this->shipment($markedStocked, $stockedWarehouse, null);
        $this->mark($user, $markedShort, 'stockout');
        $this->mark($user, $markedStocked, 'stockout');

        $this->assertViewContainsOnly($user, 'stockout', 'ORDER-MARKED-SHORT');
        $this->assertDatabaseMissing('order_operational_markers', ['order_id' => $unmarkedShort->id, 'marker' => 'stockout']);
    }

    public function test_order_saved_views_reuse_shared_saved_views_table_and_enforce_module_boundary(): void
    {
        $user = $this->userWith(['orders.view']);
        $this->actingAs($user)->post('/orders/views', [
            'name' => 'My Pending',
            'filters_json' => ['view' => 'pending', 'source_type' => 'manual'],
            'is_pinned' => true,
        ])->assertRedirect();

        $view = SavedView::query()->where('module', 'orders')->where('created_by', $user->id)->firstOrFail();
        $this->assertSame('private', $view->visibility);
        $this->assertSame('pending', $view->filters_json['view']);

        $this->actingAs($user)->get('/orders')->assertInertia(fn (Assert $page) => $page
            ->has('savedViews', 1)
            ->where('savedViews.0.id', $view->id)
            ->where('savedViews.0.name', 'My Pending'));

        $kolView = SavedView::create([
            'module' => 'kol', 'name' => 'KOL only', 'view_type' => 'custom', 'created_by' => $user->id,
            'visibility' => 'private', 'filters_json' => [], 'is_pinned' => false, 'is_default' => false, 'sort_order' => 0,
        ]);
        $this->actingAs($user)->delete("/orders/views/{$kolView->id}")->assertNotFound();
        $this->assertDatabaseHas('saved_views', ['id' => $kolView->id, 'module' => 'kol']);
    }

    public function test_only_order_editors_can_change_operational_markers(): void
    {
        $viewer = $this->userWith(['orders.view']);
        $order = $this->order($viewer, 'PERMISSION', 'pending_shipment');
        $this->actingAs($viewer)->post("/orders/{$order->id}/operational-markers/exception", ['active' => true])->assertForbidden();
        $this->assertDatabaseMissing('order_operational_markers', ['order_id' => $order->id]);
    }

    private function assertViewContainsOnly(User $user, string $view, string $externalOrderNumber): void
    {
        $this->actingAs($user)->get('/orders?view='.$view)->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.external_order_number', $externalOrderNumber)
            ->where('filters.view', $view));
    }

    private function order(User $user, string $suffix, string $status, ?Product $product = null): Order
    {
        $order = Order::create([
            'internal_order_number' => 'ERP-'.$suffix,
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'external_order_number' => 'ORDER-'.$suffix,
            'order_date' => '2026-08-28',
            'owner_id' => $user->id,
            'order_status' => $status,
            'currency' => 'USD',
        ]);
        $order->address()->create(['recipient_name' => 'Receiver '.$suffix, 'country' => 'US']);
        if ($product) {
            $order->items()->create(['product_id' => $product->id, 'sku' => $product->sku, 'product_summary' => $product->name, 'quantity' => 1, 'unit_price' => '10.00', 'line_revenue' => '10.00']);
        }
        return $order;
    }

    private function mark(User $user, Order $order, string $marker): void
    {
        $this->actingAs($user)->post("/orders/{$order->id}/operational-markers/{$marker}", ['active' => true])->assertRedirect();
    }

    private function finance(Order $order, float $paid, float $refund, float $eachCost): void
    {
        $order->financials()->create(['amount_paid' => number_format($paid, 2, '.', ''), 'refund_amount' => number_format($refund, 2, '.', ''), 'currency' => 'USD']);
        foreach (self::COST_TYPES as $type) {
            $order->costEntries()->create(['cost_type' => $type, 'amount' => number_format($eachCost, 2, '.', ''), 'currency' => 'USD']);
        }
    }

    private function wooFinance(Order $order, bool $paidAt): void
    {
        $order->financials()->create([
            'merchandise_list_amount' => '10.00',
            'discount_amount' => '0.00',
            'shipping_revenue' => '0.00',
            'refund_amount' => '0.00',
            'tax_collected' => '0.00',
            'provider_order_total' => '10.00',
            'currency' => 'USD',
            'paid_at' => $paidAt ? now() : null,
            'provider' => 'woocommerce',
            'provider_reference' => (string) $order->external_order_number,
            'provider_order_status' => 'completed',
            'revenue_provenance' => 'provider_import',
            'reconciliation_status' => 'matched',
        ]);
        foreach (self::COST_TYPES as $type) {
            $order->costEntries()->create(['cost_type' => $type, 'amount' => '2.00', 'currency' => 'USD']);
        }
    }

    private function shipment(Order $order, Warehouse $warehouse, ?float $chargeableWeight): void
    {
        $shipment = $order->shipment()->create([
            'warehouse_id' => $warehouse->id,
            'shipment_status' => $order->order_status->value === 'shipped' ? 'shipped' : 'pending',
            'carrier' => 'DHL',
            'service' => 'Test',
            'tracking_number' => 'TRACK-'.$order->id,
            'shipped_at' => $order->order_status->value === 'shipped' ? now() : null,
        ]);
        if ($chargeableWeight !== null) {
            $shipment->billingEvidence()->create(['chargeable_weight_kg' => $chargeableWeight, 'data_source' => 'test']);
        }
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'orders-'.uniqid(), 'label' => 'Orders Saved Views']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
