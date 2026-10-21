<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use App\Modules\Kol\Application\AffiliateWp\ReferralCommissionSyncService;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateReferral;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class KolReferralCommissionSyncTest extends TestCase
{
    use RefreshDatabase;
    private const CONNECTION = 'test.example.com';

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'referral_'.uniqid(), 'label' => 'Referral Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function kolWithAffiliate(User $owner, int $affiliateId): Kol
    {
        $kol = Kol::create([
            'display_name' => 'Referral KOL '.$affiliateId,
            'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $owner->id,
            'approval_status' => 'approved', 'contact_email' => 'ref-'.$affiliateId.'@example.test',
        ]);
        KolAffiliateAccount::create([
            'connection_key' => self::CONNECTION, 'affiliate_id' => $affiliateId, 'kol_id' => $kol->id,
            'provider_status' => 'active', 'origin' => 'external_sync', 'sync_status' => 'synced',
            'reconciliation_status' => 'present', 'provisioning_status' => 'not_requested',
        ]);
        return $kol;
    }

    private function canonicalOrder(string $reference): Order
    {
        $order = Order::create([
            'internal_order_number' => 'ERP-'.$reference,
            'erp_order_id' => '007-0901-c'.$reference,
            'source_type' => 'woocommerce', 'order_kind' => 'customer', 'external_order_number' => $reference,
            'order_date' => '2026-09-01', 'order_status' => 'pending_shipment', 'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce', 'connection_key' => self::CONNECTION, 'reference' => $reference,
        ]);
        return $order;
    }

    private function response(array $data): ConnectorResponse
    {
        return ConnectorResponse::fromArray([
            'ok' => true, 'contract_version' => '1', 'request_id' => uniqid('ref-', true),
            'connection_key' => self::CONNECTION, 'data' => $data,
        ]);
    }

    private function item(int $referralId, int $affiliateId, string $reference, string $amount = '12.50', string $status = 'unpaid', ?int $payoutId = null): array
    {
        return [
            'referral_id' => $referralId, 'affiliate_id' => $affiliateId, 'reference' => $reference,
            'description' => 'Woo referral', 'amount' => $amount, 'currency' => 'USD', 'status' => $status,
            'context' => 'woocommerce', 'referred_at' => '2026-09-01T00:00:00+00:00', 'payout_id' => $payoutId,
        ];
    }

    private function mockGateway(): AffiliateWpGateway&MockInterface
    {
        $mock = Mockery::mock(AffiliateWpGateway::class);
        $mock->shouldReceive('descriptor')->andReturn(['connection_key' => self::CONNECTION])->byDefault();
        $this->app->instance(AffiliateWpGateway::class, $mock);
        return $mock;
    }

    public function test_full_snapshot_projects_exact_kol_and_canonical_order_and_allows_multiple_referrals_per_order(): void
    {
        $manager = $this->userWith(['kol.view', 'kol.views.manage', 'kol.affiliates.manage']);
        $kol = $this->kolWithAffiliate($manager, 42);
        $order = $this->canonicalOrder('9001');
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('snapshot')->once()->with('referrals', null, null)->andReturn($this->response([
            'items' => [$this->item(101, 42, '9001', '12.50', 'paid', 7001), $this->item(102, 42, '9001', '5', 'unpaid')],
            'next_cursor' => null,
        ]));

        $result = $this->app->make(ReferralCommissionSyncService::class)->sync(self::CONNECTION, $manager);
        self::assertSame(2, $result['synced']);
        self::assertSame(0, $result['order_unresolved']);
        self::assertSame(2, KolAffiliateReferral::where('order_id', $order->id)->count());
        self::assertSame([$kol->id], KolAffiliateReferral::pluck('kol_id')->unique()->values()->all());
        self::assertSame(7001, KolAffiliateReferral::where('referral_id', 101)->firstOrFail()->payout_id);
    }

    public function test_andy_core_v153_referred_at_and_zero_payout_sentinel_are_normalized(): void
    {
        $manager = $this->userWith(['kol.view', 'kol.views.manage', 'kol.affiliates.manage']);
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('snapshot')->once()->andReturn($this->response([
            'items' => [$this->item(150, 999, '150', '3.25', 'unpaid', 0)], 'next_cursor' => null,
        ]));

        $this->app->make(ReferralCommissionSyncService::class)->sync(self::CONNECTION, $manager);
        $row = KolAffiliateReferral::where('referral_id', 150)->firstOrFail();
        self::assertNull($row->payout_id);
        self::assertSame('2026-09-01', $row->referral_date->toDateString());
    }

    public function test_unresolved_affiliate_and_order_are_retained_without_guessing(): void
    {
        $manager = $this->userWith(['kol.view', 'kol.views.manage', 'kol.affiliates.manage']);
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('snapshot')->once()->andReturn($this->response(['items' => [$this->item(201, 999, 'missing-order')], 'next_cursor' => null]));
        $result = $this->app->make(ReferralCommissionSyncService::class)->sync(self::CONNECTION, $manager);
        $row = KolAffiliateReferral::firstOrFail();
        self::assertSame(1, $result['affiliate_unresolved']);
        self::assertSame(1, $result['order_unresolved']);
        self::assertNull($row->kol_id);
        self::assertNull($row->order_id);
        self::assertSame('missing', $row->affiliate_resolution_status);
        self::assertSame('unresolved', $row->order_resolution_status);
    }

    public function test_second_page_failure_applies_no_partial_changes(): void
    {
        $manager = $this->userWith(['kol.view', 'kol.views.manage', 'kol.affiliates.manage']);
        KolAffiliateReferral::create([
            'connection_key' => self::CONNECTION, 'referral_id' => 301, 'affiliate_id' => 1, 'order_reference' => 'old',
            'commission_amount' => '1', 'currency' => 'USD', 'provider_status' => 'paid', 'context' => 'woocommerce',
            'referral_date' => now(), 'affiliate_resolution_status' => 'missing', 'order_resolution_status' => 'unresolved',
            'reconciliation_status' => 'present', 'sync_status' => 'synced',
        ]);
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('snapshot')->once()->with('referrals', null, null)->andReturn($this->response(['items' => [$this->item(302, 1, 'new')], 'next_cursor' => 'page-2']));
        $gateway->shouldReceive('snapshot')->once()->with('referrals', 'page-2', null)->andThrow(new \RuntimeException('provider page failed'));
        try {
            $this->app->make(ReferralCommissionSyncService::class)->sync(self::CONNECTION, $manager);
            self::fail('Expected provider failure.');
        } catch (\RuntimeException) {
            self::assertDatabaseCount('kol_affiliate_referrals', 1);
            self::assertSame('present', KolAffiliateReferral::where('referral_id', 301)->firstOrFail()->reconciliation_status);
        }
    }

    public function test_complete_snapshot_marks_unseen_rows_missing_without_deleting_history(): void
    {
        $manager = $this->userWith(['kol.view', 'kol.views.manage', 'kol.affiliates.manage']);
        foreach ([401, 402] as $id) {
            KolAffiliateReferral::create([
                'connection_key' => self::CONNECTION, 'referral_id' => $id, 'affiliate_id' => 1, 'order_reference' => (string) $id,
                'commission_amount' => '1', 'currency' => 'USD', 'provider_status' => 'paid', 'context' => 'woocommerce',
                'referral_date' => now(), 'affiliate_resolution_status' => 'missing', 'order_resolution_status' => 'unresolved',
                'reconciliation_status' => 'present', 'sync_status' => 'synced',
            ]);
        }
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('snapshot')->once()->andReturn($this->response(['items' => [$this->item(401, 1, '401')], 'next_cursor' => null]));
        $result = $this->app->make(ReferralCommissionSyncService::class)->sync(self::CONNECTION, $manager);
        self::assertSame(1, $result['marked_missing']);
        self::assertSame('missing_from_latest_snapshot', KolAffiliateReferral::where('referral_id', 402)->firstOrFail()->reconciliation_status);
        self::assertDatabaseCount('kol_affiliate_referrals', 2);
    }

    public function test_specialist_cannot_sync_and_workspace_is_owner_scoped(): void
    {
        $owner = $this->userWith(['kol.view', 'kol.operate']);
        $other = $this->userWith(['kol.view', 'kol.operate']);
        $manager = $this->userWith(['kol.view', 'kol.views.manage', 'kol.affiliates.manage']);
        $kol = $this->kolWithAffiliate($owner, 501);
        $otherKol = $this->kolWithAffiliate($other, 502);
        foreach ([[$kol, 501, 601], [$otherKol, 502, 602]] as [$target, $affiliate, $referral]) {
            KolAffiliateReferral::create([
                'connection_key' => self::CONNECTION, 'referral_id' => $referral, 'affiliate_id' => $affiliate, 'kol_id' => $target->id,
                'order_reference' => (string) $referral, 'commission_amount' => '10', 'currency' => 'USD', 'provider_status' => 'unpaid',
                'context' => 'woocommerce', 'referral_date' => now(), 'affiliate_resolution_status' => 'bound', 'order_resolution_status' => 'unresolved',
                'reconciliation_status' => 'present', 'sync_status' => 'synced',
            ]);
        }
        $service = $this->app->make(ReferralCommissionSyncService::class);
        self::assertCount(1, $service->workspace(self::CONNECTION, $owner)['rows']);
        self::assertCount(2, $service->workspace(self::CONNECTION, $manager)['rows']);
        $this->expectException(AuthorizationException::class);
        $service->sync(self::CONNECTION, $owner);
    }

    public function test_commissions_workspace_replaces_placeholder_and_sync_route_is_manager_only(): void
    {
        $manager = $this->userWith(['kol.view', 'kol.views.manage', 'kol.affiliates.manage']);
        $gateway = $this->mockGateway();
        $gateway->shouldReceive('snapshot')->once()->andReturn($this->response(['items' => [], 'next_cursor' => null]));
        $this->actingAs($manager)->get('/kol/commissions')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Kol/Commissions')->where('canSync', true));
        $this->actingAs($manager)->post('/kol/commissions/sync')->assertRedirect();
        $specialist = $this->userWith(['kol.view']);
        $this->actingAs($specialist)->post('/kol/commissions/sync')->assertForbidden();
    }
}
