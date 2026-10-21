<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Shell\Application\ErpShellNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppShellNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions, string $roleKey = 'operator'): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_guest_shell_access_redirects_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/sync')->assertRedirect('/login');
    }

    public function test_shell_navigation_permission_resolution_has_bounded_query_count(): void
    {
        Warehouse::query()->delete();
        Warehouse::query()->create(['slug' => 'de', 'code' => 'DE', 'name' => '德国仓', 'is_active' => true, 'priority' => 10]);

        $permissions = collect(config('erp_shell_navigation'))
            ->flatMap(function (array $item): array {
                $keys = array_values(array_filter([
                    $item['permission'] ?? null,
                    ...($item['permissions_any'] ?? []),
                    ...($item['permissions_all'] ?? []),
                ]));
                foreach ($item['children'] ?? [] as $child) {
                    $keys = [...$keys, ...array_values(array_filter([
                        $child['permission'] ?? null,
                        ...($child['permissions_any'] ?? []),
                        ...($child['permissions_all'] ?? []),
                    ]))];
                }

                return $keys;
            })
            ->unique()->values()->all();
        $user = $this->userWith($permissions, 'navigation-query-budget');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $navigation = app(ErpShellNavigation::class)->for($user);

        $this->assertNotEmpty($navigation);
        $this->assertLessThanOrEqual(4, $queryCount, 'Shell navigation must not query once per permission check.');
    }

    public function test_dashboard_renders_formal_shell_with_safe_shared_props(): void
    {
        $user = $this->userWith(['dashboard.view', 'sync.view']);
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('auth.user.id', $user->id)
            ->where('auth.user.name', $user->name)
            ->where('auth.user.email', $user->email)
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token')
            ->has('shellNavigation', 2)
            ->where('shellNavigation.0.key', 'dashboard')
            ->where('shellNavigation.1.key', 'sync'));
    }

    public function test_navigation_comes_from_formal_source_and_filters_unauthorized_modules(): void
    {
        $user = $this->userWith(['dashboard.view', 'logistics.view']);
        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertInertia(fn (Assert $page) => $page
            ->where('shellNavigation.0.label', '仪表盘')
            ->where('shellNavigation.1.label', '物流')
            ->missing('shellNavigation.2'));
        $this->actingAs($user)->get('/sync')->assertForbidden();
        $this->actingAs($user)->get('/settings')->assertForbidden();
    }

    public function test_restored_legacy_children_are_emitted_and_gated_by_their_own_permission(): void
    {
        $user = $this->userWith(['dashboard.view', 'logistics.view'], 'operator-without-prototype');
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('shellNavigation.1.children', 7)
            ->where('shellNavigation.1.children.0.label', '待分仓')
            ->where('shellNavigation.1.children.1.label', '国内发货')
            ->where('shellNavigation.1.children.1.path', '/logistics/china')
            ->where('shellNavigation.1.children.2.label', '海外仓发货')
            ->where('shellNavigation.1.children.3.label', '已发待单号')
            ->where('shellNavigation.1.children.4.label', '运输中')
            ->where('shellNavigation.1.children.5.label', '异常订单')
            ->where('shellNavigation.1.children.6.label', '承运商'));

        $withPrototype = $this->userWith(['dashboard.view', 'logistics.view', 'prototype.view'], 'operator-with-prototype');
        $this->actingAs($withPrototype)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('shellNavigation.1.children', 7)
            ->where('shellNavigation.1.children.0.label', '待分仓')
            ->where('shellNavigation.1.children.1.label', '国内发货')
            ->where('shellNavigation.1.children.2.label', '海外仓发货')
            ->where('shellNavigation.1.children.3.label', '已发待单号')
            ->where('shellNavigation.1.children.4.label', '运输中')
            ->where('shellNavigation.1.children.5.label', '异常订单')
            ->where('shellNavigation.1.children.6.label', '承运商'));
    }

    public function test_owner_approved_kol_logistics_entry_is_visible_only_with_kol_permission(): void
    {
        $specialist = $this->userWith(['dashboard.view', 'kol.view', 'orders.view'], 'kol-logistics-menu-specialist');
        $nav = app(ErpShellNavigation::class)->for($specialist);
        $this->assertNull(collect($nav)->firstWhere('key', 'logistics'), 'KOL specialist must not receive general Logistics navigation');
        $kol = collect($nav)->firstWhere('key', 'kol');
        $this->assertNotNull($kol);
        $item = collect($kol['children'])->firstWhere('key', 'kol-logistics');
        $this->assertNotNull($item);
        $this->assertSame('/kol/logistics', $item['path']);
        $this->assertSame('kol.logistics', $item['route']);
        $this->assertSame('物流进度', $item['label']);
        $this->actingAs($specialist)->get('/kol/logistics')->assertOk();

        $withoutKol = $this->userWith(['dashboard.view', 'logistics.view'], 'logistics-without-kol');
        $other = app(ErpShellNavigation::class)->for($withoutKol);
        $this->assertNull(collect($other)->firstWhere('key', 'kol'));
        $this->actingAs($withoutKol)->get('/kol/logistics')->assertForbidden();
    }

    public function test_inventory_navigation_includes_active_warehouse_shortcuts(): void
    {
        Warehouse::query()->delete();
        $first = Warehouse::query()->create(['slug' => 'de', 'code' => 'DE', 'name' => '德国仓', 'is_active' => true, 'priority' => 10]);
        $second = Warehouse::query()->create(['slug' => 'us-east', 'code' => 'USE', 'name' => '美东仓', 'is_active' => true, 'priority' => 20]);
        Warehouse::query()->create(['slug' => 'inactive', 'code' => 'OFF', 'name' => '停用仓', 'is_active' => false, 'priority' => 1]);

        $user = $this->userWith(['dashboard.view', 'inventory.view']);
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('shellNavigation.1.children', 6)
            ->where('shellNavigation.1.key', 'inventory')
            ->where('shellNavigation.1.children.0.key', 'inventory-overview')
            ->where('shellNavigation.1.children.1.key', 'inventory-list')
            ->where('shellNavigation.1.children.2.key', 'inventory-warehouse-'.$first->id)
            ->where('shellNavigation.1.children.2.label', '德国仓')
            ->where('shellNavigation.1.children.2.path', '/inventory/de')
            ->where('shellNavigation.1.children.3.key', 'inventory-warehouse-'.$second->id)
            ->where('shellNavigation.1.children.3.label', '美东仓')
            ->where('shellNavigation.1.children.4.key', 'inventory-products')
            ->where('shellNavigation.1.children.5.key', 'inventory-packaging'));

        $this->actingAs($user)->get('/inventory/de')->assertOk();
    }

    public function test_packaging_permission_exposes_inventory_only_through_packaging_child(): void
    {
        $user = $this->userWith(['dashboard.view', 'inventory.packaging.submit'], 'factory-packaging-menu');

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('shellNavigation', 2)
            ->where('shellNavigation.1.key', 'inventory')
            ->has('shellNavigation.1.children', 1)
            ->where('shellNavigation.1.children.0.key', 'inventory-packaging')
            ->where('shellNavigation.1.children.0.path', '/inventory/packaging'));
    }

    public function test_accounting_navigation_closes_to_overview_and_order_profit(): void
    {
        $user = $this->userWith(['dashboard.view', 'accounting.view', 'analysis.view', 'prototype.view']);
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('shellNavigation.1.key', 'accounting')
            ->has('shellNavigation.1.children', 3)
            ->where('shellNavigation.1.children.0.key', 'accounting-overview')
            ->where('shellNavigation.1.children.0.path', '/accounting')
            ->where('shellNavigation.1.children.1.key', 'accounting-order-costs')
            ->where('shellNavigation.1.children.1.label', '订单利润')
            ->where('shellNavigation.1.children.1.path', '/accounting/order-costs')
            ->where('shellNavigation.1.children.2.key', 'accounting-loss-orders')
            ->where('shellNavigation.1.children.2.label', '亏损订单')
            ->where('shellNavigation.1.children.2.path', '/accounting/order-costs?profit_status=loss'));
    }

    public function test_customer_import_is_available_from_customer_and_sync_navigation_with_the_same_workspace(): void
    {
        $user = $this->userWith(['dashboard.view', 'sync.view', 'customers.view', 'customers.import', 'system.permissions.manage'], 'customer-import-admin');

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('shellNavigation.1.key', 'sync')
            ->where('shellNavigation.1.children.3.key', 'sync-customers-import')
            ->where('shellNavigation.1.children.3.path', '/sync/customers/import')
            ->where('shellNavigation.2.key', 'customers')
            ->where('shellNavigation.2.children.2.key', 'customers-companies')
            ->where('shellNavigation.2.children.3.key', 'customers-import')
            ->where('shellNavigation.2.children.3.path', '/customers/import'));

        $this->actingAs($user)->get('/customers/import')->assertInertia(fn (Assert $page) => $page->component('Customers/Import'));
        $this->actingAs($user)->get('/sync/customers/import')->assertInertia(fn (Assert $page) => $page->component('Customers/Import'));
    }

    public function test_customer_import_navigation_requires_both_import_and_permission_admin_gates(): void
    {
        $withoutAdminGate = $this->userWith(['dashboard.view', 'sync.view', 'customers.view', 'customers.import'], 'customer-import-no-admin');

        $this->actingAs($withoutAdminGate)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('shellNavigation.1.key', 'sync')
            ->has('shellNavigation.1.children', 4)
            ->where('shellNavigation.2.key', 'customers')
            ->has('shellNavigation.2.children', 3)
            ->where('shellNavigation.2.children.2.key', 'customers-companies'));

        $this->actingAs($withoutAdminGate)->get('/customers/import')->assertForbidden();
        $this->actingAs($withoutAdminGate)->get('/sync/customers/import')->assertForbidden();
    }

    public function test_sync_permission_controls_route_and_navigation_item(): void
    {
        $user = $this->userWith(['sync.view']);
        $this->actingAs($user)->get('/sync')->assertInertia(fn (Assert $page) => $page
            ->component('Sync/Index')
            ->where('shellNavigation.0.path', '/sync'));
    }

    public function test_settings_fx_child_menu_is_implemented_with_settings_permission(): void
    {
        $settings = collect(config('erp_shell_navigation'))->firstWhere('key', 'settings');
        $fx = collect($settings['children'] ?? [])->firstWhere('key', 'settings-fx');
        $this->assertSame('汇率计算', $fx['label']);
        $this->assertSame('/settings/fx', $fx['path']);
        $this->assertSame('settings.fx', $fx['route']);
        $this->assertSame('system.permissions.manage', $fx['permission']);
        $this->assertSame('implemented', $fx['status']);
    }

    public function test_backup_child_menu_uses_backup_view_permission_and_canonical_route(): void
    {
        $user = $this->userWith(['dashboard.view', 'system.permissions.manage', 'backup.view'], 'backup-menu-user');
        $settings = collect(config('erp_shell_navigation'))->firstWhere('key', 'settings');
        $backup = collect($settings['children'] ?? [])->firstWhere('key', 'settings-backup');
        $this->assertSame('备份与迁移', $backup['label']);
        $this->assertSame('/settings/backup', $backup['path']);
        $this->assertSame('backup.index', $backup['route']);
        $this->assertSame('backup.view', $backup['permission']);
    }

    public function test_only_exact_legacy_root_path_collisions_fall_back_for_prototype_users(): void
    {
        $prototypeUser = $this->userWith(['prototype.view'], 'prototype-operator');
        $this->actingAs($prototypeUser)->get('/procurement')->assertForbidden();
        $this->actingAs($prototypeUser)->get('/purchase')->assertForbidden();
        // Guides is now a formal knowledge-base module and no longer falls back to prototype access.
        $this->actingAs($prototypeUser)->get('/guides')->assertForbidden();
        // /products now requires products.view (not prototype.view); prototype-only users are forbidden
        $this->actingAs($prototypeUser)->get('/products')->assertForbidden();
        foreach (['/inventory', '/logistics', '/customers', '/kol', '/marketing', '/accounting', '/settings', '/sync'] as $path) {
            $this->actingAs($prototypeUser)->get($path)->assertForbidden();
        }
    }

    public function test_every_formal_root_renders_real_content_instead_of_placeholder(): void
    {
        $permissionsByPath = [
            '/orders' => 'orders.view', '/logistics' => 'logistics.view', '/sync' => 'sync.view', '/customers' => 'customers.view',
            '/kol' => 'kol.view', '/marketing' => 'marketing.view', '/inventory' => 'inventory.view', '/purchase' => 'procurement.view',
            '/products' => 'products.view', '/guides' => 'guides.view', '/accounting' => 'accounting.view',
            '/settings' => 'system.permissions.manage',
        ];
        $user = $this->userWith(array_values($permissionsByPath), 'formal-operator');
        foreach ($permissionsByPath as $path => $permission) {
            if ($path === '/logistics') {
                $this->actingAs($user)->get($path)->assertRedirect('/logistics/wait-stock');

                continue;
            }
            $this->actingAs($user)->get($path)->assertInertia(fn (Assert $page) => $page
                ->component(match ($path) {
                    '/orders' => 'Orders/Index',
                    '/sync' => 'Sync/Index',
                    '/products' => 'Products/Dashboard',
                    '/inventory' => 'Inventory/Dashboard',
                    '/kol' => 'Kol/Overview',
                    '/marketing' => 'Marketing/Overview',
                    '/guides' => 'Guides/Index',
                    '/accounting' => 'Finance/ProfitDashboard',
                    '/settings' => 'Settings/Index',
                    '/customers' => 'Customers/Dashboard',
                    '/purchase' => 'Procurement/Orders/Index',
                    default => 'Shell/ModuleOverview',
                }));
        }
    }

    public function test_users_without_formal_or_prototype_permission_receive_forbidden_for_all_modules(): void
    {
        $user = $this->userWith([], 'restricted-operator');
        foreach (['/orders', '/logistics', '/sync', '/customers', '/kol', '/marketing', '/inventory', '/purchase', '/procurement', '/products', '/guides', '/accounting', '/settings'] as $path) {
            $this->actingAs($user)->get($path)->assertForbidden();
        }
    }

    public function test_super_admin_can_access_all_formal_modules(): void
    {
        $user = $this->userWith([], 'super_admin');
        $this->actingAs($user)->get('/settings')->assertOk();
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->has('shellNavigation', 13));
    }

    public function test_root_contract_and_legacy_prototype_access_remain_unchanged(): void
    {
        $user = $this->userWith(['dashboard.view', 'prototype.view']);
        $this->actingAs($user)->get('/')->assertRedirectToRoute('dashboard');
        $this->actingAs($user)->get('/logistics/pending')->assertForbidden();
    }

    public function test_kol_orders_surface_requires_the_real_orders_permission_not_prototype(): void
    {
        $prototypeOnly = $this->userWith(['dashboard.view', 'prototype.view'], 'prototype-only');
        $this->actingAs($prototypeOnly)->get('/kol/orders')->assertForbidden();

        $withOrdersView = $this->userWith(['dashboard.view', 'orders.view'], 'orders-view');
        $this->actingAs($withOrdersView)->get('/kol/orders')->assertOk();
    }

    public function test_no_public_registration_route_is_introduced(): void
    {
        $this->assertFalse(app('router')->has('register'));
        $this->post('/register')->assertNotFound();
    }
}
