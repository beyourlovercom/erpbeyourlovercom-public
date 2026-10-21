<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\AffiliateWp\AffiliateBindingService;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AffiliateBindingTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'test_binding_'.uniqid(), 'label' => 'Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function manager(): User
    {
        return $this->userWith(['kol.view', 'kol.operate', 'kol.approvals.manage', 'kol.affiliates.manage']);
    }

    private function makeKol(User $owner, string $name = 'Target KOL'): Kol
    {
        return Kol::create([
            'display_name' => $name,
            'status' => 'active',
            'relationship_stage' => 'prospect',
            'owner_id' => $owner->id,
            'approval_status' => 'approved',
        ]);
    }

    private function account(int $affiliateId, array $overrides = []): KolAffiliateAccount
    {
        return KolAffiliateAccount::create(array_merge([
            'connection_key' => 'test.example.com',
            'affiliate_id' => $affiliateId,
            'display_name' => 'External Creator',
            'email' => 'external@example.com',
            'provider_status' => 'pending',
            'origin' => 'external_sync',
            'sync_status' => 'synced',
            'reconciliation_status' => 'present',
            'provisioning_status' => 'not_requested',
        ], $overrides));
    }

    private function service(): AffiliateBindingService
    {
        return $this->app->make(AffiliateBindingService::class);
    }

    public function test_manager_binds_external_affiliate_to_existing_kol_without_mutating_provider_facts(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);
        $account = $this->account(1001);

        $bound = $this->service()->bind('test.example.com', $account->id, $kol->id, $manager);

        self::assertSame($kol->id, $bound->kol_id);
        self::assertSame('pending', $bound->provider_status);
        self::assertSame('external_sync', $bound->origin);
    }

    public function test_repeating_exact_same_binding_is_idempotent(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);
        $account = $this->account(1002);

        $this->service()->bind('test.example.com', $account->id, $kol->id, $manager);
        $second = $this->service()->bind('test.example.com', $account->id, $kol->id, $manager);

        self::assertSame($kol->id, $second->kol_id);
        self::assertSame(1, KolAffiliateAccount::where('affiliate_id', 1002)->count());
    }

    public function test_binding_rejects_affiliate_already_bound_elsewhere(): void
    {
        $manager = $this->manager();
        $first = $this->makeKol($manager, 'First');
        $second = $this->makeKol($manager, 'Second');
        $account = $this->account(1003, ['kol_id' => $first->id]);

        $this->expectException(DomainException::class);
        $this->service()->bind('test.example.com', $account->id, $second->id, $manager);
    }

    public function test_binding_rejects_kol_already_bound_to_another_affiliate_same_connection(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);
        $this->account(1004, ['kol_id' => $kol->id]);
        $account = $this->account(1005);

        $this->expectException(DomainException::class);
        $this->service()->bind('test.example.com', $account->id, $kol->id, $manager);
    }

    public function test_conversion_creates_approved_kol_and_preserves_external_provider_status(): void
    {
        $manager = $this->manager();
        $owner = $this->userWith(['kol.view', 'kol.operate']);
        $account = $this->account(1101, ['display_name' => 'Provider Creator', 'email' => 'creator@example.com']);

        $result = $this->service()->convert('test.example.com', $account->id, $owner->id, null, $manager);
        $kol = $result['kol']->fresh();
        $affiliate = $result['affiliate']->fresh();

        self::assertSame('Provider Creator', $kol->display_name);
        self::assertSame($owner->id, $kol->owner_id);
        self::assertSame('creator@example.com', $kol->contact_email);
        self::assertSame('active', $kol->status);
        self::assertSame('prospect', $kol->relationship_stage);
        self::assertSame('approved', $kol->approval_status);
        self::assertDatabaseHas('kol_approval_decisions', [
            'kol_id' => $kol->id,
            'outcome' => 'approved',
            'source' => 'privileged_creator',
        ]);
        self::assertSame($kol->id, $affiliate->kol_id);
        self::assertSame('erp_conversion', $affiliate->origin);
        self::assertSame('pending', $affiliate->provider_status);
    }

    public function test_conversion_rejects_already_bound_affiliate_without_creating_second_kol(): void
    {
        $manager = $this->manager();
        $owner = $this->userWith(['kol.view']);
        $existing = $this->makeKol($owner);
        $account = $this->account(1102, ['kol_id' => $existing->id]);
        $before = Kol::count();

        try {
            $this->service()->convert('test.example.com', $account->id, $owner->id, null, $manager);
            self::fail('Expected conversion conflict.');
        } catch (DomainException) {
            self::assertSame($before, Kol::count());
        }
    }

    public function test_specialist_cannot_bind_or_convert_through_application_service(): void
    {
        $specialist = $this->userWith(['kol.view', 'kol.operate']);
        $kol = $this->makeKol($specialist);
        $account = $this->account(1201);

        try {
            $this->service()->bind('test.example.com', $account->id, $kol->id, $specialist);
            self::fail('Expected authorization failure.');
        } catch (AuthorizationException) {
            self::assertNull($account->fresh()->kol_id);
        }
    }

    public function test_manager_routes_bind_and_convert_while_specialist_is_forbidden(): void
    {
        $gateway = Mockery::mock(AffiliateWpGateway::class);
        $gateway->shouldReceive('descriptor')->andReturn(['connection_key' => 'test.example.com']);
        $this->app->instance(AffiliateWpGateway::class, $gateway);

        $manager = $this->manager();
        $owner = $this->userWith(['kol.view', 'kol.operate']);
        $kol = $this->makeKol($owner);
        $bindAccount = $this->account(1301);
        $convertAccount = $this->account(1302, ['display_name' => 'Converted Route']);

        $this->actingAs($manager)->post('/kol/affiliatewp/bind', [
            'account_id' => $bindAccount->id,
            'kol_id' => $kol->id,
        ])->assertRedirect();
        self::assertSame($kol->id, $bindAccount->fresh()->kol_id);

        $this->actingAs($manager)->post('/kol/affiliatewp/convert', [
            'account_id' => $convertAccount->id,
            'owner_id' => $owner->id,
        ])->assertRedirect();
        self::assertNotNull($convertAccount->fresh()->kol_id);

        $specialist = $this->userWith(['kol.view', 'kol.operate']);
        $this->actingAs($specialist)->post('/kol/affiliatewp/bind', [
            'account_id' => $this->account(1303)->id,
            'kol_id' => $kol->id,
        ])->assertForbidden();
    }
}
