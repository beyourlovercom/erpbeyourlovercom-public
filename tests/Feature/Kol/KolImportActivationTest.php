<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\ImportActivation\KolImportActivationService;
use App\Modules\Kol\Application\ImportPreview\KolImportPreviewService;
use App\Modules\Kol\Application\OwnerMapping\KolOwnerMappingService;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolImportActivationBatch;
use App\Modules\Kol\Infrastructure\Models\KolOwnerSourceMapping;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationBatch;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolImportActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_require_administrator_kol_authority(): void
    {
        $manager = $this->userWith(['kol.view', 'kol.operate', 'kol.affiliates.manage', 'kol.approvals.manage']);
        $this->actingAs($manager)->get('/kol/import-activation')->assertForbidden();
        $admin = $this->admin();
        $this->actingAs($admin)->get('/kol/import-activation')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Kol/ImportActivation'));
    }

    public function test_prepare_is_evidence_only_and_unmapped_owner_fails_closed(): void
    {
        $admin = $this->admin();
        $batch = $this->batch();
        $this->row($batch, 2, ['Contact Email' => 'creator@example.com', 'Influencer`s Link/URL' => 'https://instagram.com/creator', 'Belongs to' => 'Emma']);
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $admin);
        $before = [Kol::count(), KolAffiliateAccount::count()];

        $plan = $this->app->make(KolImportActivationService::class)->prepare($preview->id, $admin);

        self::assertSame(1, $plan->candidate_count);
        self::assertSame(0, $plan->ready_count);
        self::assertSame(1, $plan->blocked_count);
        self::assertSame('blocked', $plan->items->first()->readiness_status);
        self::assertContains('owner_unresolved', $plan->items->first()->block_reasons);
        self::assertSame($before, [Kol::count(), KolAffiliateAccount::count()]);
    }

    public function test_deterministic_owner_makes_candidate_ready_and_ambiguous_optional_fields_are_omitted(): void
    {
        $admin = $this->admin();
        $owner = $this->ownerUser('ERP Emma');
        $batch = $this->batch();
        $this->row($batch, 2, ['Contact Email'=>'creator@example.com','Influencer`s Link/URL'=>'https://instagram.com/creator','Belongs to'=>'Emma','Phone'=>'111','City'=>'Austin']);
        $this->row($batch, 3, ['Contact Email'=>'creator@example.com','Influencer`s Link/URL'=>'https://instagram.com/creator','Belongs to'=>'Emma','Phone'=>'222','City'=>'Dallas']);
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $admin);
        $this->app->make(KolOwnerMappingService::class)->saveSourceMapping($preview->id, 'Emma', $owner->id, $admin);

        $plan = $this->app->make(KolImportActivationService::class)->prepare($preview->id, $admin);
        $item = $plan->items->first();
        self::assertSame(1, $plan->ready_count);
        self::assertSame('ready', $item->readiness_status);
        self::assertSame($owner->id, $item->effective_owner_id);
        self::assertArrayNotHasKey('contact_phone', $item->canonical_proposal);
        self::assertArrayNotHasKey('shipping_city', $item->canonical_proposal);
    }

    public function test_local_apply_creates_exactly_one_kol_and_replay_is_idempotent(): void
    {
        config(['kol.canonical_import_enabled' => true]);
        $admin = $this->admin();
        $owner = $this->ownerUser('ERP Emma');
        $batch = $this->batch();
        $this->row($batch, 2, ['Contact Email'=>'apply@example.com','Influencer`s Link/URL'=>'https://instagram.com/apply','Followers'=>'25K','Belongs to'=>'Emma','国 Country'=>'USA']);
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $admin);
        $this->app->make(KolOwnerMappingService::class)->saveSourceMapping($preview->id, 'Emma', $owner->id, $admin);
        $service = $this->app->make(KolImportActivationService::class);
        $plan = $service->prepare($preview->id, $admin);
        $affiliateBefore = KolAffiliateAccount::count();

        $applied = $service->apply($plan, $plan->plan_checksum, $plan->ready_count, $admin);
        self::assertSame('applied', $applied->status);
        self::assertSame(1, Kol::where('contact_email', 'apply@example.com')->count());
        $kol = Kol::where('contact_email', 'apply@example.com')->firstOrFail();
        self::assertSame($owner->id, $kol->owner_id);
        self::assertNull($kol->approval_manager_id);
        self::assertNull($kol->finance_owner_id);
        self::assertSame('https://instagram.com/apply', $kol->socialAccounts()->firstOrFail()->profile_url);
        self::assertSame($affiliateBefore, KolAffiliateAccount::count());

        $replayed = $service->apply($applied, $plan->plan_checksum, $plan->ready_count, $admin);
        self::assertSame('applied', $replayed->status);
        self::assertSame(1, Kol::where('contact_email', 'apply@example.com')->count());
    }

    public function test_apply_rechecks_exact_identity_and_blocks_new_duplicate_without_partial_import(): void
    {
        config(['kol.canonical_import_enabled' => true]);
        $admin = $this->admin();
        $owner = $this->ownerUser('ERP Emma');
        $batch = $this->batch();
        $this->row($batch, 2, ['Contact Email'=>'first@example.com','Influencer`s Link/URL'=>'https://instagram.com/first','Belongs to'=>'Emma']);
        $this->row($batch, 3, ['Contact Email'=>'second@example.com','Influencer`s Link/URL'=>'https://instagram.com/second','Belongs to'=>'Emma']);
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $admin);
        $this->app->make(KolOwnerMappingService::class)->saveSourceMapping($preview->id, 'Emma', $owner->id, $admin);
        $service = $this->app->make(KolImportActivationService::class);
        $plan = $service->prepare($preview->id, $admin);
        self::assertSame(2, $plan->ready_count);

        Kol::create(['display_name'=>'Late Duplicate','status'=>'active','relationship_stage'=>'prospect','contact_email'=>'second@example.com']);
        try {
            $service->apply($plan, $plan->plan_checksum, $plan->ready_count, $admin);
            self::fail('Late exact duplicate should block the entire apply.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
        self::assertSame(0, Kol::where('contact_email', 'first@example.com')->count());
        self::assertSame(1, Kol::where('contact_email', 'second@example.com')->count());
        self::assertSame('prepared', $plan->fresh()->status);
    }

    public function test_apply_is_environment_locked_by_default_and_confirmation_must_match_plan(): void
    {
        $admin = $this->admin();
        $owner = $this->ownerUser('ERP Emma');
        $batch = $this->batch();
        $this->row($batch, 2, ['Contact Email'=>'locked@example.com','Belongs to'=>'Emma']);
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $admin);
        $this->app->make(KolOwnerMappingService::class)->saveSourceMapping($preview->id, 'Emma', $owner->id, $admin);
        $service = $this->app->make(KolImportActivationService::class);
        $plan = $service->prepare($preview->id, $admin);

        config(['kol.canonical_import_enabled' => false]);
        try { $service->apply($plan, $plan->plan_checksum, 1, $admin); self::fail('Environment lock should block apply.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { self::assertSame(403, $e->getStatusCode()); }
        config(['kol.canonical_import_enabled' => true]);
        try { $service->apply($plan, str_repeat('f', 64), 1, $admin); self::fail('Wrong checksum should block apply.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { self::assertSame(422, $e->getStatusCode()); }
        self::assertSame(0, Kol::where('contact_email', 'locked@example.com')->count());
    }

    private function batch(): KolReconciliationBatch
    {
        return KolReconciliationBatch::create([
            'source_type' => 'xlsx', 'original_filename' => '0902-网红订单.xlsx',
            'checksum' => str_repeat('c', 64), 'connection_key' => 'test.example.com', 'status' => 'preview',
        ]);
    }

    private function row(KolReconciliationBatch $batch, int $number, array $raw): KolReconciliationRow
    {
        $email = isset($raw['Contact Email']) ? strtolower(trim((string) $raw['Contact Email'])) : null;
        return KolReconciliationRow::create([
            'batch_id' => $batch->id, 'source_row_number' => $number,
            'row_hash' => hash('sha256', json_encode($raw)), 'raw_source' => $raw,
            'normalized_identity' => ['email'=>$email,'social'=>[],'key'=>$email ? 'email:'.$email : null],
            'source_identity_key' => $email ? 'email:'.$email : null,
            'kol_status' => 'unresolved', 'affiliate_status' => 'missing', 'reconciliation_status' => 'unresolved',
        ]);
    }

    private function admin(): User
    {
        return $this->userWith(['kol.view','kol.operate','kol.approvals.manage','kol.affiliates.manage','system.permissions.manage'], 'Admin');
    }

    private function ownerUser(string $name): User
    {
        return $this->userWith(['kol.view'], $name);
    }

    private function userWith(array $keys, string $name = 'User'): User
    {
        $role = Role::create(['key' => 'test_activation_'.uniqid(), 'label' => 'Test']);
        foreach ($keys as $key) $role->permissions()->attach(Permission::firstOrCreate(['key'=>$key], ['label'=>$key]));
        $user = User::factory()->create(['is_active'=>true, 'name'=>$name]);
        $user->roles()->attach($role);
        return $user;
    }
}
