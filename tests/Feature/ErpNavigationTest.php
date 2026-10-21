<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ErpNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $permissionKeys = [
            'prototype.view', 'dashboard.view', 'architecture.foundation.view', 'logistics.view', 'logistics.operate',
            'sync.view', 'sync.operate', 'customers.view', 'kol.view', 'inventory.view', 'procurement.view',
            'products.view', 'guides.view', 'accounting.view', 'marketing.view', 'marketing.operate', 'system.permissions.manage', 'orders.view',
            'orders.create', 'orders.edit', 'analysis.view',
        ];
        $permissions = collect($permissionKeys)->mapWithKeys(fn (string $key): array => [$key => Permission::create(['key' => $key, 'label' => $key])]);
        $role = Role::create(['key' => 'admin', 'label' => 'Admin']);
        $role->permissions()->attach($permissions->pluck('id')->all());
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        $this->actingAs($user);
    }

    public function test_every_declared_navigation_path_is_available(): void
    {
        foreach ($this->paths(config('erp_navigation')) as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_shell_renders_required_modules_without_affiliate(): void
    {
        $response = $this->get('/logistics/wait-stock');
        $response->assertInertia(fn (Assert $page) => $page->component('Logistics/FulfillmentAllocation')->has('shellNavigation', 13));
        $this->get('/logistics/pending')->assertRedirect('/logistics/wait-stock');
    }

    public function test_accounting_third_level_navigation_is_rendered(): void
    {
        $this->get('/accounting/expense-analysis/2026-execution')->assertOk()->assertSee('费用分析表')->assertSee('2026执行');
    }

    public function test_order_list_and_detail_render_the_phase_two_skeleton(): void
    {
        $kolProduct = Product::firstOrCreate(['sku' => 'KOL-1'], ['name' => 'Synthetic KOL Product', 'product_type' => 'simple', 'woocommerce_product_id' => 6001]);
        $this->post('/kol/orders', [
            'order_kind' => 'kol_fulfillment', 'source_type' => 'kol', 'external_order_number' => 'PHASE2-KOL-1',
            'order_date' => '2026-08-05', 'recipient_name' => 'Synthetic KOL', 'phone' => '+1 555 0101',
            'country' => 'US', 'province_state' => 'CA', 'city' => 'Los Angeles', 'address_line_1' => 'Synthetic Street',
            'items' => [['product_id' => $kolProduct->id, 'product_summary' => 'Synthetic KOL Product', 'sku' => 'KOL-1', 'quantity' => 1, 'unit_price' => '10.00']],
        ])->assertRedirect();
        $order = Order::query()->firstOrFail();
        $this->get('/kol/orders')->assertInertia(fn (Assert $page) => $page->component('Orders/Index')->where('orders.data.0.internal_order_number', $order->internal_order_number));
        $this->get('/kol/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->component('Orders/Show')->where('order.internal_order_number', $order->internal_order_number));
        $this->get('/kol/orders/999999')->assertNotFound();
    }

    public function test_all_orders_page_combines_customer_and_kol_orders(): void
    {
        $product = Product::firstOrCreate(['sku' => 'ALL-ORDERS-1'], ['name' => 'All Orders Product', 'product_type' => 'simple', 'woocommerce_product_id' => 6002]);
        $base = [
            'order_date' => '2026-08-27', 'recipient_name' => 'All Orders', 'country' => 'US',
            'items' => [['product_id' => $product->id, 'product_summary' => 'All Orders Product', 'quantity' => 1, 'unit_price' => '10.00']],
        ];
        $this->post('/customers/orders', [...$base, 'external_order_number' => 'ALL-CUSTOMER-1'])->assertRedirect();
        $this->post('/kol/orders', [...$base, 'external_order_number' => 'ALL-KOL-1'])->assertRedirect();

        $this->get('/orders')->assertInertia(fn (Assert $page) => $page
            ->component('Orders/Index')
            ->where('orderKind', 'all')
            ->has('orders.data', 2)
            ->where('orders.data.0.order_kind', 'kol_fulfillment')
            ->where('orders.data.1.order_kind', 'customer'));
        $this->get('/orders/export')->assertOk();
    }

    public function test_procurement_orders_include_only_orders_assigned_to_external_warehouses(): void
    {
        $product = Product::firstOrCreate(['sku' => 'PROC-ORDERS-1'], ['name' => 'Procurement Orders Product', 'product_type' => 'simple', 'woocommerce_product_id' => 6003]);
        $base = [
            'order_date' => '2026-08-27', 'recipient_name' => 'Warehouse Scope', 'country' => 'US',
            'items' => [['product_id' => $product->id, 'product_summary' => 'Procurement Orders Product', 'quantity' => 1, 'unit_price' => '10.00']],
        ];
        $this->post('/customers/orders', [...$base, 'external_order_number' => 'PROC-EXTERNAL-1'])->assertRedirect();
        $this->post('/customers/orders', [...$base, 'external_order_number' => 'PROC-OWNED-1'])->assertRedirect();
        $this->post('/customers/orders', [...$base, 'external_order_number' => 'PROC-UNASSIGNED-1'])->assertRedirect();

        $externalWarehouse = Warehouse::query()->create([
            'slug' => 'external-3pl', 'code' => 'EXTERNAL-3PL', 'name' => 'External 3PL', 'type' => 'warehouse',
            'ownership_type' => 'external', 'is_active' => true, 'priority' => 999,
        ]);
        $ownedWarehouse = Warehouse::query()->where('slug', 'us-la')->firstOrFail();
        Order::query()->where('external_order_number', 'PROC-EXTERNAL-1')->firstOrFail()->shipment()->create([
            'warehouse_id' => $externalWarehouse->id, 'shipment_status' => 'draft',
        ]);
        Order::query()->where('external_order_number', 'PROC-OWNED-1')->firstOrFail()->shipment()->create([
            'warehouse_id' => $ownedWarehouse->id, 'shipment_status' => 'draft',
        ]);

        $this->get('/orders/procurement')->assertInertia(fn (Assert $page) => $page
            ->component('Orders/Index')
            ->where('orderKind', 'all')
            ->where('orderScope', 'external_warehouse')
            ->has('orders.data', 1)
            ->where('orders.data.0.external_order_number', 'PROC-EXTERNAL-1'));
        $csv = $this->get('/orders/procurement/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('PROC-EXTERNAL-1', $csv);
        $this->assertStringNotContainsString('PROC-OWNED-1', $csv);
        $this->assertStringNotContainsString('PROC-UNASSIGNED-1', $csv);
    }

    public function test_architecture_foundation_is_an_inertia_page_with_safe_props(): void
    {
        $this->get('/architecture/foundation')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Architecture/Foundation')
                ->where('title', 'BYL ERP Foundation')
                ->where('status', 'ARCHITECTURE VALIDATION')
                ->missing('user')
                ->missing('orders'));
    }

    public function test_guest_root_redirects_to_login_and_authenticated_root_to_dashboard(): void
    {
        auth()->logout();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
        $this->actingAs(User::first())->get('/')->assertRedirectToRoute('dashboard');
    }

    public function test_login_rejects_inactive_user_and_logs_out_authenticated_user(): void
    {
        auth()->logout();
        $user = User::first();
        $user->update(['is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $user->update(['is_active' => true, 'password' => Hash::make('password')]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertDatabaseHas('audit_records', ['action' => 'auth.login']);
        $this->post('/logout')->assertRedirect('/');
        $this->assertDatabaseHas('audit_records', ['action' => 'auth.logout']);
    }

    public function test_successful_login_regenerates_the_session_id(): void
    {
        auth()->logout();
        $user = User::first();
        $user->update(['password' => Hash::make('password')]);
        $before = $this->app['session']->getId();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');
        $this->assertNotSame($before, $this->app['session']->getId());
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        auth()->logout();
        $user = User::first();
        RateLimiter::clear(strtolower($user->email).'|127.0.0.1');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(429);
    }

    private function paths(array $items): array
    {
        $paths = [];
        foreach ($items as $item) {
            if (isset($item['path'])) {
                $paths[] = $item['path'];
            }
            if (isset($item['children'])) {
                $paths = [...$paths, ...$this->paths($item['children'])];
            }
        }

        return $paths;
    }
}
