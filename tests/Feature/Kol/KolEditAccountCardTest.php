<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateCoupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class KolEditAccountCardTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECTION = 'beyourlover.com';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.andy_core.connection_key' => self::CONNECTION,
            'kol.create_auto_provision.coupon_discount_type' => 'percent',
            'kol.create_auto_provision.coupon_amount' => '15',
            'kol.create_auto_provision.coupon_expiry' => null,
        ]);
    }

    private function operator(): User
    {
        $role = Role::create(['key' => 'test_kol_edit_account_'.uniqid(), 'label' => 'KOL Operator']);
        foreach (['kol.view', 'kol.operate'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function kol(User $owner, array $overrides = []): Kol
    {
        return Kol::create(array_merge([
            'display_name' => 'Existing KOL',
            'status' => 'active',
            'relationship_stage' => 'prospect',
            'owner_id' => $owner->id,
            'contact_email' => 'existing-kol@example.test',
        ], $overrides));
    }

    private function gateway(): AffiliateWpGateway&MockInterface
    {
        $mock = Mockery::mock(AffiliateWpGateway::class);
        $mock->shouldReceive('descriptor')->andReturn(['connection_key' => self::CONNECTION])->byDefault();
        $this->app->instance(AffiliateWpGateway::class, $mock);

        return $mock;
    }

    private function response(array $data, string $requestId = 'req-edit-account'): ConnectorResponse
    {
        return ConnectorResponse::fromArray([
            'ok' => true,
            'contract_version' => '1',
            'request_id' => $requestId,
            'connection_key' => self::CONNECTION,
            'data' => $data,
        ]);
    }

    public function test_edit_page_exposes_exact_wordpress_affiliate_and_coupon_facts(): void
    {
        $user = $this->operator();
        $kol = $this->kol($user);
        KolAffiliateAccount::create([
            'connection_key' => self::CONNECTION,
            'affiliate_id' => 81001,
            'kol_id' => $kol->id,
            'wordpress_user_id' => 71001,
            'wordpress_user_login' => 'creator-login',
            'wordpress_user_email' => 'existing-kol@example.test',
            'provider_status' => 'active',
            'origin' => 'erp_provisioned',
            'sync_status' => 'synced',
            'reconciliation_status' => 'present',
            'provisioning_status' => 'active',
        ]);
        KolAffiliateCoupon::create([
            'connection_key' => self::CONNECTION,
            'coupon_id' => 91001,
            'code' => 'CREATOR15',
            'normalized_code' => 'creator15',
            'discount_type' => 'percent',
            'amount' => '15',
            'affiliate_id' => 81001,
            'kol_id' => $kol->id,
            'origin' => 'erp_provisioned',
            'sync_status' => 'synced',
            'reconciliation_status' => 'present',
            'provisioning_status' => 'active',
        ]);

        $this->actingAs($user)->get("/kol/library/{$kol->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Kol/Edit')
                ->where('affiliateAccount.wordpress_user_id', 71001)
                ->where('affiliateAccount.wordpress_user_login', 'creator-login')
                ->where('affiliateAccount.wordpress_user_email', 'existing-kol@example.test')
                ->where('affiliateAccount.affiliate_id', 81001)
                ->where('affiliateAccount.provider_status', 'active')
                ->where('affiliateAccount.coupon.code', 'CREATOR15'));
    }

    public function test_explicit_existing_kol_provision_creates_account_and_coupon_without_requiring_approval(): void
    {
        $user = $this->operator();
        $kol = $this->kol($user, ['approval_status' => 'pending']);
        $mock = $this->gateway();
        $mock->shouldReceive('checkAffiliateIdentity')->once()->with('existing-kol@example.test')
            ->andReturn($this->response(['available' => true], 'identity-ok'));
        $mock->shouldReceive('checkCoupon')->twice()->with('EXISTING15')
            ->andReturn($this->response(['available' => true, 'normalized_code' => 'existing15'], 'coupon-ok'));
        $mock->shouldReceive('provisionAffiliate')->once()
            ->withArgs(fn (array $payload, string $key) => $payload['erp_kol_id'] === $kol->id
                && $payload['email'] === 'existing-kol@example.test'
                && $payload['password'] === 'NewAccountPassword123'
                && $payload['existing_account_policy'] === 'reject'
                && $key !== '')
            ->andReturn($this->response([
                'affiliate_id' => 82001,
                'wp_user_id' => 72001,
                'user_email' => 'existing-kol@example.test',
                'affiliate_status' => 'active',
            ], 'affiliate-created'));
        $mock->shouldReceive('provisionCoupon')->once()
            ->andReturn($this->response([
                'coupon_id' => 92001,
                'code' => 'EXISTING15',
                'normalized_code' => 'existing15',
                'affiliate_id' => 82001,
                'discount_type' => 'percent',
                'amount' => '15',
                'date_expires' => null,
            ], 'coupon-created'));

        $this->actingAs($user)->postJson("/kol/library/{$kol->id}/affiliate-account", [
            'password' => 'NewAccountPassword123',
            'password_confirmation' => 'NewAccountPassword123',
            'affiliate_coupon_code' => 'EXISTING15',
        ])->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('affiliate_account.wordpress_user_id', 72001)
            ->assertJsonPath('affiliate_account.affiliate_id', 82001)
            ->assertJsonPath('affiliate_account.coupon.code', 'EXISTING15');

        $this->assertSame('pending', $kol->fresh()->approval_status);
        $this->assertSame('active', KolAffiliateAccount::where('kol_id', $kol->id)->sole()->provisioning_status);
        $this->assertSame('active', KolAffiliateCoupon::where('kol_id', $kol->id)->sole()->provisioning_status);
        $persisted = KolAffiliateAccount::where('kol_id', $kol->id)->get()->toJson()
            .KolAffiliateCoupon::where('kol_id', $kol->id)->get()->toJson();
        $this->assertStringNotContainsString('NewAccountPassword123', $persisted);
    }

    public function test_explicit_password_update_uses_exact_bound_ids_and_never_persists_password(): void
    {
        $user = $this->operator();
        $kol = $this->kol($user);
        KolAffiliateAccount::create([
            'connection_key' => self::CONNECTION,
            'affiliate_id' => 83001,
            'kol_id' => $kol->id,
            'wordpress_user_id' => 73001,
            'wordpress_user_email' => 'existing-kol@example.test',
            'provider_status' => 'active',
            'origin' => 'erp_provisioned',
            'sync_status' => 'synced',
            'reconciliation_status' => 'present',
            'provisioning_status' => 'active',
        ]);
        $mock = $this->gateway();
        $mock->shouldReceive('updateWordpressPassword')->once()
            ->withArgs(fn (int $userId, array $payload, string $key) => $userId === 73001
                && $payload === [
                    'erp_kol_id' => $kol->id,
                    'affiliate_id' => 83001,
                    'password' => 'ReplacementPassword123',
                ] && $key !== '')
            ->andReturn($this->response([
                'wordpress_user_id' => 73001,
                'affiliate_id' => 83001,
                'password_updated' => true,
            ], 'password-updated'));

        $this->actingAs($user)->postJson("/kol/library/{$kol->id}/wordpress-password", [
            'password' => 'ReplacementPassword123',
            'password_confirmation' => 'ReplacementPassword123',
        ])->assertOk()->assertJsonPath('message', 'WordPress 密码已更新并完成 exact read-back。');

        $persisted = Kol::whereKey($kol->id)->get()->toJson()
            .KolAffiliateAccount::where('kol_id', $kol->id)->get()->toJson();
        $this->assertStringNotContainsString('ReplacementPassword123', $persisted);
    }

    public function test_normal_kol_save_never_provisions_or_changes_wordpress_password(): void
    {
        $user = $this->operator();
        $kol = $this->kol($user);
        $mock = $this->gateway();
        $mock->shouldNotReceive('provisionAffiliate');
        $mock->shouldNotReceive('provisionCoupon');
        $mock->shouldNotReceive('updateWordpressPassword');

        $this->actingAs($user)->patch("/kol/library/{$kol->id}", [
            'display_name' => 'Renamed KOL',
            'status' => 'active',
            'relationship_stage' => 'prospect',
            'contact_email' => 'existing-kol@example.test',
            'social_accounts' => [],
        ])->assertRedirect(route('kol.library.show', $kol));

        $this->assertSame('Renamed KOL', $kol->fresh()->display_name);
        $this->assertDatabaseCount('kol_affiliate_accounts', 0);
        $this->assertDatabaseCount('kol_affiliate_coupons', 0);
    }
}
