<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\AffiliateWp\KolAffiliateBindingActivationService;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateReferral;
use App\Modules\Kol\Infrastructure\Models\KolImportActivationBatch;
use App\Modules\Kol\Infrastructure\Models\KolImportPreviewBatch;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolAffiliateBindingActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_p4b_migration_uses_mysql_safe_explicit_foreign_key_names(): void
    {
        $source = file_get_contents(database_path('migrations/2026_09_04_020000_create_kol_affiliate_binding_activation_tables.php'));
        self::assertIsString($source);
        preg_match_all("/'(kol_bind_[^']+_fk)'/", $source, $matches);
        $names = $matches[1] ?? [];
        self::assertCount(9, $names);
        foreach ($names as $name) {
            self::assertLessThanOrEqual(64, strlen($name), $name);
        }
        self::assertStringNotContainsString("->constrained('kol_", $source);
    }

    public function test_routes_require_elevated_administrator_kol_authority(): void
    {
        $manager = $this->userWith(['kol.view', 'kol.operate', 'kol.affiliates.manage', 'kol.approvals.manage']);
        $this->actingAs($manager)->get('/kol/affiliatewp/binding-activation')->assertForbidden();

        $this->actingAs($this->admin())->get('/kol/affiliatewp/binding-activation')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Kol/AffiliateWp/BindingActivation'));
    }

    public function test_prepare_is_evidence_only_and_projects_exact_unbound_affiliate_ready(): void
    {
        $admin = $this->admin();
        [$activation, $kol] = $this->activationWithKol($admin, 'ready@example.com');
        $account = $this->account(501, 'ready@example.com');
        $activation->items()->firstOrFail()->update(['affiliate_evidence' => ['account_ids' => [$account->id]]]);
        $referral = $this->referral(501, 9001);
        $beforeKolCount = Kol::count();

        $plan = $this->service()->prepare($activation->id, $admin);

        self::assertSame(1, $plan->eligible_kol_count);
        self::assertSame(1, $plan->ready_count);
        self::assertSame('ready_for_binding', $plan->items->first()->readiness_status);
        self::assertSame($kol->id, $plan->items->first()->canonical_kol_id);
        self::assertSame($account->id, $plan->items->first()->affiliate_account_id);
        self::assertSame(1, $plan->items->first()->referral_impact_count);
        self::assertNull($account->fresh()->kol_id);
        self::assertNull($referral->fresh()->kol_id);
        self::assertSame($beforeKolCount, Kol::count());
    }

    public function test_prepare_classifies_already_bound_no_affiliate_and_conflicts_fail_closed(): void
    {
        $admin = $this->admin();
        [$activation, $boundKol] = $this->activationWithKol($admin, 'bound@example.com');
        $bound = $this->account(601, 'bound@example.com', ['kol_id' => $boundKol->id]);
        $activation->items()->firstOrFail()->update(['affiliate_evidence' => ['account_ids' => [$bound->id]]]);

        $noAffiliateKol = $this->appendKolItem($activation, $admin, 'none@example.com');
        $conflictKol = $this->appendKolItem($activation, $admin, 'conflict@example.com');
        $first = $this->account(602, 'conflict@example.com');
        $second = $this->account(603, 'conflict@example.com');
        $activation->items()->where('created_kol_id', $conflictKol->id)->firstOrFail()
            ->update(['affiliate_evidence' => ['account_ids' => [$first->id, $second->id]]]);
        $activation->update(['candidate_count' => 3]);

        $plan = $this->service()->prepare($activation->id, $admin);
        $byEmail = $plan->items->keyBy(fn ($item) => ($item->exact_identity['emails'] ?? [null])[0]);

        self::assertSame('already_bound', $byEmail['bound@example.com']->readiness_status);
        self::assertSame('no_affiliate', $byEmail['none@example.com']->readiness_status);
        self::assertSame('blocked', $byEmail['conflict@example.com']->readiness_status);
        self::assertContains('multiple_affiliates', $byEmail['conflict@example.com']->block_reasons);
        self::assertSame(0, $plan->ready_count);
        self::assertSame(1, $plan->already_bound_count);
        self::assertSame(1, $plan->no_affiliate_count);
        self::assertSame(1, $plan->blocked_count);
        self::assertNotNull($noAffiliateKol);
    }

    public function test_apply_uses_existing_bind_authority_reresolves_referrals_and_preserves_provider_facts(): void
    {
        config(['kol.affiliate_binding_activation_enabled' => true]);
        $admin = $this->admin();
        [$activation, $kol] = $this->activationWithKol($admin, 'apply-bind@example.com');
        $account = $this->account(701, 'apply-bind@example.com', ['provider_status' => 'pending', 'origin' => 'external_sync']);
        $activation->items()->firstOrFail()->update(['affiliate_evidence' => ['account_ids' => [$account->id]]]);
        $referral = $this->referral(701, 9101, ['commission_amount' => '12.340000', 'currency' => 'USD', 'provider_status' => 'unpaid', 'payout_id' => 77]);
        $providerBefore = $referral->only(['referral_id','affiliate_id','order_reference','description','commission_amount','currency','provider_status','context','payout_id','order_resolution_status','reconciliation_status','sync_status']);
        $kolCount = Kol::count();
        $plan = $this->service()->prepare($activation->id, $admin);

        $applied = $this->service()->apply($plan, $plan->plan_checksum, $plan->ready_count, $admin);

        self::assertSame('applied', $applied->status);
        self::assertSame($kol->id, $account->fresh()->kol_id);
        self::assertSame('pending', $account->fresh()->provider_status);
        self::assertSame('external_sync', $account->fresh()->origin);
        self::assertSame($kol->id, $referral->fresh()->kol_id);
        self::assertSame('bound', $referral->fresh()->affiliate_resolution_status);
        self::assertSame($providerBefore, $referral->fresh()->only(array_keys($providerBefore)));
        self::assertSame($kolCount, Kol::count());

        $replay = $this->service()->apply($applied, $plan->plan_checksum, $plan->ready_count, $admin);
        self::assertSame('applied', $replay->status);
        self::assertSame($kolCount, Kol::count());
        self::assertSame(1, KolAffiliateAccount::where('affiliate_id', 701)->count());
    }

    public function test_apply_rechecks_current_mirror_and_blocks_entire_batch_on_drift(): void
    {
        config(['kol.affiliate_binding_activation_enabled' => true]);
        $admin = $this->admin();
        [$activation] = $this->activationWithKol($admin, 'first-bind@example.com');
        $first = $this->account(801, 'first-bind@example.com');
        $activation->items()->firstOrFail()->update(['affiliate_evidence' => ['account_ids' => [$first->id]]]);
        $secondKol = $this->appendKolItem($activation, $admin, 'second-bind@example.com');
        $second = $this->account(802, 'second-bind@example.com');
        $activation->items()->where('created_kol_id', $secondKol->id)->firstOrFail()->update(['affiliate_evidence' => ['account_ids' => [$second->id]]]);
        $activation->update(['candidate_count' => 2]);
        $plan = $this->service()->prepare($activation->id, $admin);
        self::assertSame(2, $plan->ready_count);

        $second->update(['email' => 'changed@example.com', 'wordpress_user_email' => 'changed@example.com']);
        try {
            $this->service()->apply($plan, $plan->plan_checksum, $plan->ready_count, $admin);
            self::fail('Current mirror drift must block the entire batch.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
        self::assertNull($first->fresh()->kol_id);
        self::assertNull($second->fresh()->kol_id);
        self::assertSame('prepared', $plan->fresh()->status);
    }

    public function test_apply_is_environment_locked_and_confirmation_must_match_exact_plan(): void
    {
        $admin = $this->admin();
        [$activation] = $this->activationWithKol($admin, 'locked-bind@example.com');
        $account = $this->account(901, 'locked-bind@example.com');
        $activation->items()->firstOrFail()->update(['affiliate_evidence' => ['account_ids' => [$account->id]]]);
        $plan = $this->service()->prepare($activation->id, $admin);

        config(['kol.affiliate_binding_activation_enabled' => false]);
        try { $this->service()->apply($plan, $plan->plan_checksum, 1, $admin); self::fail('Environment lock must block apply.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { self::assertSame(403, $e->getStatusCode()); }

        config(['kol.affiliate_binding_activation_enabled' => true]);
        try { $this->service()->apply($plan, str_repeat('f', 64), 1, $admin); self::fail('Checksum mismatch must block apply.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { self::assertSame(422, $e->getStatusCode()); }
        self::assertNull($account->fresh()->kol_id);
    }

    public function test_production_authorized_service_path_is_forbidden_outside_production(): void
    {
        $admin = $this->admin();
        [$activation] = $this->activationWithKol($admin, 'prod-only@example.com');
        $account = $this->account(1001, 'prod-only@example.com');
        $activation->items()->firstOrFail()->update(['affiliate_evidence' => ['account_ids' => [$account->id]]]);
        $plan = $this->service()->prepare($activation->id, $admin);

        try {
            $this->service()->applyProductionAuthorized(
                $plan,
                $plan->plan_checksum,
                $plan->ready_count,
                $admin,
                KolAffiliateBindingActivationService::PRODUCTION_CONFIRMATION,
            );
            self::fail('Production-authorized path must be unavailable outside Production.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
        self::assertNull($account->fresh()->kol_id);
    }

    public function test_production_web_apply_stays_locked_while_exact_console_command_can_apply(): void
    {
        config(['kol.affiliate_binding_activation_enabled' => true]);
        $admin = $this->admin();
        [$activation, $kol] = $this->activationWithKol($admin, 'production-console@example.com');
        $account = $this->account(1002, 'production-console@example.com');
        $activation->items()->firstOrFail()->update(['affiliate_evidence' => ['account_ids' => [$account->id]]]);
        $referral = $this->referral(1002, 9202);
        $plan = $this->service()->prepare($activation->id, $admin);
        $originalEnvironment = $this->app->environment();
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            try {
                $this->service()->apply($plan, $plan->plan_checksum, $plan->ready_count, $admin);
                self::fail('Web apply must remain locked in Production.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                self::assertSame(403, $e->getStatusCode());
            }

            $this->artisan('kol:affiliate-binding-apply-production', [
                'batch_id' => $plan->id,
                '--plan-checksum' => $plan->plan_checksum,
                '--ready-count' => (string) $plan->ready_count,
                '--actor-id' => (string) $admin->id,
                '--confirm' => 'WRONG',
            ])->assertExitCode(1);
            self::assertNull($account->fresh()->kol_id);

            $this->artisan('kol:affiliate-binding-apply-production', [
                'batch_id' => $plan->id,
                '--plan-checksum' => $plan->plan_checksum,
                '--ready-count' => (string) $plan->ready_count,
                '--actor-id' => (string) $admin->id,
                '--confirm' => 'APPLY_EXACT_KOL_AFFILIATE_BINDINGS',
                '--json' => true,
            ])->assertExitCode(0);
        } finally {
            $this->app->detectEnvironment(fn (): string => $originalEnvironment);
        }

        self::assertSame($kol->id, $account->fresh()->kol_id);
        self::assertSame($kol->id, $referral->fresh()->kol_id);
        self::assertSame('bound', $referral->fresh()->affiliate_resolution_status);
        $this->assertDatabaseHas('audit_records', [
            'action' => 'kol.affiliate_binding_activation.applied',
            'entity_id' => (string) $plan->id,
            'after->execution_surface' => 'production_console',
        ]);
    }

    private function service(): KolAffiliateBindingActivationService
    {
        return $this->app->make(KolAffiliateBindingActivationService::class);
    }

    private function activationWithKol(User $admin, string $email): array
    {
        $reconciliation = KolReconciliationBatch::create([
            'source_type' => 'xlsx', 'original_filename' => 'P4B-UAT.xlsx', 'checksum' => str_repeat('b', 64),
            'connection_key' => 'test.example.com', 'creator_user_id' => $admin->id, 'status' => 'preview',
        ]);
        $preview = KolImportPreviewBatch::create([
            'reconciliation_batch_id' => $reconciliation->id, 'creator_user_id' => $admin->id, 'status' => 'generated',
            'total_source_rows' => 1, 'candidate_count' => 1, 'unresolved_source_rows' => 0,
        ]);
        $activation = KolImportActivationBatch::create([
            'import_preview_batch_id' => $preview->id, 'reconciliation_batch_id' => $reconciliation->id,
            'source_checksum' => $reconciliation->checksum, 'plan_version' => 1, 'status' => 'applied',
            'candidate_count' => 1, 'ready_count' => 1, 'blocked_count' => 0, 'existing_count' => 0, 'skipped_count' => 0,
            'plan_checksum' => str_repeat('a', 64), 'generated_by' => $admin->id, 'applied_by' => $admin->id,
            'generated_at' => now()->subMinute(), 'applied_at' => now()->subSeconds(30),
        ]);
        $kol = $this->makeKol($admin, $email);
        $this->appendActivationItem($activation, $kol, $email);
        return [$activation->fresh('items'), $kol];
    }

    private function appendKolItem(KolImportActivationBatch $activation, User $owner, string $email): Kol
    {
        $kol = $this->makeKol($owner, $email);
        $this->appendActivationItem($activation, $kol, $email);
        return $kol;
    }

    private function appendActivationItem(KolImportActivationBatch $activation, Kol $kol, string $email): void
    {
        $activation->items()->create([
            'cluster_key' => hash('sha256', $email), 'readiness_status' => 'applied', 'preview_status' => 'new_candidate',
            'block_reasons' => [], 'effective_owner_id' => $kol->owner_id,
            'canonical_proposal' => ['display_name' => $kol->display_name, 'contact_email' => $email],
            'social_proposal' => [], 'exact_identity' => ['emails' => [$email], 'profile_urls' => [], 'tokens' => ['email:'.$email]],
            'existing_guard' => ['status' => 'none', 'kol_ids' => []], 'source_owner_evidence' => [],
            'affiliate_evidence' => ['account_ids' => []], 'history_summary' => [],
            'created_kol_id' => $kol->id, 'applied_by' => $kol->owner_id, 'applied_at' => now()->subSeconds(20),
        ]);
    }

    private function makeKol(User $owner, string $email): Kol
    {
        return Kol::create([
            'display_name' => ucfirst(strstr($email, '@', true) ?: 'Creator'), 'status' => 'active',
            'relationship_stage' => 'prospect', 'owner_id' => $owner->id, 'contact_email' => $email,
            'approval_status' => 'approved',
        ]);
    }

    private function account(int $affiliateId, string $email, array $overrides = []): KolAffiliateAccount
    {
        return KolAffiliateAccount::create(array_merge([
            'connection_key' => 'test.example.com', 'affiliate_id' => $affiliateId, 'display_name' => 'Affiliate '.$affiliateId,
            'email' => $email, 'wordpress_user_email' => $email, 'provider_status' => 'active', 'origin' => 'external_sync',
            'sync_status' => 'synced', 'reconciliation_status' => 'present', 'provisioning_status' => 'not_requested',
        ], $overrides));
    }

    private function referral(int $affiliateId, int $referralId, array $overrides = []): KolAffiliateReferral
    {
        return KolAffiliateReferral::create(array_merge([
            'connection_key' => 'test.example.com', 'referral_id' => $referralId, 'affiliate_id' => $affiliateId,
            'order_reference' => 'ORDER-'.$referralId, 'description' => 'Historical referral', 'commission_amount' => '5.000000',
            'currency' => 'USD', 'provider_status' => 'unpaid', 'context' => 'sale', 'referral_date' => now()->subDay(),
            'affiliate_resolution_status' => 'unbound', 'order_resolution_status' => 'unresolved', 'reconciliation_status' => 'present',
            'sync_status' => 'synced', 'last_synced_at' => now()->subHour(), 'last_successful_sync_at' => now()->subHour(),
        ], $overrides));
    }

    private function admin(): User
    {
        return $this->userWith(['kol.view','kol.operate','kol.approvals.manage','kol.affiliates.manage','system.permissions.manage'], 'Admin');
    }

    private function userWith(array $keys, string $name = 'User'): User
    {
        $role = Role::create(['key' => 'test_binding_activation_'.uniqid(), 'label' => 'Test']);
        foreach ($keys as $key) $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        $user = User::factory()->create(['is_active' => true, 'name' => $name]);
        $user->roles()->attach($role);
        return $user;
    }
}
