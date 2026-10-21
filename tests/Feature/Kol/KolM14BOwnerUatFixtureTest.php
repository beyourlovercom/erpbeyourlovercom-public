<?php

namespace Tests\Feature\Kol;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\AffiliateWp\LocalUatAffiliateWpGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Providers\KolServiceProvider;
use Database\Seeders\KolM14BOwnerUatSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolM14BOwnerUatFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_uat_fake_fixture_exercises_success_error_conflict_and_readback_without_live_provider(): void
    {
        config(['services.andy_core.uat_fake' => true]);
        (new KolServiceProvider($this->app))->register();
        self::assertInstanceOf(LocalUatAffiliateWpGateway::class, $this->app->make(AffiliateWpGateway::class));

        $this->seed(KolM14BOwnerUatSeeder::class);
        $manager = User::where('email', 'kol.manager@local.test')->firstOrFail();

        $ready = Kol::where('contact_email', 'uat-m14b-ready@example.test')->firstOrFail();
        $this->actingAs($manager)->post('/kol/affiliatewp/provision', ['kol_id' => $ready->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        self::assertSame('active', KolAffiliateAccount::where('kol_id', $ready->id)->firstOrFail()->provisioning_status);

        $providerError = Kol::where('contact_email', 'uat-m14b-provider-error@example.test')->firstOrFail();
        $this->actingAs($manager)->post('/kol/affiliatewp/provision', ['kol_id' => $providerError->id])
            ->assertRedirect()
            ->assertSessionHasErrors('affiliatewp_provision');
        $errorAccount = KolAffiliateAccount::where('kol_id', $providerError->id)->firstOrFail();
        self::assertSame('error', $errorAccount->provisioning_status);
        self::assertNotNull($errorAccount->provision_idempotency_key);

        $conflict = Kol::where('contact_email', 'uat-m14b-conflict@example.test')->firstOrFail();
        $this->actingAs($manager)->post('/kol/affiliatewp/provision', ['kol_id' => $conflict->id])
            ->assertRedirect()
            ->assertSessionHasErrors('affiliatewp_provision');
        self::assertSame('conflict', KolAffiliateAccount::where('kol_id', $conflict->id)->firstOrFail()->provisioning_status);

        $readback = Kol::where('contact_email', 'uat-m14b-readback@example.test')->firstOrFail();
        $this->actingAs($manager)->post('/kol/affiliatewp/provision', ['kol_id' => $readback->id])
            ->assertRedirect()
            ->assertSessionHasErrors('affiliatewp_provision');
        self::assertSame('readback_mismatch', KolAffiliateAccount::where('kol_id', $readback->id)->firstOrFail()->provisioning_status);
    }

    public function test_local_uat_seeder_is_repeatable_and_resets_fixture_state(): void
    {
        $this->seed(KolM14BOwnerUatSeeder::class);
        $ready = Kol::where('contact_email', 'uat-m14b-ready@example.test')->firstOrFail();
        KolAffiliateAccount::create([
            'connection_key' => (string) config('services.andy_core.connection_key'),
            'kol_id' => $ready->id,
            'affiliate_id' => 899999,
            'origin' => 'erp_provisioned',
            'sync_status' => 'synced',
            'reconciliation_status' => 'present',
            'provisioning_status' => 'active',
        ]);

        $this->seed(KolM14BOwnerUatSeeder::class);

        self::assertSame(9, Kol::where('contact_email', 'like', 'uat-m14b-%')->count());
        self::assertNull(KolAffiliateAccount::where('kol_id', $ready->id)->first());
        self::assertSame(3, KolAffiliateAccount::whereIn('kol_id', Kol::where('contact_email', 'like', 'uat-m14b-%')->pluck('id'))->count());
    }
}
