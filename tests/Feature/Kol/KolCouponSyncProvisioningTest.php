<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use App\Modules\Kol\Application\AffiliateWp\CouponProvisioningService;
use App\Modules\Kol\Application\AffiliateWp\CouponSnapshotSyncService;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateCoupon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class KolCouponSyncProvisioningTest extends TestCase
{
    use RefreshDatabase;
    private const CONNECTION = 'test.example.com';

    private function manager(): User
    {
        $role = Role::create(['key' => 'coupon_manager_'.uniqid(), 'label' => 'Coupon Manager']);
        foreach (['kol.view', 'kol.operate', 'kol.affiliates.manage'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function kolWithAffiliate(User $owner, int $affiliateId = 42): Kol
    {
        $kol = Kol::create([
            'display_name' => 'Coupon KOL', 'status' => 'active', 'relationship_stage' => 'prospect',
            'owner_id' => $owner->id, 'approval_status' => 'approved', 'contact_email' => 'coupon@example.test',
        ]);
        KolAffiliateAccount::create([
            'connection_key' => self::CONNECTION, 'affiliate_id' => $affiliateId, 'kol_id' => $kol->id,
            'provider_status' => 'active', 'origin' => 'external_sync', 'sync_status' => 'synced',
            'reconciliation_status' => 'present', 'provisioning_status' => 'not_requested',
        ]);
        return $kol;
    }

    private function mockGateway(): AffiliateWpGateway&MockInterface
    {
        $mock = Mockery::mock(AffiliateWpGateway::class);
        $mock->shouldReceive('descriptor')->andReturn(['connection_key' => self::CONNECTION])->byDefault();
        $this->app->instance(AffiliateWpGateway::class, $mock);
        return $mock;
    }

    private function response(array $data): ConnectorResponse
    {
        return ConnectorResponse::fromArray(['ok' => true, 'contract_version' => '1', 'request_id' => uniqid('coupon-', true), 'connection_key' => self::CONNECTION, 'data' => $data]);
    }

    public function test_full_coupon_snapshot_includes_non_affiliate_and_exact_kol_projection(): void
    {
        $manager = $this->manager();
        $kol = $this->kolWithAffiliate($manager, 42);
        $mock = $this->mockGateway();
        $mock->shouldReceive('snapshot')->once()->with('coupons', null, null)->andReturn($this->response([
            'items' => [
                ['coupon_id' => 10, 'code' => ' KOL15 ', 'normalized_code' => 'kol15', 'discount_type' => 'percent', 'amount' => '15.000', 'status' => 'publish', 'date_expires' => null, 'linked_affiliate_id' => 42],
                ['coupon_id' => 11, 'code' => 'WELCOME', 'normalized_code' => 'welcome', 'discount_type' => 'fixed_cart', 'amount' => '10', 'status' => 'publish', 'date_expires' => null, 'linked_affiliate_id' => null],
            ], 'next_cursor' => null,
        ]));

        $result = $this->app->make(CouponSnapshotSyncService::class)->sync(self::CONNECTION, $manager);
        self::assertSame(2, $result['synced']);
        self::assertSame($kol->id, KolAffiliateCoupon::where('coupon_id', 10)->firstOrFail()->kol_id);
        self::assertNull(KolAffiliateCoupon::where('coupon_id', 11)->firstOrFail()->kol_id);
        self::assertSame('15', KolAffiliateCoupon::where('coupon_id', 10)->firstOrFail()->amount);
    }

    public function test_failed_partial_snapshot_does_not_mark_existing_coupon_missing(): void
    {
        $manager = $this->manager();
        KolAffiliateCoupon::create([
            'connection_key' => self::CONNECTION, 'coupon_id' => 99, 'code' => 'KEEP', 'normalized_code' => 'keep',
            'origin' => 'external_sync', 'sync_status' => 'synced', 'reconciliation_status' => 'present', 'provisioning_status' => 'not_requested',
        ]);
        $mock = $this->mockGateway();
        $mock->shouldReceive('snapshot')->once()->with('coupons', null, null)->andReturn($this->response(['items' => [], 'next_cursor' => 'next']));
        $mock->shouldReceive('snapshot')->once()->with('coupons', 'next', null)->andThrow(new \RuntimeException('page failed'));

        try { $this->app->make(CouponSnapshotSyncService::class)->sync(self::CONNECTION, $manager); } catch (\RuntimeException) {}
        self::assertSame('present', KolAffiliateCoupon::where('coupon_id', 99)->firstOrFail()->reconciliation_status);
    }

    public function test_coupon_provision_checks_live_and_sends_exact_six_field_contract(): void
    {
        $manager = $this->manager();
        $kol = $this->kolWithAffiliate($manager, 42);
        $mock = $this->mockGateway();
        $mock->shouldReceive('checkCoupon')->once()->with('KOL15')->andReturn($this->response(['available' => true, 'normalized_code' => 'kol15']));
        $captured = null; $capturedKey = null;
        $mock->shouldReceive('provisionCoupon')->once()->andReturnUsing(function (array $payload, string $key) use (&$captured, &$capturedKey): ConnectorResponse {
            $captured = $payload; $capturedKey = $key;
            return $this->response(['coupon_id' => 501, 'code' => 'KOL15', 'normalized_code' => 'kol15', 'affiliate_id' => 42, 'discount_type' => 'percent', 'amount' => '15', 'date_expires' => null]);
        });

        $row = $this->app->make(CouponProvisioningService::class)->provision(self::CONNECTION, [
            'kol_id' => $kol->id, 'code' => 'KOL15', 'discount_type' => 'percent', 'amount' => '15.00', 'expiry' => null,
        ], $manager);

        self::assertSame([
            'erp_kol_id' => $kol->id, 'affiliate_id' => 42, 'code' => 'KOL15', 'discount_type' => 'percent', 'amount' => '15', 'expiry' => null,
        ], $captured);
        self::assertSame($capturedKey, $row->provision_idempotency_key);
        self::assertSame(501, $row->coupon_id);
        self::assertSame('active', $row->provisioning_status);
    }

    public function test_local_mirror_conflict_hard_blocks_without_provider_call(): void
    {
        $manager = $this->manager();
        $kol = $this->kolWithAffiliate($manager, 42);
        KolAffiliateCoupon::create([
            'connection_key' => self::CONNECTION, 'coupon_id' => 8, 'code' => 'Taken', 'normalized_code' => 'taken',
            'origin' => 'external_sync', 'sync_status' => 'synced', 'reconciliation_status' => 'present', 'provisioning_status' => 'not_requested',
        ]);
        $mock = $this->mockGateway(); $mock->shouldNotReceive('checkCoupon'); $mock->shouldNotReceive('provisionCoupon');
        $this->expectException(DomainException::class);
        $this->app->make(CouponProvisioningService::class)->provision(self::CONNECTION, [
            'kol_id' => $kol->id, 'code' => ' TAKEN ', 'discount_type' => 'fixed_cart', 'amount' => '10', 'expiry' => null,
        ], $manager);
    }

    public function test_live_conflict_hard_blocks_before_placeholder_creation(): void
    {
        $manager = $this->manager();
        $kol = $this->kolWithAffiliate($manager, 42);
        $mock = $this->mockGateway();
        $mock->shouldReceive('checkCoupon')->once()->andReturn($this->response(['available' => false, 'coupon_id' => 9, 'code' => 'LIVE', 'linked_affiliate_id' => 88, 'normalized_code' => 'live']));
        $mock->shouldNotReceive('provisionCoupon');
        try {
            $this->app->make(CouponProvisioningService::class)->provision(self::CONNECTION, [
                'kol_id' => $kol->id, 'code' => 'LIVE', 'discount_type' => 'fixed_cart', 'amount' => '10', 'expiry' => null,
            ], $manager);
            self::fail('Expected live conflict.');
        } catch (DomainException) {}
        self::assertSame(0, KolAffiliateCoupon::count());
    }

    public function test_retry_after_provider_error_reuses_same_row_and_idempotency_key(): void
    {
        $manager = $this->manager(); $kol = $this->kolWithAffiliate($manager, 42); $mock = $this->mockGateway();
        $mock->shouldReceive('checkCoupon')->once()->andReturn($this->response(['available' => true, 'normalized_code' => 'retry']));
        $keys = [];
        $mock->shouldReceive('provisionCoupon')->twice()->andReturnUsing(function (array $payload, string $key) use (&$keys): ConnectorResponse {
            $keys[] = $key;
            if (count($keys) === 1) { throw new \RuntimeException('timeout'); }
            return $this->response(['coupon_id' => 77, 'code' => 'RETRY', 'normalized_code' => 'retry', 'affiliate_id' => 42, 'discount_type' => 'percent', 'amount' => '5', 'date_expires' => null]);
        });
        $service = $this->app->make(CouponProvisioningService::class);
        $first = $service->provision(self::CONNECTION, ['kol_id' => $kol->id, 'code' => 'RETRY', 'discount_type' => 'percent', 'amount' => '5', 'expiry' => null], $manager);
        self::assertSame('error', $first->provisioning_status);
        $second = $service->provision(self::CONNECTION, ['kol_id' => $kol->id, 'code' => 'RETRY', 'discount_type' => 'percent', 'amount' => '5', 'expiry' => null], $manager);
        self::assertSame($keys[0], $keys[1]);
        self::assertSame('active', $second->provisioning_status);
        self::assertSame(1, KolAffiliateCoupon::where('normalized_code', 'retry')->count());
    }

    public function test_readback_mismatch_never_reports_active(): void
    {
        $manager = $this->manager(); $kol = $this->kolWithAffiliate($manager, 42); $mock = $this->mockGateway();
        $mock->shouldReceive('checkCoupon')->once()->andReturn($this->response(['available' => true, 'normalized_code' => 'mismatch']));
        $mock->shouldReceive('provisionCoupon')->once()->andReturn($this->response(['coupon_id' => 78, 'code' => 'MISMATCH', 'normalized_code' => 'mismatch', 'affiliate_id' => 999, 'discount_type' => 'percent', 'amount' => '5', 'date_expires' => null]));
        $row = $this->app->make(CouponProvisioningService::class)->provision(self::CONNECTION, ['kol_id' => $kol->id, 'code' => 'MISMATCH', 'discount_type' => 'percent', 'amount' => '5', 'expiry' => null], $manager);
        self::assertSame('readback_mismatch', $row->provisioning_status);
        self::assertNull($row->coupon_id);
    }

    public function test_retry_rejects_payload_change_before_reusing_idempotency_key(): void
    {
        $manager = $this->manager(); $kol = $this->kolWithAffiliate($manager, 42); $mock = $this->mockGateway();
        $mock->shouldReceive('checkCoupon')->once()->andReturn($this->response(['available' => true, 'normalized_code' => 'stable']));
        $mock->shouldReceive('provisionCoupon')->once()->andThrow(new \RuntimeException('timeout'));
        $service = $this->app->make(CouponProvisioningService::class);
        $first = $service->provision(self::CONNECTION, ['kol_id' => $kol->id, 'code' => 'STABLE', 'discount_type' => 'percent', 'amount' => '5', 'expiry' => null], $manager);
        self::assertSame('error', $first->provisioning_status);
        $key = $first->provision_idempotency_key;

        try {
            $service->provision(self::CONNECTION, ['kol_id' => $kol->id, 'code' => 'STABLE', 'discount_type' => 'percent', 'amount' => '6', 'expiry' => null], $manager);
            self::fail('Expected immutable retry payload guard.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('exact original payload', $exception->getMessage());
        }
        self::assertSame($key, KolAffiliateCoupon::where('normalized_code', 'stable')->firstOrFail()->provision_idempotency_key);
    }

    public function test_malformed_provider_readback_becomes_explicit_mismatch_instead_of_exception(): void
    {
        $manager = $this->manager(); $kol = $this->kolWithAffiliate($manager, 42); $mock = $this->mockGateway();
        $mock->shouldReceive('checkCoupon')->once()->andReturn($this->response(['available' => true, 'normalized_code' => 'malformed']));
        $mock->shouldReceive('provisionCoupon')->once()->andReturn($this->response(['coupon_id' => 88, 'affiliate_id' => 42]));
        $row = $this->app->make(CouponProvisioningService::class)->provision(self::CONNECTION, [
            'kol_id' => $kol->id, 'code' => 'MALFORMED', 'discount_type' => 'percent', 'amount' => '5', 'expiry' => null,
        ], $manager);
        self::assertSame('readback_mismatch', $row->provisioning_status);
        self::assertSame('READBACK_MISMATCH', $row->last_error_code);
        self::assertNull($row->coupon_id);
    }

    public function test_erp_provisioned_coupon_provider_rebind_is_surfaced_without_silently_losing_kol(): void
    {
        $manager = $this->manager();
        $kol = $this->kolWithAffiliate($manager, 42);
        KolAffiliateCoupon::create([
            'connection_key' => self::CONNECTION, 'coupon_id' => 70, 'code' => 'BOUND', 'normalized_code' => 'bound',
            'discount_type' => 'percent', 'amount' => '5', 'affiliate_id' => 42, 'kol_id' => $kol->id,
            'origin' => 'erp_provisioned', 'sync_status' => 'synced', 'reconciliation_status' => 'present', 'provisioning_status' => 'active',
        ]);
        $mock = $this->mockGateway();
        $mock->shouldReceive('snapshot')->once()->with('coupons', null, null)->andReturn($this->response([
            'items' => [[
                'coupon_id' => 70, 'code' => 'BOUND', 'normalized_code' => 'bound', 'discount_type' => 'percent',
                'amount' => '5', 'status' => 'publish', 'date_expires' => null, 'linked_affiliate_id' => 99,
            ]], 'next_cursor' => null,
        ]));

        $this->app->make(CouponSnapshotSyncService::class)->sync(self::CONNECTION, $manager);
        $row = KolAffiliateCoupon::where('coupon_id', 70)->firstOrFail();
        self::assertSame(99, $row->affiliate_id);
        self::assertSame($kol->id, $row->kol_id);
        self::assertSame('erp_provisioned', $row->origin);
        self::assertSame('provider_binding_mismatch', $row->reconciliation_status);
    }

    public function test_provider_duplicate_codes_are_all_mirrored_and_marked_conflict(): void
    {
        $manager = $this->manager();
        $kol = $this->kolWithAffiliate($manager, 42);
        $mock = $this->mockGateway();
        $mock->shouldReceive('snapshot')->once()->with('coupons', null, null)->andReturn($this->response([
            'items' => [
                ['coupon_id' => 301, 'code' => 'DUP20', 'normalized_code' => 'dup20', 'discount_type' => 'percent', 'amount' => '20', 'status' => 'publish', 'date_expires' => null, 'linked_affiliate_id' => 42],
                ['coupon_id' => 302, 'code' => 'dup20', 'normalized_code' => 'dup20', 'discount_type' => 'percent', 'amount' => '20', 'status' => 'publish', 'date_expires' => null, 'linked_affiliate_id' => null],
            ], 'next_cursor' => null,
        ]));

        $result = $this->app->make(CouponSnapshotSyncService::class)->sync(self::CONNECTION, $manager);
        self::assertSame(2, $result['synced']);
        self::assertSame(2, KolAffiliateCoupon::where('normalized_code', 'dup20')->count());
        self::assertSame(['provider_code_conflict'], KolAffiliateCoupon::where('normalized_code', 'dup20')->pluck('reconciliation_status')->unique()->values()->all());
        self::assertSame($kol->id, KolAffiliateCoupon::where('coupon_id', 301)->firstOrFail()->kol_id);
    }

    public function test_provider_blank_coupon_code_is_mirrored_as_invalid_provider_fact(): void
    {
        $manager = $this->manager();
        $mock = $this->mockGateway();
        $mock->shouldReceive('snapshot')->once()->with('coupons', null, null)->andReturn($this->response([
            'items' => [
                ['coupon_id' => 401, 'code' => '', 'normalized_code' => '', 'discount_type' => 'fixed_cart', 'amount' => '5', 'status' => 'publish', 'date_expires' => null, 'linked_affiliate_id' => null],
            ], 'next_cursor' => null,
        ]));

        $result = $this->app->make(CouponSnapshotSyncService::class)->sync(self::CONNECTION, $manager);
        self::assertSame(1, $result['synced']);
        $row = KolAffiliateCoupon::where('coupon_id', 401)->firstOrFail();
        self::assertSame('', $row->code);
        self::assertSame('', $row->normalized_code);
        self::assertSame('provider_invalid_code', $row->reconciliation_status);
    }


    public function test_provider_custom_discount_type_is_mirrored_but_not_treated_as_erp_provisionable(): void
    {
        $manager = $this->manager();
        $mock = $this->mockGateway();
        $mock->shouldReceive('snapshot')->once()->with('coupons', null, null)->andReturn($this->response([
            'items' => [
                ['coupon_id' => 501, 'code' => 'BOGO-UAT', 'normalized_code' => 'bogo-uat', 'discount_type' => 'wbte_sc_bogo', 'amount' => '0', 'status' => 'publish', 'date_expires' => null, 'linked_affiliate_id' => null],
            ], 'next_cursor' => null,
        ]));

        $result = $this->app->make(CouponSnapshotSyncService::class)->sync(self::CONNECTION, $manager);
        self::assertSame(1, $result['synced']);
        $row = KolAffiliateCoupon::where('coupon_id', 501)->firstOrFail();
        self::assertSame('wbte_sc_bogo', $row->discount_type);
        self::assertSame('provider_unsupported_discount_type', $row->reconciliation_status);
    }

}
