<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\AffiliateWp\LocalUatAffiliateWpGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolSocialAccount;
use App\Modules\Kol\Infrastructure\Models\KolSocialMetric;
use App\Modules\Kol\Infrastructure\Models\SavedView;
use App\Modules\Shell\Infrastructure\Models\UserUiPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'test_kol_'.uniqid(), 'label' => 'Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function adminUser(): User
    {
        return $this->userWith(['kol.view', 'kol.operate', 'kol.views.manage']);
    }

    private function specialistUser(): User
    {
        return $this->userWith(['kol.view', 'kol.operate']);
    }

    private function enableCreateAutoProvisionFake(): void
    {
        config([
            'services.andy_core.connection_key' => 'beyourlover.com',
            'kol.create_auto_provision.coupon_discount_type' => 'percent',
            'kol.create_auto_provision.coupon_amount' => '15',
            'kol.create_auto_provision.coupon_expiry' => null,
        ]);
        $this->app->instance(AffiliateWpGateway::class, new LocalUatAffiliateWpGateway);
    }

    private function makeKol(array $attrs = []): Kol
    {
        return Kol::create(array_merge([
            'display_name' => 'Test KOL',
            'status' => 'active',
            'relationship_stage' => 'prospect',
        ], $attrs));
    }

    // === Schema and Model ===

    public function test_kol_can_be_created_and_retrieved(): void
    {
        $kol = $this->makeKol(['display_name' => 'Jane', 'country_code' => 'US', 'language' => 'en']);
        $this->assertDatabaseHas('kols', ['display_name' => 'Jane', 'country_code' => 'US']);
        $this->assertNull($kol->archived_at);
    }

    public function test_kol_social_account_and_metric_persist(): void
    {
        $kol = $this->makeKol();
        $sa = KolSocialAccount::create(['kol_id' => $kol->id, 'platform' => 'instagram', 'is_active' => true]);
        KolSocialMetric::create(['social_account_id' => $sa->id, 'followers' => 150000, 'raw_followers_value' => '150K', 'measured_at' => now()]);

        $this->assertDatabaseHas('kol_social_accounts', ['kol_id' => $kol->id, 'platform' => 'instagram']);
        $this->assertDatabaseHas('kol_social_metrics', ['social_account_id' => $sa->id, 'followers' => 150000]);

        $sa->refresh();
        $this->assertNotNull($sa->latestMetric);
        $this->assertSame(150000, $sa->latestMetric->followers);
    }

    public function test_hundred_k_plus_scope_returns_correct_kols(): void
    {
        $bigKol = $this->makeKol(['display_name' => 'BigKOL']);
        $smallKol = $this->makeKol(['display_name' => 'SmallKOL']);

        $sa1 = KolSocialAccount::create(['kol_id' => $bigKol->id, 'platform' => 'instagram', 'is_active' => true]);
        KolSocialMetric::create(['social_account_id' => $sa1->id, 'followers' => 200000, 'measured_at' => now()]);

        $sa2 = KolSocialAccount::create(['kol_id' => $smallKol->id, 'platform' => 'instagram', 'is_active' => true]);
        KolSocialMetric::create(['social_account_id' => $sa2->id, 'followers' => 50000, 'measured_at' => now()]);

        $results = Kol::hundredKPlus()->pluck('id')->all();
        $this->assertContains($bigKol->id, $results);
        $this->assertNotContains($smallKol->id, $results);
    }

    public function test_archive_soft_deletes_with_timestamp(): void
    {
        $kol = $this->makeKol();
        $kol->update(['archived_at' => now()]);
        $this->assertTrue($kol->isArchived());
        $this->assertNotContains($kol->id, Kol::active()->pluck('id')->all());
    }

    // === Authorization ===

    public function test_guest_cannot_access_kol_overview(): void
    {
        $this->get('/kol')->assertRedirect('/login');
    }

    public function test_user_without_kol_view_cannot_access_library(): void
    {
        $user = $this->userWith(['dashboard.view']);
        $this->actingAs($user)->get('/kol/library')->assertForbidden();
    }

    public function test_user_with_kol_view_can_access_overview(): void
    {
        $user = $this->userWith(['kol.view']);
        $this->actingAs($user)->get('/kol')->assertOk();
        $this->actingAs($user)->get('/kol/overview')->assertRedirect('/kol');
    }

    public function test_user_with_kol_view_can_access_library(): void
    {
        $user = $this->userWith(['kol.view']);
        $this->actingAs($user)->get('/kol/library')->assertOk();
    }

    public function test_user_without_kol_operate_cannot_create_kol(): void
    {
        $user = $this->userWith(['kol.view']);
        $this->actingAs($user)->get('/kol/library/create')->assertForbidden();
    }

    public function test_user_with_kol_operate_can_create_kol(): void
    {
        $user = $this->userWith(['kol.view', 'kol.operate']);
        $this->actingAs($user)->get('/kol/library/create')->assertOk();
    }

    // === Data Scope ===

    public function test_specialist_can_only_see_own_kols(): void
    {
        $specialist = $this->specialistUser();
        $other = $this->adminUser();

        $ownKol = $this->makeKol(['display_name' => 'Own KOL', 'owner_id' => $specialist->id]);
        $otherKol = $this->makeKol(['display_name' => 'Other KOL', 'owner_id' => $other->id]);

        $response = $this->actingAs($specialist)->get('/kol/library');
        $response->assertOk();
        $data = $response->original->getData()['page']['props']['kols']['data'] ?? [];
        $ids = array_column($data, 'id');
        $this->assertContains($ownKol->id, $ids);
        $this->assertNotContains($otherKol->id, $ids);
    }

    public function test_library_projects_account_synced_grid_preference_namespace_and_value(): void
    {
        $user = $this->userWith(['kol.view']);
        UserUiPreference::create([
            'user_id' => $user->id,
            'namespace' => 'grid.kol.library.v1',
            'value' => ['columns' => [
                ['key' => 'followers', 'visible' => true],
                ['key' => 'account', 'visible' => false],
            ]],
        ]);

        $response = $this->actingAs($user)->get('/kol/library');
        $response->assertOk();
        $props = $response->original->getData()['page']['props'];

        self::assertSame('grid.kol.library.v1', $props['gridPreferenceNamespace']);
        self::assertSame('followers', $props['gridPreference']['columns'][0]['key']);
        self::assertFalse($props['gridPreference']['columns'][1]['visible']);
    }

    public function test_library_projects_current_wordpress_account_and_affiliate_id_without_binding_unbound_kol(): void
    {
        config(['services.andy_core.connection_key' => 'beyourlover.com']);
        $user = $this->userWith(['kol.view']);
        $bound = $this->makeKol(['display_name' => 'Bound Account KOL', 'owner_id' => $user->id]);
        $unbound = $this->makeKol(['display_name' => 'No Affiliate KOL', 'owner_id' => $user->id]);

        KolAffiliateAccount::create([
            'connection_key' => 'beyourlover.com',
            'affiliate_id' => 1430,
            'kol_id' => $bound->id,
            'wordpress_user_id' => 8811,
            'wordpress_user_login' => 'provider-login',
            'wordpress_user_email' => 'provider@example.test',
            'provider_status' => 'active',
            'origin' => 'external_sync',
            'sync_status' => 'synced',
            'reconciliation_status' => 'present',
            'provisioning_status' => 'not_requested',
        ]);

        $response = $this->actingAs($user)->get('/kol/library');
        $response->assertOk();
        $rows = collect($response->original->getData()['page']['props']['kols']['data'] ?? [])->keyBy('id');

        self::assertSame(1430, $rows[$bound->id]['affiliate_account']['affiliate_id']);
        self::assertSame(8811, $rows[$bound->id]['affiliate_account']['wordpress_user_id']);
        self::assertSame('provider-login', $rows[$bound->id]['affiliate_account']['wordpress_user_login']);
        self::assertArrayNotHasKey('wordpress_user_email', $rows[$bound->id]['affiliate_account']);
        self::assertArrayNotHasKey('provider_status', $rows[$bound->id]['affiliate_account']);
        self::assertNull($rows[$unbound->id]['affiliate_account']);
        self::assertNull(KolAffiliateAccount::where('kol_id', $unbound->id)->first(), 'List projection must never auto-bind/provision an unbound historical KOL.');
    }

    public function test_specialist_cannot_access_other_owner_kol_directly(): void
    {
        $specialist = $this->specialistUser();
        $other = $this->adminUser();
        $kol = $this->makeKol(['owner_id' => $other->id]);

        $this->actingAs($specialist)->get("/kol/library/{$kol->id}")->assertForbidden();
    }

    public function test_admin_can_see_all_kols(): void
    {
        $admin = $this->adminUser();
        $user2 = $this->adminUser();

        $kol1 = $this->makeKol(['owner_id' => $admin->id]);
        $kol2 = $this->makeKol(['owner_id' => $user2->id]);

        $response = $this->actingAs($admin)->get('/kol/library');
        $response->assertOk();
        $data = $response->original->getData()['page']['props']['kols']['data'] ?? [];
        $ids = array_column($data, 'id');
        $this->assertContains($kol1->id, $ids);
        $this->assertContains($kol2->id, $ids);
    }

    // === Create/Store ===

    public function test_admin_can_store_kol(): void
    {
        $admin = $this->adminUser();
        $this->enableCreateAutoProvisionFake();

        $this->actingAs($admin)->post('/kol/library', [
            'display_name' => 'New KOL',
            'status' => 'active',
            'relationship_stage' => 'prospect',
            'owner_id' => $admin->id,
            'contact_email' => 'admin-new-kol@example.test',
            'wordpress_password' => 'StrongPassword123',
            'wordpress_password_confirmation' => 'StrongPassword123',
            'affiliate_coupon_code' => 'ADMIN15',
            'social_accounts' => [],
        ])->assertRedirect();

        $this->assertDatabaseHas('kols', ['display_name' => 'New KOL']);
    }

    public function test_specialist_store_always_assigns_own_owner_id(): void
    {
        $specialist = $this->specialistUser();
        $admin = $this->adminUser();
        $this->enableCreateAutoProvisionFake();

        $this->actingAs($specialist)->post('/kol/library', [
            'display_name' => 'Specialist KOL',
            'status' => 'active',
            'relationship_stage' => 'prospect',
            'owner_id' => $admin->id,
            'contact_email' => 'specialist-new-kol@example.test',
            'wordpress_password' => 'StrongPassword123',
            'wordpress_password_confirmation' => 'StrongPassword123',
            'affiliate_coupon_code' => 'SPECIAL15',
            'social_accounts' => [],
        ])->assertRedirect();

        $kol = Kol::where('display_name', 'Specialist KOL')->firstOrFail();
        $this->assertSame($specialist->id, $kol->owner_id);
    }

    // === Saved Views ===

    public function test_user_without_kol_views_manage_cannot_create_saved_view(): void
    {
        $user = $this->specialistUser();
        $this->actingAs($user)->post('/kol/views', [
            'name' => 'My View',
            'visibility' => 'private',
        ])->assertForbidden();
    }

    public function test_admin_can_create_saved_view(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin)->post('/kol/views', [
            'name' => 'Emma清单',
            'visibility' => 'shared',
            'filters_json' => ['owner_id' => '1'],
            'is_pinned' => true,
        ])->assertStatus(201);

        $this->assertDatabaseHas('saved_views', ['name' => 'Emma清单', 'module' => 'kol', 'visibility' => 'shared']);
    }

    public function test_saved_view_owner_can_delete_own_view(): void
    {
        $admin = $this->adminUser();
        $view = SavedView::create([
            'module' => 'kol', 'name' => 'Test View', 'view_type' => 'custom',
            'created_by' => $admin->id, 'visibility' => 'private',
            'filters_json' => [], 'is_pinned' => false, 'is_default' => false, 'sort_order' => 0,
        ]);

        $this->actingAs($admin)->delete("/kol/views/{$view->id}")->assertStatus(204);
        $this->assertDatabaseMissing('saved_views', ['id' => $view->id]);
    }

    public function test_saved_view_non_owner_cannot_delete_view(): void
    {
        $admin1 = $this->adminUser();
        $admin2 = $this->adminUser();
        $view = SavedView::create([
            'module' => 'kol', 'name' => 'Admin1 View', 'view_type' => 'custom',
            'created_by' => $admin1->id, 'visibility' => 'private',
            'filters_json' => [], 'is_pinned' => false, 'is_default' => false, 'sort_order' => 0,
        ]);

        $this->actingAs($admin2)->delete("/kol/views/{$view->id}")->assertForbidden();
    }
}
