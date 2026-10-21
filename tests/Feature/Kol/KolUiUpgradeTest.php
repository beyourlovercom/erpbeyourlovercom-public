<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\AffiliateWp\LocalUatAffiliateWpGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolSalesPerformance;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolUiUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.andy_core.connection_key' => 'beyourlover.com',
            'kol.create_auto_provision.coupon_discount_type' => 'percent',
            'kol.create_auto_provision.coupon_amount' => '15',
            'kol.create_auto_provision.coupon_expiry' => null,
        ]);
        $this->app->instance(AffiliateWpGateway::class, new LocalUatAffiliateWpGateway);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'test_kol_ui_'.uniqid(), 'label' => 'Test']);
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

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'display_name' => 'UI Upgrade KOL',
            'status' => 'active',
            'relationship_stage' => 'prospect',
            'contact_email' => 'ui-upgrade-kol@example.test',
            'wordpress_password' => 'StrongPassword123',
            'wordpress_password_confirmation' => 'StrongPassword123',
            'affiliate_coupon_code' => 'UIUPGRADE15',
            'social_accounts' => [],
        ], $overrides);
    }

    // === Contact fields micro-adjustment ===

    public function test_foreign_contact_fields_persist_with_email_default_preferred_method(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->post('/kol/library', $this->basePayload([
            'owner_id' => $admin->id,
            'contact_email' => 'kol@example.test',
            'contact_phone' => '+1 555 0100',
            'contact_whatsapp' => '+1 555 0199',
            'other_contact' => 'Line: kol-line',
        ]))->assertRedirect();

        $kol = Kol::where('display_name', 'UI Upgrade KOL')->firstOrFail();
        $this->assertSame('kol@example.test', $kol->contact_email);
        $this->assertSame('+1 555 0199', $kol->contact_whatsapp);
        $this->assertSame('Line: kol-line', $kol->other_contact);
        $this->assertSame('email', $kol->preferred_contact_method);
    }

    public function test_explicit_preferred_contact_method_is_persisted(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->post('/kol/library', $this->basePayload([
            'owner_id' => $admin->id,
            'preferred_contact_method' => 'whatsapp',
        ]))->assertRedirect();

        $kol = Kol::where('display_name', 'UI Upgrade KOL')->firstOrFail();
        $this->assertSame('whatsapp', $kol->preferred_contact_method);
    }

    // === Business profile ===

    public function test_business_profile_facts_persist(): void
    {
        $admin = $this->adminUser();
        Product::create(['source_type' => 'manual', 'woocommerce_product_id' => null, 'sku' => 'MW810A', 'name' => 'Test Product', 'product_type' => 'simple']);

        $this->actingAs($admin)->post('/kol/library', $this->basePayload([
            'owner_id' => $admin->id,
            'level' => '头部',
            'gender' => '女',
            'categories' => ['Beauty'],
            'products' => ['MW810A'],
            'content_format' => '短视频',
            'product_quantity' => 12,
            'internal_contact' => 'Emma',
            'mcn_agency' => 'ACME MCN',
            'is_mcn_managed' => true,
        ]))->assertRedirect();

        $kol = Kol::where('display_name', 'UI Upgrade KOL')->firstOrFail();
        $this->assertSame('头部', $kol->level);
        $this->assertSame('女', $kol->gender);
        $this->assertSame(['Beauty'], $kol->categories);
        $this->assertSame(['MW810A'], $kol->products);
        $this->assertSame('短视频', $kol->content_format);
        $this->assertSame(12, $kol->product_quantity);
        $this->assertSame('Emma', $kol->internal_contact);
        $this->assertSame('ACME MCN', $kol->mcn_agency);
        $this->assertTrue($kol->is_mcn_managed);
    }

    public function test_tax_and_payout_facts_are_not_exposed_on_the_list_projection(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin)->post('/kol/library', $this->basePayload([
            'owner_id' => $admin->id,
            'tax_id' => 'TAX-SECRET-001',
            'payout_method' => 'paypal',
            'payout_account' => 'secret@paypal.test',
        ]))->assertRedirect();

        $response = $this->actingAs($admin)->get('/kol/library');
        $response->assertOk();
        $data = $response->original->getData()['page']['props']['kols']['data'] ?? [];
        $this->assertNotEmpty($data);
        foreach ($data as $row) {
            $this->assertArrayNotHasKey('tax_id', $row);
            $this->assertArrayNotHasKey('payout_account', $row);
            $this->assertArrayNotHasKey('payout_method', $row);
        }
    }

    // === Additive historical order count ===

    public function test_sales_performance_entries_are_additive_and_derive_the_lifetime_total(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin)->post('/kol/library', $this->basePayload([
            'owner_id' => $admin->id,
            'sales_performance_entries' => [
                ['order_count' => 5, 'recorded_at' => '2026-01-01', 'source' => 'campaign-a'],
                ['order_count' => 3, 'recorded_at' => '2026-02-01', 'source' => 'campaign-b'],
            ],
        ]))->assertRedirect();

        $kol = Kol::where('display_name', 'UI Upgrade KOL')->firstOrFail();
        $this->assertSame(2, KolSalesPerformance::where('kol_id', $kol->id)->count());
        $this->assertSame(8, $kol->historicalOrderCount());

        $this->actingAs($admin)->patch("/kol/library/{$kol->id}", [
            'display_name' => $kol->display_name,
            'status' => $kol->status,
            'relationship_stage' => $kol->relationship_stage,
            'sales_performance_entries' => [
                ['order_count' => 2, 'recorded_at' => '2026-03-01'],
            ],
        ])->assertRedirect();

        $this->assertSame(3, KolSalesPerformance::where('kol_id', $kol->id)->count());
        $this->assertSame(10, $kol->fresh()->historicalOrderCount());
    }

    public function test_legacy_business_profile_does_not_block_adding_sales_performance(): void
    {
        $admin = $this->adminUser();
        $kol = Kol::create([
            'display_name' => 'Legacy KOL',
            'status' => 'active',
            'relationship_stage' => 'prospect',
            'owner_id' => $admin->id,
            'level' => 'A',
            'categories' => ['Lifestyle'],
            'products' => ['LEGACY-SKU'],
            'content_format' => 'Short Video',
        ]);

        $this->actingAs($admin)->patch("/kol/library/{$kol->id}", [
            'display_name' => $kol->display_name,
            'status' => $kol->status,
            'relationship_stage' => $kol->relationship_stage,
            'owner_id' => $admin->id,
            'level' => 'A',
            'categories' => ['Lifestyle'],
            'products' => ['LEGACY-SKU'],
            'content_format' => 'Short Video',
            'social_accounts' => [],
            'sales_performance_entries' => [[
                'order_count' => 7,
                'recorded_at' => '2026-08-28',
                'source' => 'legacy-uat',
                'note' => 'legacy compatibility',
            ]],
        ])->assertRedirect();

        $this->assertDatabaseHas('kol_sales_performance', [
            'kol_id' => $kol->id,
            'order_count' => 7,
            'source' => 'legacy-uat',
        ]);
        $this->assertSame(7, $kol->fresh()->historicalOrderCount());
    }

    public function test_historical_order_count_appears_in_the_list_and_detail_projections(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin)->post('/kol/library', $this->basePayload([
            'owner_id' => $admin->id,
            'sales_performance_entries' => [['order_count' => 4, 'recorded_at' => '2026-01-01']],
        ]))->assertRedirect();
        $kol = Kol::where('display_name', 'UI Upgrade KOL')->firstOrFail();

        $index = $this->actingAs($admin)->get('/kol/library');
        $row = collect($index->original->getData()['page']['props']['kols']['data'])->firstWhere('id', $kol->id);
        $this->assertSame(4, $row['historical_order_count']);

        $show = $this->actingAs($admin)->get("/kol/library/{$kol->id}");
        $this->assertSame(4, $show->original->getData()['page']['props']['kol']['historical_order_count']);
    }

    // === Dashboard (canonical-only) ===

    public function test_overview_reports_canonical_metrics_and_marks_unconnected_metrics_null(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin)->post('/kol/library', $this->basePayload([
            'owner_id' => $admin->id,
            'level' => '头部',
            'relationship_stage' => 'long_term',
        ]))->assertRedirect();

        $response = $this->actingAs($admin)->get('/kol');
        $response->assertOk();
        $overview = $response->original->getData()['page']['props']['overview'];

        $this->assertGreaterThanOrEqual(1, $overview['total']);
        $this->assertGreaterThanOrEqual(1, $overview['long_term']);
        $this->assertArrayHasKey('头部', $overview['by_level']);
        $this->assertNull($overview['pending_publication_count']);
        $this->assertNull($overview['pending_commission_amount']);
    }

    public function test_specialist_overview_does_not_expose_owner_ranking(): void
    {
        $specialist = $this->userWith(['kol.view', 'kol.operate']);
        $response = $this->actingAs($specialist)->get('/kol');
        $response->assertOk();
        $overview = $response->original->getData()['page']['props']['overview'];
        $this->assertSame([], $overview['owner_ranking']);
    }

    // === 红人库 dense filters ===

    public function test_library_list_can_be_filtered_by_level_city_and_content_format(): void
    {
        $admin = $this->adminUser();
        Kol::create(['display_name' => 'Match', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $admin->id, 'level' => '头部', 'city' => 'Austin', 'content_format' => '直播']);
        Kol::create(['display_name' => 'NoMatch', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $admin->id, 'level' => '尾部', 'city' => 'Denver', 'content_format' => '图文']);

        $response = $this->actingAs($admin)->get('/kol/library?level='.urlencode('头部').'&city=Austin&content_format='.urlencode('直播'));
        $response->assertOk();
        $data = $response->original->getData()['page']['props']['kols']['data'];
        $this->assertCount(1, $data);
        $this->assertSame('Match', $data[0]['display_name']);
    }

    public function test_library_list_can_be_filtered_by_mcn_state(): void
    {
        $admin = $this->adminUser();
        Kol::create(['display_name' => 'MCN Managed', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $admin->id, 'is_mcn_managed' => true]);
        Kol::create(['display_name' => 'Independent', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $admin->id, 'is_mcn_managed' => false]);

        $response = $this->actingAs($admin)->get('/kol/library?mcn=yes');
        $data = $response->original->getData()['page']['props']['kols']['data'];
        $this->assertCount(1, $data);
        $this->assertSame('MCN Managed', $data[0]['display_name']);
    }

    // === Specialist scope is preserved with the new fields ===

    public function test_specialist_cannot_widen_scope_via_owner_filter_on_library(): void
    {
        $specialist = $this->userWith(['kol.view', 'kol.operate']);
        $admin = $this->adminUser();
        $own = Kol::create(['display_name' => 'Own', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $specialist->id]);
        $other = Kol::create(['display_name' => 'Other', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $admin->id]);

        $response = $this->actingAs($specialist)->get('/kol/library?owner_id='.$admin->id);
        $response->assertOk();
        $ids = array_column($response->original->getData()['page']['props']['kols']['data'], 'id');
        $this->assertContains($own->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }
}
