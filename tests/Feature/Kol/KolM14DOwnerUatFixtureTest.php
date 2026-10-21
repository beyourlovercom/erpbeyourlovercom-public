<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\AffiliateWp\ReferralCommissionSyncService;
use App\Modules\Kol\Infrastructure\AffiliateWp\LocalUatAffiliateWpGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateReferral;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolM14DOwnerUatFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_uat_fake_projects_referrals_commissions_and_unresolved_provider_truth(): void
    {
        config(['services.andy_core.connection_key' => 'm14d-uat.test']);
        $role = Role::create(['key' => 'm14d_manager', 'label' => 'M1.4D Manager']);
        foreach (['kol.view', 'kol.views.manage', 'kol.affiliates.manage'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $manager = User::factory()->create(['is_active' => true]);
        $manager->roles()->attach($role);
        $kol = Kol::create([
            'display_name' => 'M1.4D UAT KOL', 'status' => 'active', 'relationship_stage' => 'prospect',
            'owner_id' => $manager->id, 'approval_status' => 'approved', 'contact_email' => 'm14d@example.test',
        ]);
        KolAffiliateAccount::create([
            'connection_key' => 'm14d-uat.test', 'affiliate_id' => 810002, 'kol_id' => $kol->id,
            'provider_status' => 'active', 'origin' => 'external_sync', 'sync_status' => 'synced',
            'reconciliation_status' => 'present', 'provisioning_status' => 'not_requested',
        ]);
        $order = Order::create([
            'internal_order_number' => 'ERP-990001', 'source_type' => 'woocommerce', 'order_kind' => 'customer',
            'external_order_number' => '990001', 'order_date' => '2026-09-01',
            'order_status' => 'pending_shipment', 'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce', 'connection_key' => 'm14d-uat.test', 'reference' => '990001',
        ]);

        $service = new ReferralCommissionSyncService(new LocalUatAffiliateWpGateway());
        $result = $service->sync('m14d-uat.test', $manager);
        self::assertSame(3, $result['synced']);
        self::assertSame(1, $result['affiliate_unresolved']);
        self::assertSame(1, $result['order_unresolved']);
        self::assertSame(2, KolAffiliateReferral::where('order_id', $order->id)->count());
        self::assertSame(2, KolAffiliateReferral::where('kol_id', $kol->id)->count());
        self::assertSame(1, KolAffiliateReferral::whereNull('kol_id')->count());

        $workspace = $service->workspace('m14d-uat.test', $manager);
        self::assertSame(3, $workspace['summary']['referrals']);
        self::assertSame('20.75', $workspace['summary']['commission_amount']);
        self::assertSame('USD', $workspace['summary']['currency']);
        self::assertSame(1, $workspace['summary']['by_status']['paid']['count']);
        self::assertSame('12.50', $workspace['summary']['by_status']['paid']['amount']);
    }
}
