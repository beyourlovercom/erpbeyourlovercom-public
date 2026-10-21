<?php

namespace Tests\Feature\Customers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Customers\Infrastructure\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CustomerMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_create_and_view_customer_master(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);

        $response = $this->actingAs($user)->post('/customers/library', [
            'name' => 'Alice Example',
            'email' => ' Alice@Example.COM ',
            'phone' => '+1 310 555 0100',
            'country_code' => 'us',
            'language_code' => 'English',
            'source_type' => 'manual',
            'notes' => 'Priority customer',
        ]);

        $customer = Customer::query()->firstOrFail();
        $response->assertRedirect('/customers/library/'.$customer->id);
        $this->assertSame('alice@example.com', $customer->email);
        $this->assertSame('US', $customer->country_code);
        $this->assertSame('CUS-00000001', $customer->customer_number);
        $this->assertDatabaseHas('audit_records', ['action' => 'customer.created', 'entity_id' => (string) $customer->id]);

        $this->actingAs($user)->get('/customers/library/'.$customer->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Library/Show')
                ->where('customer.email', 'alice@example.com')
                ->where('metrics.order_count', 0)
                ->where('metrics.revenue_by_currency', []));
    }

    public function test_customer_detail_aggregates_only_explicitly_linked_customer_orders(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $customer = Customer::query()->create(['name' => 'Linked Buyer', 'source_type' => 'manual']);
        $other = Customer::query()->create(['name' => 'Other Buyer', 'source_type' => 'manual']);

        $linkedOrderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'CUS-ORDER-1', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'WEB-1001', 'order_date' => '2026-08-20', 'order_status' => 'shipped',
            'customer_id' => $customer->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            ['order_id' => $linkedOrderId, 'product_summary' => 'Product A', 'sku' => 'A1', 'quantity' => 1, 'unit_price' => 100, 'line_revenue' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => $linkedOrderId, 'product_summary' => 'Product B', 'sku' => 'B1', 'quantity' => 1, 'unit_price' => 50, 'line_revenue' => 50, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('orders')->insert([
            'internal_order_number' => 'CUS-ORDER-2', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'WEB-1002', 'order_date' => '2026-08-21', 'order_status' => 'shipped',
            'customer_id' => $other->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers/library/'.$customer->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('customer.orders_count', 1)
                ->where('metrics.order_count', 1)
                ->where('metrics.first_order_date', '2026-08-20')
                ->where('metrics.last_order_date', '2026-08-20')
                ->has('metrics.revenue_by_currency', 1)
                ->where('metrics.revenue_by_currency.0.revenue', 150)
                ->where('metrics.revenue_by_currency.0.average_order_value', 150)
                ->has('recentOrders', 1)
                ->where('recentOrders.0.number', 'WEB-1001'));
    }

    public function test_unlinked_historical_orders_are_never_counted_or_backfilled(): void
    {
        $user = $this->userWith(['customers.view']);
        $customer = Customer::query()->create(['name' => 'Same Name', 'email' => 'same@example.com', 'country_code' => 'US', 'source_type' => 'website']);
        DB::table('orders')->insert([
            'internal_order_number' => 'UNLINKED-1', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'OLD-1', 'order_date' => '2026-08-01', 'order_status' => 'shipped',
            'customer_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/customers/library?search=same@example.com&source_type=website')
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.id', $customer->id)
                ->where('customers.data.0.orders_count', 0));

        $this->actingAs($user)->get('/customers/library/'.$customer->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.order_count', 0)
                ->where('metrics.revenue_by_currency', [])
                ->has('recentOrders', 0));
    }

    public function test_multi_currency_orders_are_not_summed_together(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $customer = Customer::query()->create(['name' => 'Multi Currency Buyer', 'source_type' => 'manual']);

        $usdOrderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'USD-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'USD-EXT-1', 'order_date' => '2026-08-10', 'order_status' => 'shipped',
            'customer_id' => $customer->id, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            ['order_id' => $usdOrderId, 'product_summary' => 'US Product', 'sku' => 'US1', 'quantity' => 1, 'unit_price' => 200, 'line_revenue' => 200, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $eurOrderId = DB::table('orders')->insertGetId([
            'internal_order_number' => 'EUR-001', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'EUR-EXT-1', 'order_date' => '2026-08-11', 'order_status' => 'shipped',
            'customer_id' => $customer->id, 'currency' => 'EUR', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            ['order_id' => $eurOrderId, 'product_summary' => 'EU Product', 'sku' => 'EU1', 'quantity' => 1, 'unit_price' => 150, 'line_revenue' => 150, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($user)->get('/customers/library/'.$customer->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.order_count', 2)
                ->has('metrics.revenue_by_currency', 2)
                ->where('metrics.revenue_by_currency.0.currency', 'EUR')
                ->where('metrics.revenue_by_currency.0.revenue', 150)
                ->where('metrics.revenue_by_currency.1.currency', 'USD')
                ->where('metrics.revenue_by_currency.1.revenue', 200)
                ->has('recentOrders', 2)
                ->where('recentOrders.0.currency', 'EUR')
                ->where('recentOrders.1.currency', 'USD'));
    }

    public function test_duplicate_email_is_rejected_after_normalization(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $this->actingAs($user)->post('/customers/library', ['name' => 'First', 'email' => 'first@example.com', 'source_type' => 'manual'])->assertRedirect();
        $this->actingAs($user)->post('/customers/library', ['name' => 'Second', 'email' => 'FIRST@EXAMPLE.COM', 'source_type' => 'manual'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_permissions_and_archive_restore_are_enforced(): void
    {
        $operator = $this->userWith(['customers.view', 'customers.operate']);
        $customer = Customer::query()->create(['name' => 'Archive Me', 'source_type' => 'manual']);
        $this->actingAs($operator)->post('/customers/library/'.$customer->id.'/archive')->assertRedirect('/customers/library');
        $this->assertNotNull($customer->fresh()->archived_at);
        $this->actingAs($operator)->post('/customers/library/'.$customer->id.'/restore')->assertRedirect('/customers/library/'.$customer->id);
        $this->assertNull($customer->fresh()->archived_at);

        $reader = $this->userWith(['customers.view'], 'customer_reader');
        $this->actingAs($reader)->get('/customers/library')->assertOk();
        $this->actingAs($reader)->post('/customers/library', ['name' => 'Forbidden', 'source_type' => 'manual'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->get('/customers/library')->assertRedirect('/login');
    }

    public function test_audit_records_created_on_create_update_archive_restore(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $this->actingAs($user)->post('/customers/library', ['name' => 'Audited', 'source_type' => 'manual']);
        $customer = Customer::query()->firstOrFail();
        $this->assertDatabaseHas('audit_records', ['action' => 'customer.created', 'entity_id' => (string) $customer->id]);

        $this->actingAs($user)->patch('/customers/library/'.$customer->id, ['name' => 'Audited Updated', 'source_type' => 'manual']);
        $this->assertDatabaseHas('audit_records', ['action' => 'customer.updated', 'entity_id' => (string) $customer->id]);

        $this->actingAs($user)->post('/customers/library/'.$customer->id.'/archive');
        $this->assertDatabaseHas('audit_records', ['action' => 'customer.archived', 'entity_id' => (string) $customer->id]);

        $this->actingAs($user)->post('/customers/library/'.$customer->id.'/restore');
        $this->assertDatabaseHas('audit_records', ['action' => 'customer.restored', 'entity_id' => (string) $customer->id]);
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
