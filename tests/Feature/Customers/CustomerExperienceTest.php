<?php

namespace Tests\Feature\Customers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerAddress;
use App\Modules\Customers\Infrastructure\Models\CustomerGroup;
use App\Modules\Marketing\Infrastructure\Models\MarketingSubscriber;
use App\Modules\Marketing\Infrastructure\Models\MarketingSubscription;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CustomerExperienceTest extends TestCase
{
    use RefreshDatabase;

    // ─── Dashboard ────────────────────────────────────────────────────────────

    public function test_customers_dashboard_shows_real_metrics(): void
    {
        $user = $this->userWith(['customers.view']);

        DB::table('customers')->insert(['name' => 'Old Customer', 'source_type' => 'manual', 'created_at' => now()->subYear(), 'updated_at' => now()->subYear()]);
        DB::table('customers')->insert(['name' => 'New This Month', 'source_type' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

        $customerId = DB::table('customers')->where('name', 'New This Month')->value('id');
        $orderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'DASH-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'DASH-EXT-1', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => $customerId,
            'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_summary' => 'P', 'sku' => 'S1', 'quantity' => 1,
            'unit_price' => 100, 'line_revenue' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Dashboard')
                ->where('metrics.total_customers', 2)
                ->where('metrics.new_this_month', 1)
                ->where('metrics.orders_this_month', 1)
                ->where('metrics.subscriber_identity_links', 0)
                ->has('metrics.revenue_this_month', 1)
                ->where('metrics.revenue_this_month.0.currency', 'USD')
                ->where('metrics.revenue_this_month.0.revenue', 100));
    }

    public function test_customers_dashboard_multi_currency_revenue_is_separated(): void
    {
        $user = $this->userWith(['customers.view']);
        $c = Customer::query()->create(['name' => 'MC', 'source_type' => 'manual']);

        foreach (['USD' => 200, 'EUR' => 150] as $currency => $amount) {
            $oid = DB::table('orders')->insertGetId([
                'internal_order_number' => "MC-{$currency}", 'source_type' => 'manual', 'order_kind' => 'customer',
                'external_order_number' => "MC-EXT-{$currency}", 'order_date' => now()->toDateString(),
                'order_status' => 'shipped', 'customer_id' => $c->id,
                'currency' => $currency, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('order_items')->insert([
                'order_id' => $oid, 'product_summary' => 'P', 'sku' => "S-{$currency}", 'quantity' => 1,
                'unit_price' => $amount, 'line_revenue' => $amount, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($user)->get('/customers')
            ->assertInertia(fn (Assert $page) => $page
                ->has('metrics.revenue_this_month', 2));
    }

    public function test_customer_dashboard_counts_only_active_customer_subscriber_identity_links(): void
    {
        $user = $this->userWith(['customers.view']);
        Customer::query()->create(['name' => 'Linked', 'email' => '  LINKED@example.test ', 'source_type' => 'manual']);
        Customer::query()->create(['name' => 'Archived', 'email' => 'archived@example.test', 'source_type' => 'manual', 'archived_at' => now()]);
        MarketingSubscriber::query()->create(['email' => 'linked@example.test']);
        MarketingSubscriber::query()->create(['email' => 'archived@example.test']);

        $this->actingAs($user)->get('/customers')->assertInertia(fn (Assert $page) => $page
            ->where('metrics.subscriber_identity_links', 1));
    }

    public function test_customer_subscriber_projection_keeps_provider_source_facts_separate(): void
    {
        $user = $this->userWith(['customers.view']);
        $customer = Customer::query()->create(['name' => 'Subscriber Customer', 'email' => ' Alice@example.test ', 'source_type' => 'manual']);
        $subscriber = MarketingSubscriber::query()->create(['email' => 'alice@example.test']);
        MarketingSubscription::query()->create(['subscriber_id' => $subscriber->id, 'provider' => 'mail-a', 'source_site' => 'site-a', 'status' => 'subscribed', 'consent_source' => 'provider-a', 'subscribed_at' => '2026-08-01 10:00:00', 'last_synced_at' => '2026-08-02 10:00:00']);
        MarketingSubscription::query()->create(['subscriber_id' => $subscriber->id, 'provider' => 'mail-b', 'source_site' => 'site-b', 'status' => 'unsubscribed', 'consent_source' => 'provider-b', 'unsubscribed_at' => '2026-08-03 10:00:00', 'status_updated_at' => '2026-08-03 10:00:00']);

        $this->actingAs($user)->get('/customers/library')->assertInertia(fn (Assert $page) => $page
            ->where('customers.data.0.subscription.matched', true)
            ->where('customers.data.0.subscription.subscription_statuses', ['subscribed', 'unsubscribed']));
        $this->actingAs($user)->get("/customers/library/{$customer->id}")->assertInertia(fn (Assert $page) => $page
            ->where('subscription.matched', true)
            ->where('subscription.subscription_facts.0.provider', 'mail-a')
            ->where('subscription.subscription_facts.1.status', 'unsubscribed')
            ->where('subscription.subscription_facts.1.consent_source', 'provider-b')
            ->where('subscription.subscription_facts.1.status_updated_at', '2026-08-03 10:00:00'));
    }

    public function test_customer_subscription_projection_has_honest_empty_states(): void
    {
        $user = $this->userWith(['customers.view']);
        $withoutEmail = Customer::query()->create(['name' => 'No Email', 'source_type' => 'manual']);
        $withoutFacts = Customer::query()->create(['name' => 'No Facts', 'email' => 'nofacts@example.test', 'source_type' => 'manual']);
        $this->assertDatabaseMissing('marketing_subscribers', ['email' => 'noemail@example.test']);
        MarketingSubscriber::query()->create(['email' => 'nofacts@example.test']);

        $this->actingAs($user)->get("/customers/library/{$withoutEmail->id}")->assertInertia(fn (Assert $page) => $page->where('subscription.matched', false));
        $this->actingAs($user)->get("/customers/library/{$withoutFacts->id}")->assertInertia(fn (Assert $page) => $page
            ->where('subscription.matched', true)->where('subscription.subscription_facts', []));
    }

    // ─── Addresses ────────────────────────────────────────────────────────────

    public function test_create_customer_with_billing_and_shipping_addresses(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);

        $this->actingAs($user)->post('/customers/library', [
            'name' => 'Addr Test', 'source_type' => 'manual',
            'billing_address' => [
                'name' => 'Bill To', 'city' => 'New York', 'country_code' => 'US',
                'address_line_1' => '1 Bill St', 'postal_code' => '10001',
                'company' => '', 'phone' => '', 'province_state' => '', 'address_line_2' => '',
            ],
            'shipping_address' => [
                'name' => 'Ship To', 'city' => 'Los Angeles', 'country_code' => 'US',
                'address_line_1' => '2 Ship Ave', 'postal_code' => '90001',
                'company' => '', 'phone' => '', 'province_state' => '', 'address_line_2' => '',
            ],
        ])->assertRedirect();

        $customer = Customer::query()->firstOrFail();
        $this->assertDatabaseHas('customer_addresses', ['customer_id' => $customer->id, 'address_type' => 'billing', 'city' => 'New York']);
        $this->assertDatabaseHas('customer_addresses', ['customer_id' => $customer->id, 'address_type' => 'shipping', 'city' => 'Los Angeles']);
    }

    public function test_edit_updates_addresses_transactionally(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $customer = Customer::query()->create(['name' => 'Edit Addr', 'source_type' => 'manual']);
        CustomerAddress::query()->create(['customer_id' => $customer->id, 'address_type' => 'billing', 'city' => 'OldCity']);

        $this->actingAs($user)->patch("/customers/library/{$customer->id}", [
            'name' => 'Edit Addr', 'source_type' => 'manual',
            'billing_address' => [
                'name' => '', 'city' => 'NewCity', 'country_code' => 'US',
                'address_line_1' => '', 'postal_code' => '', 'company' => '', 'phone' => '', 'province_state' => '', 'address_line_2' => '',
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('customer_addresses', ['customer_id' => $customer->id, 'address_type' => 'billing', 'city' => 'NewCity']);
    }

    public function test_show_includes_addresses_and_unique_constraint_enforced(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $customer = Customer::query()->create(['name' => 'Show Addr', 'source_type' => 'manual']);
        CustomerAddress::query()->create(['customer_id' => $customer->id, 'address_type' => 'billing', 'city' => 'Boston']);

        $this->actingAs($user)->get("/customers/library/{$customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Library/Show')
                ->where('billingAddress.city', 'Boston')
                ->where('shippingAddress', null));

        $this->expectException(\Illuminate\Database\QueryException::class);
        CustomerAddress::query()->create(['customer_id' => $customer->id, 'address_type' => 'billing', 'city' => 'Duplicate']);
    }

    // ─── Pagination ───────────────────────────────────────────────────────────

    public function test_customer_list_is_server_side_paginated(): void
    {
        $user = $this->userWith(['customers.view']);
        for ($i = 1; $i <= 3; $i++) {
            Customer::query()->create(['name' => "Customer {$i}", 'source_type' => 'manual']);
        }

        $this->actingAs($user)->get('/customers/library?page=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Library/Index')
                ->has('customers.data')
                ->has('customers.current_page')
                ->has('customers.last_page')
                ->has('customers.total'));
    }

    // ─── Shipment projection ──────────────────────────────────────────────────

    public function test_show_projects_shipments_through_customer_orders(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $customer = Customer::query()->create(['name' => 'Shipment Test', 'source_type' => 'manual']);

        $orderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'SHIP-001', 'erp_order_id' => '007-0908-cship', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'SHIP-EXT-1', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => $customer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new Shipment())->forceFill([
            'order_id' => $orderId, 'shipment_status' => 'shipped', 'carrier' => 'DHL',
            'tracking_number' => 'DHL-123', 'shipped_at' => now()->subHour(),
        ])->save();

        $this->actingAs($user)->get("/customers/library/{$customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('shipments', 1)
                ->where('shipments.0.erp_order_id', '007-0908-cship')
                ->where('shipments.0.carrier', 'DHL')
                ->where('shipments.0.tracking_number', 'DHL-123')
                ->where('metrics.shipment_count', 1));
    }

    public function test_unlinked_orders_do_not_contribute_shipments(): void
    {
        $user = $this->userWith(['customers.view']);
        $customer = Customer::query()->create(['name' => 'No Ship', 'source_type' => 'manual']);
        $other = Customer::query()->create(['name' => 'Other', 'source_type' => 'manual']);

        $otherOrderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'OTHER-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'OTHER-EXT-1', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => $other->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new Shipment())->forceFill([
            'order_id' => $otherOrderId, 'shipment_status' => 'shipped', 'carrier' => 'FedEx',
            'tracking_number' => 'FEDEX-999', 'shipped_at' => now()->subHour(),
        ])->save();

        $this->actingAs($user)->get("/customers/library/{$customer->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('shipments', 0)
                ->where('metrics.shipment_count', 0));
    }

    // ─── Library stats ────────────────────────────────────────────────────────

    public function test_library_index_exposes_real_stats_including_support_counts(): void
    {
        $user = $this->userWith(['customers.view']);
        $c = Customer::query()->create(['name' => 'Stats Test', 'source_type' => 'manual']);
        $orderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'STAT-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'STAT-EXT-1', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => $c->id,
            'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_summary' => 'P', 'sku' => 'S1', 'quantity' => 1,
            'unit_price' => 80, 'line_revenue' => 80, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers/library')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Library/Index')
                ->has('stats')
                ->where('stats.total_customers', 1)
                ->where('stats.new_this_month', 1)
                ->where('stats.orders_this_month', 1)
                ->has('stats.revenue_this_month', 1)
                ->where('stats.revenue_this_month.0.currency', 'USD')
                ->where('stats.revenue_this_month.0.revenue', 80)
                ->where('stats.subscriber_count_available', true)
                ->where('stats.subscriber_identity_links', 0)
                ->where('stats.pending_support_tickets', 0));
    }

    public function test_library_table_rows_include_lifetime_revenue_and_latest_order_date(): void
    {
        $user = $this->userWith(['customers.view']);
        $c = Customer::query()->create(['name' => 'Row Metrics', 'source_type' => 'manual']);
        $orderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'ROW-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'ROW-EXT-1', 'order_date' => '2026-07-15',
            'order_status' => 'shipped', 'customer_id' => $c->id,
            'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_summary' => 'P', 'sku' => 'S1', 'quantity' => 1,
            'unit_price' => 120, 'line_revenue' => 120, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers/library')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.data.0.latest_order_date', '2026-07-15')
                ->has('customers.data.0.lifetime_revenue', 1)
                ->where('customers.data.0.lifetime_revenue.0.currency', 'USD')
                ->where('customers.data.0.lifetime_revenue.0.revenue', 120));
    }

    public function test_library_row_lifetime_revenue_excludes_unlinked_orders(): void
    {
        $user = $this->userWith(['customers.view']);
        $c = Customer::query()->create(['name' => 'Linked Only', 'source_type' => 'manual']);
        DB::table('orders')->insert([
            'internal_order_number' => 'UNLINKED-ROW', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'UNLINKED-EXT', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => null,
            'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers/library')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.data.0.lifetime_revenue', [])
                ->where('customers.data.0.latest_order_date', null));
    }

    // ─── shipment_count independence from display limit ───────────────────────

    public function test_shipment_count_reflects_all_shipments_not_just_display_limit(): void
    {
        $user = $this->userWith(['customers.view']);
        $customer = Customer::query()->create(['name' => 'Many Ships', 'source_type' => 'manual']);

        // Create 25 orders each with one shipment (unique constraint on shipments.order_id)
        for ($i = 1; $i <= 25; $i++) {
            $orderId = DB::table('orders')->insertGetId([
                'internal_order_number' => "MANY-{$i}", 'source_type' => 'manual', 'order_kind' => 'customer',
                'external_order_number' => "MANY-EXT-{$i}", 'order_date' => now()->toDateString(),
                'order_status' => 'shipped', 'customer_id' => $customer->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            (new Shipment())->forceFill([
                'order_id' => $orderId, 'shipment_status' => 'shipped', 'carrier' => 'DHL',
                'tracking_number' => "DHL-{$i}", 'shipped_at' => now()->subMinutes($i),
            ])->save();
        }

        $this->actingAs($user)->get("/customers/library/{$customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.shipment_count', 25)
                ->has('shipments', 20)); // display capped at 20
    }

    // ─── Blank address deletion ───────────────────────────────────────────────

    public function test_submitting_blank_address_deletes_existing_record(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $customer = Customer::query()->create(['name' => 'Del Addr', 'source_type' => 'manual']);
        CustomerAddress::query()->create(['customer_id' => $customer->id, 'address_type' => 'billing', 'city' => 'ToDelete']);

        $this->assertDatabaseHas('customer_addresses', ['customer_id' => $customer->id, 'address_type' => 'billing']);

        $this->actingAs($user)->patch("/customers/library/{$customer->id}", [
            'name' => 'Del Addr', 'source_type' => 'manual',
            'billing_address' => [
                'name' => '', 'city' => '', 'country_code' => '',
                'address_line_1' => '', 'address_line_2' => '',
                'company' => '', 'phone' => '', 'province_state' => '', 'postal_code' => '',
            ],
        ])->assertRedirect();

        $this->assertDatabaseMissing('customer_addresses', ['customer_id' => $customer->id, 'address_type' => 'billing']);
    }

    // ─── Purchase history shipment status ─────────────────────────────────────

    public function test_recent_orders_include_shipment_status(): void
    {
        $user = $this->userWith(['customers.view']);
        $customer = Customer::query()->create(['name' => 'Ship Status Test', 'source_type' => 'manual']);

        $orderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'SS-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'SS-EXT-1', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => $customer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new Shipment())->forceFill([
            'order_id' => $orderId, 'shipment_status' => 'delivered', 'carrier' => 'UPS',
            'tracking_number' => 'UPS-123', 'shipped_at' => now()->subDay(),
        ])->save();

        $this->actingAs($user)->get("/customers/library/{$customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recentOrders', 1)
                ->where('recentOrders.0.shipment_status', 'delivered'));
    }

    public function test_shipment_segments_use_only_non_cancelled_customer_orders(): void
    {
        $user = $this->userWith(['customers.view']);
        $shipped = Customer::query()->create(['name' => 'Shipped', 'source_type' => 'manual']);
        $cancelledOnly = Customer::query()->create(['name' => 'Cancelled Only', 'source_type' => 'manual']);
        $unshipped = Customer::query()->create(['name' => 'Unshipped', 'source_type' => 'manual']);
        $neverOrdered = Customer::query()->create(['name' => 'Never Ordered', 'source_type' => 'manual']);

        $makeOrder = function (Customer $customer, string $status, string $number): int {
            return DB::table('orders')->insertGetId([
                'internal_order_number' => $number, 'source_type' => 'manual', 'order_kind' => 'customer',
                'external_order_number' => $number, 'order_date' => now()->toDateString(),
                'order_status' => $status, 'customer_id' => $customer->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $shippedOrder = $makeOrder($shipped, 'shipped', 'SEG-SHIPPED');
        (new Shipment())->forceFill(['order_id' => $shippedOrder, 'shipment_status' => 'shipped'])->save();
        $cancelledOrder = $makeOrder($cancelledOnly, 'cancelled', 'SEG-CANCELLED');
        (new Shipment())->forceFill(['order_id' => $cancelledOrder, 'shipment_status' => 'shipped'])->save();
        $makeOrder($unshipped, 'pending_shipment', 'SEG-PENDING');

        $this->actingAs($user)->get('/customers/library?group=has_shipment')->assertInertia(fn (Assert $page) => $page
            ->where('customers.total', 1)->where('customers.data.0.name', 'Shipped'));
        $this->actingAs($user)->get('/customers/library?group=no_shipment_yet')->assertInertia(fn (Assert $page) => $page
            ->where('customers.total', 3)
            ->where('customers.data', function ($rows): bool {
                return collect($rows)->pluck('name')->sort()->values()->all() === ['Cancelled Only', 'Never Ordered', 'Unshipped'];
            }));
    }

    public function test_customer_library_shipment_count_is_page_batched(): void
    {
        $user = $this->userWith(['customers.view']);
        $customer = Customer::query()->create(['name' => 'Batched Shipment Count', 'source_type' => 'manual']);
        $orderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'BATCH-SHIP', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'BATCH-SHIP', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => $customer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new Shipment())->forceFill(['order_id' => $orderId, 'shipment_status' => 'shipped'])->save();

        $this->actingAs($user)->get('/customers/library')->assertInertia(fn (Assert $page) => $page
            ->where('customers.data.0.shipment_count', 1));
    }

    // ─── Import/Export permissions ────────────────────────────────────────────

    public function test_import_export_buttons_visible_only_to_authorized_users(): void
    {
        $admin = $this->userWith(['customers.view', 'customers.import', 'customers.export', 'system.permissions.manage']);
        $regular = $this->userWith(['customers.view'], 'customer_reader2');

        $this->actingAs($admin)->get('/customers/library')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canImport', true)
                ->where('canExport', true));

        $this->actingAs($regular)->get('/customers/library')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canImport', false)
                ->where('canExport', false));
    }

    public function test_reserved_import_endpoint_requires_permission(): void
    {
        $noImport = $this->userWith(['customers.view', 'customers.operate'], 'no_import');
        $this->actingAs($noImport)->post('/customers/library/import')->assertForbidden();
    }

    public function test_reserved_export_endpoint_requires_permission(): void
    {
        $noExport = $this->userWith(['customers.view', 'customers.operate'], 'no_export');
        $this->actingAs($noExport)->get('/customers/library/export')->assertForbidden();
    }

    public function test_authorized_import_without_file_returns_validation_error(): void
    {
        $admin = $this->userWith(['customers.view', 'customers.operate', 'customers.import', 'system.permissions.manage'], 'has_import');
        $this->actingAs($admin)->post('/customers/library/import')->assertStatus(422);
    }

    public function test_authorized_export_returns_xlsx(): void
    {
        $admin = $this->userWith(['customers.view', 'customers.export', 'system.permissions.manage'], 'has_export');
        $this->actingAs($admin)->get('/customers/library/export')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_non_admin_presets_do_not_receive_import_export_by_default(): void
    {
        $salesUser = $this->userWith(['customers.view', 'customers.operate'], 'sales_no_import');

        $this->actingAs($salesUser)->get('/customers/library')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canImport', false)
                ->where('canExport', false));
    }

    // ─── Dashboard canImport/canExport ────────────────────────────────────────

    public function test_dashboard_shows_import_export_only_to_authorized(): void
    {
        $admin = $this->userWith(['customers.view', 'customers.import', 'customers.export', 'system.permissions.manage'], 'dash_admin');
        $viewer = $this->userWith(['customers.view'], 'dash_viewer');

        $this->actingAs($admin)->get('/customers')
            ->assertInertia(fn (Assert $page) => $page->where('canImport', true)->where('canExport', true));

        $this->actingAs($viewer)->get('/customers')
            ->assertInertia(fn (Assert $page) => $page->where('canImport', false)->where('canExport', false));
    }

    // ─── Administrator-only gate: import/export requires system.permissions.manage ──

    public function test_import_export_buttons_false_without_system_permissions_manage(): void
    {
        // User has customers.import/export but lacks system.permissions.manage
        $user = $this->userWith(['customers.view', 'customers.import', 'customers.export'], 'import_export_no_admin');

        $this->actingAs($user)->get('/customers/library')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canImport', false)
                ->where('canExport', false));

        $this->actingAs($user)->get('/customers')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canImport', false)
                ->where('canExport', false));
    }

    public function test_import_endpoint_403_when_lacking_system_permissions_manage(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import'], 'import_no_admin_gate');
        $this->actingAs($user)->post('/customers/library/import')->assertForbidden();
    }

    public function test_export_endpoint_403_when_lacking_system_permissions_manage(): void
    {
        $user = $this->userWith(['customers.view', 'customers.export'], 'export_no_admin_gate');
        $this->actingAs($user)->get('/customers/library/export')->assertForbidden();
    }

    public function test_import_endpoint_validation_error_when_both_permissions_present_without_file(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import', 'system.permissions.manage'], 'import_both_gates');
        $this->actingAs($user)->post('/customers/library/import')->assertStatus(422);
    }

    public function test_export_endpoint_succeeds_when_both_permissions_present(): void
    {
        $user = $this->userWith(['customers.view', 'customers.export', 'system.permissions.manage'], 'export_both_gates');
        $this->actingAs($user)->get('/customers/library/export')->assertOk();
    }

    // ─── RMB estimate in library stats payload ────────────────────────────────

    public function test_library_stats_includes_rmb_estimate_payload(): void
    {
        Cache::forget('ecb_fx_snapshot');
        Http::fake(['https://www.ecb.europa.eu/*' => Http::response(
            $this->ecbXml(['CNY' => '7.8', 'USD' => '1.1']), 200
        )]);

        $user = $this->userWith(['customers.view'], 'rmb_test_user');
        DB::table('finance_fx_manual_rates')->insert([
            'currency' => 'USD', 'manual_rate_to_cny' => 6.78, 'use_auto' => 0,
            'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c = Customer::query()->create(['name' => 'RMB Test', 'source_type' => 'manual']);
        $oid = DB::table('orders')->insertGetId([
            'internal_order_number' => 'RMB-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'RMB-EXT-1', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => $c->id,
            'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $oid, 'product_summary' => 'P', 'sku' => 'S-RMB', 'quantity' => 1,
            'unit_price' => 110, 'line_revenue' => 110, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers/library')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('stats.rmb_estimate')
                ->where('stats.rmb_estimate.available', true)
                ->where('stats.rmb_estimate.source', 'SETTINGS_FX')
                ->where('stats.rmb_estimate.amount_cny', 745.8)
                ->where('stats.rmb_estimate.conversions.0.source', 'manual')
                ->where('stats.rmb_estimate.conversions.0.rate_to_cny', 6.78)
                ->has('stats.rmb_estimate.reference_date')
                ->has('stats.rmb_estimate.stale')
                ->has('stats.rmb_estimate.conversions'));
    }

    public function test_library_stats_rmb_estimate_unavailable_for_unsupported_currency(): void
    {
        Cache::forget('ecb_fx_snapshot');
        // Only CNY in XML, no GBP
        Http::fake(['https://www.ecb.europa.eu/*' => Http::response(
            $this->ecbXml(['CNY' => '7.8']), 200
        )]);

        $user = $this->userWith(['customers.view'], 'rmb_unsupported_user');
        $c = Customer::query()->create(['name' => 'GBP Test', 'source_type' => 'manual']);
        $oid = DB::table('orders')->insertGetId([
            'internal_order_number' => 'GBP-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'GBP-EXT-1', 'order_date' => now()->toDateString(),
            'order_status' => 'shipped', 'customer_id' => $c->id,
            'currency' => 'GBP', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $oid, 'product_summary' => 'P', 'sku' => 'S-GBP', 'quantity' => 1,
            'unit_price' => 50, 'line_revenue' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers/library')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.rmb_estimate.available', false));
    }

    // ─── Customer-order export gate (UAT amendment) ───────────────────────────

    public function test_orders_view_user_gets_403_on_customer_order_export(): void
    {
        $user = $this->userWith(['orders.view'], 'orders_view_only');
        $this->actingAs($user)->get('/customers/orders/export')->assertForbidden();
    }

    public function test_customers_export_without_permissions_manage_gets_403_on_customer_order_export(): void
    {
        $user = $this->userWith(['orders.view', 'customers.export'], 'customers_export_no_admin');
        $this->actingAs($user)->get('/customers/orders/export')->assertForbidden();
    }

    public function test_admin_with_both_permissions_can_access_customer_order_export(): void
    {
        $user = $this->userWith(['orders.view', 'customers.export', 'system.permissions.manage'], 'customer_order_export_admin');
        $this->actingAs($user)->get('/customers/orders/export')->assertOk();
    }

    public function test_orders_index_customer_kind_canExport_false_without_both_permissions(): void
    {
        $user = $this->userWith(['orders.view', 'customers.view'], 'orders_view_no_export');
        $this->actingAs($user)->get('/customers/orders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Index')
                ->where('canExport', false));
    }

    public function test_orders_index_customer_kind_canExport_true_with_both_permissions(): void
    {
        $user = $this->userWith(['orders.view', 'customers.export', 'system.permissions.manage'], 'orders_export_admin');
        $this->actingAs($user)->get('/customers/orders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Index')
                ->where('canExport', true));
    }

    // ─── Customer M1.2 segmentation foundation ─────────────────────────────

    public function test_customer_groups_route_renders_dedicated_workspace_with_account_grid_preference(): void
    {
        $user = $this->userWith(['customers.view'], 'segment_groups_workspace');
        DB::table('user_ui_preferences')->insert([
            'user_id' => $user->id,
            'namespace' => 'grid.customers.groups.v1',
            'value' => json_encode(['columns' => [['key' => 'email', 'visible' => false]]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers/groups?group=no_purchase&country_code=US')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Library/Index')
                ->where('workspace', 'groups')
                ->where('gridPreferenceNamespace', 'grid.customers.groups.v1')
                ->where('gridPreference.columns.0.key', 'email')
                ->where('gridPreference.columns.0.visible', false)
                ->where('filters.group', 'no_purchase')
                ->where('filters.country_code', 'US'));
    }

    public function test_customer_list_grid_projects_registered_address_spend_aov_and_latest_order_from_real_data(): void
    {
        $user = $this->userWith(['customers.view'], 'segment_grid_projection');
        $customer = Customer::query()->create([
            'name' => 'Grid Customer',
            'email' => 'grid@example.test',
            'phone' => '+1-555-0100',
            'country_code' => 'US',
            'source_type' => 'manual',
        ]);
        CustomerAddress::query()->create([
            'customer_id' => $customer->id,
            'address_type' => 'billing',
            'country_code' => 'US',
            'province_state' => 'NY',
            'city' => 'New York',
            'postal_code' => '10001',
        ]);
        CustomerAddress::query()->create([
            'customer_id' => $customer->id,
            'address_type' => 'shipping',
            'country_code' => 'US',
            'province_state' => 'CA',
            'city' => 'Los Angeles',
            'postal_code' => '90001',
        ]);
        $this->createCustomerOrder($customer, ['GRID-A', 'GRID-B']);

        $this->actingAs($user)->get('/customers/library')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.total', 1)
                ->where('customers.data.0.primary_address.city', 'Los Angeles')
                ->where('customers.data.0.primary_address.province_state', 'CA')
                ->where('customers.data.0.primary_address.postal_code', '90001')
                ->where('customers.data.0.orders_count', 1)
                ->where('customers.data.0.lifetime_revenue.0.currency', 'USD')
                ->where('customers.data.0.lifetime_revenue.0.revenue', 20)
                ->where('customers.data.0.lifetime_revenue.0.average_order_value', 20)
                ->where('customers.data.0.latest_order_date', now()->toDateString())
                ->where('customers.data.0.created_at', fn ($value): bool => is_string($value) && $value !== ''));
    }

    public function test_no_purchase_segment_counts_only_non_cancelled_customer_orders(): void
    {
        $user = $this->userWith(['customers.view'], 'segment_no_purchase');
        $none = Customer::query()->create(['name' => 'No Orders', 'source_type' => 'manual']);
        $cancelledOnly = Customer::query()->create(['name' => 'Cancelled Only', 'source_type' => 'manual']);
        $purchased = Customer::query()->create(['name' => 'Purchased', 'source_type' => 'manual']);

        $this->createCustomerOrder($cancelledOnly, ['CANCELLED-SKU'], 'cancelled');
        $this->createCustomerOrder($purchased, ['VALID-SKU']);

        $this->actingAs($user)->get('/customers/groups?group=no_purchase')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.group', 'no_purchase')
                ->where('customers.total', 2)
                ->where('systemGroupCounts.no_purchase', 2)
                ->where('customers.data', function ($rows) use ($cancelledOnly, $none): bool {
                    $actual = collect($rows)->pluck('name')->sort()->values()->all();
                    $expected = collect([$cancelledOnly->name, $none->name])->sort()->values()->all();

                    return $actual === $expected;
                }));
    }

    public function test_new_customer_segment_requires_exactly_one_valid_order(): void
    {
        $user = $this->userWith(['customers.view'], 'segment_new');
        $newCustomer = Customer::query()->create(['name' => 'One Valid', 'source_type' => 'manual']);
        $repeat = Customer::query()->create(['name' => 'Two Valid', 'source_type' => 'manual']);
        Customer::query()->create(['name' => 'Zero Valid', 'source_type' => 'manual']);

        $this->createCustomerOrder($newCustomer, ['ONE']);
        $this->createCustomerOrder($newCustomer, ['IGNORED-CANCELLED'], 'cancelled');
        $this->createCustomerOrder($repeat, ['TWO-A']);
        $this->createCustomerOrder($repeat, ['TWO-B']);

        $this->actingAs($user)->get('/customers/library?group=new_customer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.total', 1)
                ->where('customers.data.0.name', $newCustomer->name));
    }

    public function test_repeat_customer_segment_requires_at_least_two_valid_orders(): void
    {
        $user = $this->userWith(['customers.view'], 'segment_repeat');
        $repeat = Customer::query()->create(['name' => 'Repeat Buyer', 'source_type' => 'manual']);
        $single = Customer::query()->create(['name' => 'Single Buyer', 'source_type' => 'manual']);

        $this->createCustomerOrder($repeat, ['R-A']);
        $this->createCustomerOrder($repeat, ['R-B']);
        $this->createCustomerOrder($single, ['S-A']);

        $this->actingAs($user)->get('/customers/library?group=repeat_customer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.total', 1)
                ->where('customers.data.0.name', $repeat->name));
    }

    public function test_multi_product_segment_requires_three_distinct_skus_in_one_valid_order(): void
    {
        $user = $this->userWith(['customers.view'], 'segment_multi_product');
        $qualifies = Customer::query()->create(['name' => 'Three SKU One Order', 'source_type' => 'manual']);
        $quantityOnly = Customer::query()->create(['name' => 'Quantity Ten', 'source_type' => 'manual']);
        $spreadAcrossOrders = Customer::query()->create(['name' => 'Spread SKU', 'source_type' => 'manual']);
        $cancelledThreeSku = Customer::query()->create(['name' => 'Cancelled Three SKU', 'source_type' => 'manual']);

        $this->createCustomerOrder($qualifies, ['A', 'B', 'C']);
        $this->createCustomerOrder($quantityOnly, ['ONLY-SKU'], 'shipped', 10);
        $this->createCustomerOrder($spreadAcrossOrders, ['X', 'Y']);
        $this->createCustomerOrder($spreadAcrossOrders, ['Z']);
        $this->createCustomerOrder($cancelledThreeSku, ['CA', 'CB', 'CC'], 'cancelled');

        $this->actingAs($user)->get('/customers/library?group=multi_product_single_order')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.total', 1)
                ->where('customers.data.0.name', $qualifies->name));
    }

    public function test_advanced_recency_segments_use_inclusive_frozen_boundaries_and_valid_purchases(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        try {
            $user = $this->userWith(['customers.view'], 'segment_advanced_recency');
            $active = Customer::query()->create(['name' => 'Active', 'source_type' => 'manual']);
            $churn91 = Customer::query()->create(['name' => 'Churn 91', 'source_type' => 'manual']);
            $churn180 = Customer::query()->create(['name' => 'Churn 180', 'source_type' => 'manual']);
            $dormant = Customer::query()->create(['name' => 'Dormant', 'source_type' => 'manual']);
            $cancelled = Customer::query()->create(['name' => 'Cancelled', 'source_type' => 'manual']);
            $kol = Customer::query()->create(['name' => 'KOL Order Only', 'source_type' => 'manual']);

            $this->createCustomerOrder($active, ['ACTIVE'], 'shipped', 1, now()->subDays(90)->toDateString());
            $this->createCustomerOrder($churn91, ['CHURN-91'], 'shipped', 1, now()->subDays(91)->toDateString());
            $this->createCustomerOrder($churn91, ['CHURN-OLDER'], 'shipped', 1, now()->subDays(365)->toDateString());
            $this->createCustomerOrder($churn180, ['CHURN-180'], 'shipped', 1, now()->subDays(180)->toDateString());
            $this->createCustomerOrder($churn180, ['CHURN-OLDER'], 'shipped', 1, now()->subDays(365)->toDateString());
            $this->createCustomerOrder($dormant, ['DORMANT'], 'shipped', 1, now()->subDays(181)->toDateString());
            $this->createCustomerOrder($cancelled, ['CANCELLED'], 'cancelled', 1, now()->toDateString());
            $this->createCustomerOrder($kol, ['KOL'], 'shipped', 1, now()->toDateString(), 'USD', 'kol_fulfillment');

            $this->assertSegmentNames($user, 'active_customer', ['Active']);
            $this->assertSegmentNames($user, 'churn_risk', ['Churn 180', 'Churn 91']);
            $this->assertSegmentNames($user, 'dormant_customer', ['Dormant']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_advanced_frequency_value_and_vip_segments_are_deterministic_and_currency_isolated(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        try {
            $user = $this->userWith(['customers.view'], 'segment_advanced_value');
            $frequency = Customer::query()->create(['name' => 'Frequency', 'source_type' => 'manual']);
            $value = Customer::query()->create(['name' => 'Value', 'source_type' => 'manual']);
            $vip = Customer::query()->create(['name' => 'VIP', 'source_type' => 'manual']);
            $crossCurrency = Customer::query()->create(['name' => 'Cross Currency', 'source_type' => 'manual']);
            $unsupported = Customer::query()->create(['name' => 'Unsupported', 'source_type' => 'manual']);
            $cancelledOnly = Customer::query()->create(['name' => 'Cancelled Advanced Only', 'source_type' => 'manual']);
            $nonCustomerOnly = Customer::query()->create(['name' => 'Non Customer Advanced Only', 'source_type' => 'manual']);
            $boundary = Customer::query()->create(['name' => 'Frequency Boundary', 'source_type' => 'manual']);
            $currencyThresholds = ['USD' => 500, 'EUR' => 450, 'GBP' => 400, 'CAD' => 700, 'AUD' => 750, 'CNY' => 3500];
            $thresholdCustomers = [];

            foreach (range(1, 3) as $index) {
                $this->createCustomerOrder($frequency, ["F-{$index}"], 'shipped', 1, now()->subDays(365)->toDateString());
                $this->createCustomerOrder($vip, ["V-{$index}"], 'shipped', 1, now()->subDays($index)->toDateString(), 'USD', 'customer');
            }
            $this->createCustomerOrder($value, ['VALUE'], 'shipped', 50, now()->toDateString(), 'USD', 'customer');
            $this->createCustomerOrder($vip, ['VIP-VALUE'], 'shipped', 49, now()->toDateString(), 'USD', 'customer');
            $this->createCustomerOrder($crossCurrency, ['CROSS-USD'], 'shipped', 49, now()->toDateString(), 'USD', 'customer');
            $this->createCustomerOrder($crossCurrency, ['CROSS-EUR'], 'shipped', 44, now()->toDateString(), 'EUR', 'customer');
            $this->createCustomerOrder($unsupported, ['UNSUPPORTED'], 'shipped', 1, now()->toDateString(), 'JPY', 'customer');
            foreach (range(1, 3) as $index) {
                $this->createCustomerOrder($cancelledOnly, ["CANCELLED-FREQ-{$index}"], 'cancelled', 1, now()->toDateString(), 'USD', 'customer');
                $this->createCustomerOrder($nonCustomerOnly, ["NON-CUSTOMER-FREQ-{$index}"], 'shipped', 1, now()->toDateString(), 'USD', 'kol_fulfillment');
            }
            $this->createCustomerOrder($cancelledOnly, ['CANCELLED-VALUE'], 'cancelled', 50, now()->toDateString(), 'USD', 'customer');
            $this->createCustomerOrder($nonCustomerOnly, ['NON-CUSTOMER-VALUE'], 'shipped', 50, now()->toDateString(), 'USD', 'kol_fulfillment');
            foreach ($currencyThresholds as $currency => $threshold) {
                $thresholdCustomer = Customer::query()->create(['name' => "Threshold {$currency}", 'source_type' => 'manual']);
                $belowThresholdCustomer = Customer::query()->create(['name' => "Below {$currency}", 'source_type' => 'manual']);
                $this->createCustomerOrder($thresholdCustomer, ["THRESHOLD-{$currency}"], 'shipped', (int) ($threshold / 10), now()->toDateString(), $currency, 'customer');
                $this->createCustomerOrder($belowThresholdCustomer, ["BELOW-{$currency}"], 'shipped', (int) (($threshold - 10) / 10), now()->toDateString(), $currency, 'customer');
                $thresholdCustomers[] = $thresholdCustomer->name;
            }
            $this->createCustomerOrder($boundary, ['BOUNDARY-1'], 'shipped', 1, now()->subDays(366)->toDateString(), 'USD', 'customer');
            $this->createCustomerOrder($boundary, ['BOUNDARY-2'], 'shipped', 1, now()->subDays(366)->toDateString(), 'USD', 'customer');
            $this->createCustomerOrder($boundary, ['BOUNDARY-3'], 'shipped', 1, now()->subDays(366)->toDateString(), 'USD', 'customer');

            $this->assertSegmentNames($user, 'high_frequency', ['Frequency', 'VIP']);
            $this->assertSegmentNames($user, 'high_value', ['Value', 'VIP', ...$thresholdCustomers]);
            $this->assertSegmentNames($user, 'vip', ['VIP']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_customer360_closure_projects_groups_order_summary_and_human_readable_activity(): void
    {
        Carbon::setTestNow('2026-08-31 12:00:00');
        try {
            $user = $this->userWith(['customers.view', 'customers.operate'], 'customer360_closure');
            $customer = Customer::query()->create(['name' => 'Closure Customer', 'email' => 'closure@example.test', 'source_type' => 'manual']);
            $group = CustomerGroup::query()->create(['name' => '2026 黑五重点客户', 'description' => 'Owner group']);
            $customer->groups()->attach($group->id, ['added_by' => $user->id]);
            $this->createCustomerOrder($customer, ['C360-SKU'], 'shipped', 2, now()->toDateString(), 'USD', 'customer');

            $this->actingAs($user)->patch('/customers/library/'.$customer->id, [
                'name' => 'Closure Customer Updated',
                'email' => 'closure@example.test',
                'source_type' => 'manual',
            ])->assertRedirect('/customers/library/'.$customer->id);

            $this->actingAs($user)->get('/customers/library/'.$customer->id)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('metrics.order_count', 1)
                    ->where('recentOrders.0.revenue', 20)
                    ->where('manualGroups.0.name', '2026 黑五重点客户')
                    ->where('systemSegments', fn ($segments): bool => collect($segments)->contains(fn ($segment) => $segment['key'] === 'active_customer'))
                    ->where('audit.0.action', 'customer.updated')
                    ->where('audit.0.action_label', '更新客户')
                    ->where('audit.0.actor_name', $user->name));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function assertSegmentNames(User $user, string $segment, array $expected): void
    {
        $this->actingAs($user)->get("/customers/library?group={$segment}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('customers.data', function ($rows) use ($expected): bool {
                return collect($rows)->pluck('name')->sort()->values()->all() === collect($expected)->sort()->values()->all();
            }));
    }

    private function createCustomerOrder(Customer $customer, array $skus, string $status = 'shipped', int $quantity = 1, ?string $orderDate = null, string $currency = 'USD', string $orderKind = 'customer'): int
    {
        $token = str_replace('.', '', uniqid('', true));
        $orderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'SEG-'.$token,
            'source_type' => 'manual',
            'external_order_number' => 'SEG-EXT-'.$token,
            'order_date' => $orderDate ?? now()->toDateString(),
            'order_status' => $status,
            'customer_id' => $customer->id,
            'currency' => $currency,
            'order_kind' => $orderKind,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($skus as $sku) {
            DB::table('order_items')->insert([
                'order_id' => $orderId,
                'product_summary' => $sku,
                'sku' => $sku,
                'quantity' => $quantity,
                'unit_price' => 10,
                'line_revenue' => 10 * $quantity,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $orderId;
    }

    private function ecbXml(array $rates = [], string $date = '2026-08-28'): string
    {
        $rateXml = '';
        foreach ($rates as $currency => $rate) {
            $rateXml .= "<Cube currency=\"{$currency}\" rate=\"{$rate}\"/>";
        }
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"
                 xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
    <Cube><Cube time="{$date}">{$rateXml}</Cube></Cube>
</gesmes:Envelope>
XML;
    }

    private function userWith(array $permissions, string $roleKey = 'customer_operator'): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
