<?php

namespace Tests\Feature\Kol;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\AffiliateWp\LocalUatAffiliateWpGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateCoupon;
use App\Modules\Kol\Providers\KolServiceProvider;
use Database\Seeders\KolM14BOwnerUatSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolM14COwnerUatFixtureTest extends TestCase
{
    use RefreshDatabase;

    private function bootFake(): array
    {
        config(['services.andy_core.uat_fake' => true]);
        (new KolServiceProvider($this->app))->register();
        self::assertInstanceOf(LocalUatAffiliateWpGateway::class, $this->app->make(AffiliateWpGateway::class));
        $this->seed(KolM14BOwnerUatSeeder::class);

        return [
            User::where('email', 'kol.manager@local.test')->firstOrFail(),
            User::where('email', 'kol.specialist@local.test')->firstOrFail(),
            Kol::where('contact_email', 'uat-m14b-active@example.test')->firstOrFail(),
        ];
    }

    public function test_coupon_fake_snapshot_includes_affiliate_and_non_affiliate_coupon(): void
    {
        [$manager, , $active] = $this->bootFake();
        $this->actingAs($manager)->post('/kol/affiliatewp/coupons/sync')->assertRedirect()->assertSessionHasNoErrors();

        self::assertSame(2, KolAffiliateCoupon::count());
        $affiliateCoupon = KolAffiliateCoupon::where('coupon_id', 840001)->firstOrFail();
        self::assertSame(830001, $affiliateCoupon->affiliate_id);
        self::assertSame($active->id, $affiliateCoupon->kol_id);
        self::assertNull(KolAffiliateCoupon::where('coupon_id', 840002)->firstOrFail()->kol_id);
    }

    public function test_manager_can_create_coupon_through_strict_fake_provider_readback(): void
    {
        [$manager, , $active] = $this->bootFake();
        $this->actingAs($manager)->post('/kol/affiliatewp/coupons/provision', [
            'kol_id' => $active->id,
            'code' => 'UAT-M14C-NEW',
            'discount_type' => 'percent',
            'amount' => '12.5',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $coupon = KolAffiliateCoupon::where('normalized_code', 'uat-m14c-new')->firstOrFail();
        self::assertSame('active', $coupon->provisioning_status);
        self::assertSame(830001, $coupon->affiliate_id);
        self::assertSame($active->id, $coupon->kol_id);
        self::assertNotNull($coupon->provision_idempotency_key);
    }

    public function test_live_conflict_is_hard_blocked_and_does_not_create_placeholder(): void
    {
        [$manager, , $active] = $this->bootFake();
        $this->actingAs($manager)->post('/kol/affiliatewp/coupons/provision', [
            'kol_id' => $active->id,
            'code' => 'UAT-EXISTING',
            'discount_type' => 'fixed_cart',
            'amount' => '10',
        ])->assertRedirect()->assertSessionHasErrors('coupon_provision');

        self::assertSame(0, KolAffiliateCoupon::where('normalized_code', 'uat-existing')->count());
    }

    public function test_readback_mismatch_is_explicit_and_retry_safe(): void
    {
        [$manager, , $active] = $this->bootFake();
        $this->actingAs($manager)->post('/kol/affiliatewp/coupons/provision', [
            'kol_id' => $active->id,
            'code' => 'UAT-READBACK',
            'discount_type' => 'fixed_product',
            'amount' => '5',
        ])->assertRedirect()->assertSessionHasErrors('coupon_provision');

        $coupon = KolAffiliateCoupon::where('normalized_code', 'uat-readback')->firstOrFail();
        self::assertSame('readback_mismatch', $coupon->provisioning_status);
        self::assertNull($coupon->coupon_id);
        self::assertNotNull($coupon->provision_idempotency_key);
    }

    public function test_specialist_cannot_sync_or_provision_coupon_provider_truth(): void
    {
        [, $specialist, $active] = $this->bootFake();
        $this->actingAs($specialist)->post('/kol/affiliatewp/coupons/sync')->assertForbidden();
        $this->actingAs($specialist)->post('/kol/affiliatewp/coupons/provision', [
            'kol_id' => $active->id,
            'code' => 'UAT-FORBIDDEN',
            'discount_type' => 'percent',
            'amount' => '5',
        ])->assertForbidden();
        self::assertSame(0, KolAffiliateCoupon::where('normalized_code', 'uat-forbidden')->count());
    }
}
