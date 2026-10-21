<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Sync\Infrastructure\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SyncWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_root_is_overview_and_order_operations_live_under_orders_workspace(): void
    {
        $user = $this->userWithSyncView();
        $manual = SyncRun::create([
            'run_type' => 'tracking_selected', 'provider_key' => 'woocommerce',
            'status' => 'completed', 'requested_items' => 1, 'processed_items' => 1,
            'succeeded_items' => 1, 'initiated_by' => $user->id,
            'started_at' => now()->subMinute(), 'completed_at' => now(),
        ]);
        SyncRun::create([
            'run_type' => 'woocommerce_order_incremental', 'provider_key' => 'woocommerce',
            'status' => 'completed', 'requested_items' => 2, 'processed_items' => 2,
            'succeeded_items' => 2, 'initiated_by' => null,
            'started_at' => now()->subMinutes(2), 'completed_at' => now()->subMinute(),
        ]);
        $this->actingAs($user)->get('/sync')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Sync/Index')
            ->has('domains', 5)
            ->where('domains.0.href', '/sync/orders')
            ->where('domains.1.href', '/sync/warehouses')
            ->where('domains.2.href', '/sync/kol')
            ->where('domains.3.href', '/sync/exports')
            ->where('domains.4.href', '/sync/wpapi')
            ->where('domains.4.key', 'wordpress')
            ->where('manualRuns.0.id', $manual->id)
            ->where('manualRuns.0.initiator', $user->name)
            ->where('automaticRuns.0.run_type', 'woocommerce_order_incremental')
            ->where('wooOrderSync.frequency', '每 1 小时')
            ->where('wooOrderSync.can_operate', false)
            ->where('wooOrderSync.manual_action', '/sync/woocommerce/incremental')
            ->where('wooOrderSync.advanced_href', '/sync/woocommerce')
            ->where('wooOrderSync.latest_run.run_type', 'woocommerce_order_incremental')
        );

        $this->actingAs($user)->get('/sync/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Sync/Orders')
            ->has('orders')
            ->has('syncRuns')
            ->has('queueSummary')
        );
    }


    public function test_sync_overview_exposes_manual_today_control_to_sync_operators(): void
    {
        config(['services.woocommerce.order_sync_enabled' => true]);
        $user = $this->userWithPermissions(['sync.view', 'sync.operate']);

        $this->actingAs($user)->get('/sync')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Sync/Index')
            ->where('wooOrderSync.enabled', true)
            ->where('wooOrderSync.frequency', '每 1 小时')
            ->where('wooOrderSync.can_operate', true)
            ->where('wooOrderSync.manual_action', '/sync/woocommerce/incremental')
        );
    }

    public function test_wordpress_connector_card_is_sync_scoped(): void
    {
        $user = $this->userWithPermissions(['sync.view']);
        $this->actingAs($user)->get('/sync')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Sync/Index')
            ->has('domains', 5)
            ->where('domains.4.href', '/sync/wpapi')
            ->where('domains.4.key', 'wordpress')
        );
    }

    public function test_secondary_sync_workspaces_are_real_pages(): void
    {
        $user = $this->userWithSyncView();
        $this->actingAs($user)->get('/sync/warehouses')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Sync/Warehouses')->has('warehouses'));
        $this->actingAs($user)->get('/sync/kol')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Sync/Kol')->has('platforms', 2));
        // Non-admin sync user sees only 2 exports (all + procurement); customer-order export is hidden.
        $this->actingAs($user)->get('/sync/exports')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Sync/Exports')->has('exports', 2));
    }

    public function test_sync_exports_shows_customer_order_export_only_to_admin(): void
    {
        $admin = $this->userWithPermissions(['sync.view', 'customers.export', 'system.permissions.manage']);
        $this->actingAs($admin)->get('/sync/exports')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Sync/Exports')->has('exports', 3));
    }

    private function userWithSyncView(): User
    {
        return $this->userWithPermissions(['sync.view']);
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['key' => 'sync-workspace-'.uniqid(), 'label' => 'Sync Workspace']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
