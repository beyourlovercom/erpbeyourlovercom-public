<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\ImportPreview\KolImportPreviewService;
use App\Modules\Kol\Application\OwnerMapping\KolOwnerMappingService;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolOwnerCandidateDecision;
use App\Modules\Kol\Infrastructure\Models\KolOwnerSourceMapping;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationBatch;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolOwnerMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_require_manager_and_affiliate_authority(): void
    {
        $specialist = $this->userWith(['kol.view', 'kol.operate']);
        $this->actingAs($specialist)->get('/kol/owner-mapping')->assertForbidden();

        $approvalOnly = $this->userWith(['kol.view', 'kol.approvals.manage']);
        $this->actingAs($approvalOnly)->get('/kol/owner-mapping')->assertForbidden();

        $manager = $this->manager();
        $this->actingAs($manager)->get('/kol/owner-mapping')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Kol/OwnerMapping'));
    }

    public function test_single_source_owner_mapping_resolves_candidates_without_canonical_mutation(): void
    {
        $manager = $this->manager();
        $target = $this->ownerUser('ERP Emma');
        $batch = $this->batch();
        $this->row($batch, 2, ['Contact Email' => 'one@example.com', 'Belongs to' => 'Emma']);
        $this->row($batch, 3, ['Contact Email' => 'two@example.com', 'Belongs to' => 'Emma']);
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $manager);
        $before = [Kol::count(), KolAffiliateAccount::count()];

        $service = $this->app->make(KolOwnerMappingService::class);
        $initial = $service->workspace($manager, $preview->id);
        self::assertSame(2, $initial['summary']['total_candidates']);
        self::assertSame(0, $initial['summary']['resolved_candidates']);
        self::assertSame(2, $initial['summary']['unresolved_candidates']);
        self::assertSame(2, $initial['source_owners'][0]['single_candidate_count']);

        $mapped = $service->saveSourceMapping($preview->id, 'Emma', $target->id, $manager);
        self::assertSame(2, $mapped['summary']['resolved_candidates']);
        self::assertSame(0, $mapped['summary']['unresolved_candidates']);
        self::assertSame($target->id, $mapped['source_owners'][0]['mapping']['mapped_user_id']);
        self::assertSame(1, KolOwnerSourceMapping::count());
        self::assertSame($before, [Kol::count(), KolAffiliateAccount::count()]);

        $service->saveSourceMapping($preview->id, 'Emma', $target->id, $manager);
        self::assertSame(1, KolOwnerSourceMapping::count());
    }

    public function test_multi_owner_candidate_fails_closed_until_explicit_decision_and_survives_p2_regeneration(): void
    {
        $manager = $this->manager();
        $emma = $this->ownerUser('ERP Emma');
        $young = $this->ownerUser('ERP Young');
        $batch = $this->batch();
        $this->row($batch, 2, ['Contact Email' => 'shared@example.com', 'Belongs to' => 'Emma']);
        $this->row($batch, 3, ['Contact Email' => 'shared@example.com', 'Belongs to' => 'Young']);
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $manager);
        $service = $this->app->make(KolOwnerMappingService::class);

        $service->saveSourceMapping($preview->id, 'Emma', $emma->id, $manager);
        $workspace = $service->saveSourceMapping($preview->id, 'Young', $young->id, $manager);
        self::assertSame(1, $workspace['summary']['multi_owner_candidates']);
        self::assertSame(1, $workspace['summary']['decision_required']);
        self::assertSame('needs_decision', $workspace['multi_candidates'][0]['resolution_status']);

        $clusterKey = $workspace['multi_candidates'][0]['cluster_key'];
        $decided = $service->saveCandidateDecision($preview->id, $clusterKey, $young->id, 'Current owner confirmed', $manager);
        self::assertSame(0, $decided['summary']['decision_required']);
        self::assertSame('decided', $decided['multi_candidates'][0]['resolution_status']);
        self::assertSame($young->id, $decided['multi_candidates'][0]['effective_owner']['id']);
        self::assertSame(1, KolOwnerCandidateDecision::count());

        $service->saveCandidateDecision($preview->id, $clusterKey, $young->id, 'Current owner confirmed', $manager);
        self::assertSame(1, KolOwnerCandidateDecision::count());

        $regenerated = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $manager);
        self::assertSame($preview->id, $regenerated->id);
        $afterRegenerate = $service->workspace($manager, $regenerated->id);
        self::assertSame('decided', $afterRegenerate['multi_candidates'][0]['resolution_status']);
        self::assertTrue($afterRegenerate['multi_candidates'][0]['decision']['snapshot_matches']);
    }

    public function test_multi_owner_global_mappings_may_converge_to_one_explicit_erp_user(): void
    {
        $manager = $this->manager();
        $target = $this->ownerUser('ERP Shared Owner');
        $batch = $this->batch();
        $this->row($batch, 2, ['Contact Email' => 'shared@example.com', 'Belongs to' => 'Emma']);
        $this->row($batch, 3, ['Contact Email' => 'shared@example.com', 'Belongs to' => 'Young']);
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $manager);
        $service = $this->app->make(KolOwnerMappingService::class);
        $service->saveSourceMapping($preview->id, 'Emma', $target->id, $manager);
        $workspace = $service->saveSourceMapping($preview->id, 'Young', $target->id, $manager);

        self::assertSame('mapped_converged', $workspace['multi_candidates'][0]['resolution_status']);
        self::assertSame(0, $workspace['summary']['decision_required']);
        self::assertSame(0, KolOwnerCandidateDecision::count());
    }

    public function test_inactive_or_ineligible_user_is_rejected_as_owner_target(): void
    {
        $manager = $this->manager();
        $batch = $this->batch();
        $this->row($batch, 2, ['Contact Email' => 'one@example.com', 'Belongs to' => 'Emma']);
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $manager);
        $service = $this->app->make(KolOwnerMappingService::class);

        $inactive = $this->ownerUser('Inactive', false);
        try {
            $service->saveSourceMapping($preview->id, 'Emma', $inactive->id, $manager);
            self::fail('Inactive user should have been rejected.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            self::assertArrayHasKey('mapped_user_id', $e->errors());
        }

        $noKol = User::factory()->create(['is_active' => true]);
        try {
            $service->saveSourceMapping($preview->id, 'Emma', $noKol->id, $manager);
            self::fail('Ineligible user should have been rejected.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            self::assertArrayHasKey('mapped_user_id', $e->errors());
        }
        self::assertSame(0, KolOwnerSourceMapping::count());
    }

    private function batch(): KolReconciliationBatch
    {
        return KolReconciliationBatch::create([
            'source_type' => 'xlsx',
            'original_filename' => '0902-网红订单.xlsx',
            'checksum' => str_repeat('b', 64),
            'connection_key' => 'test.example.com',
            'status' => 'preview',
        ]);
    }

    private function row(KolReconciliationBatch $batch, int $number, array $raw): KolReconciliationRow
    {
        $email = isset($raw['Contact Email']) ? strtolower(trim((string) $raw['Contact Email'])) : null;
        return KolReconciliationRow::create([
            'batch_id' => $batch->id,
            'source_row_number' => $number,
            'row_hash' => hash('sha256', json_encode($raw)),
            'raw_source' => $raw,
            'normalized_identity' => ['email' => $email, 'social' => [], 'key' => $email ? 'email:'.$email : null],
            'source_identity_key' => $email ? 'email:'.$email : null,
            'kol_status' => 'unresolved',
            'affiliate_status' => 'missing',
            'reconciliation_status' => 'unresolved',
        ]);
    }

    private function manager(): User
    {
        return $this->userWith(['kol.view', 'kol.operate', 'kol.approvals.manage', 'kol.affiliates.manage'], 'Manager');
    }

    private function ownerUser(string $name, bool $active = true): User
    {
        return $this->userWith(['kol.view'], $name, $active);
    }

    private function userWith(array $keys, string $name = 'User', bool $active = true): User
    {
        $role = Role::create(['key' => 'test_owner_mapping_'.uniqid(), 'label' => 'Test']);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user = User::factory()->create(['is_active' => $active, 'name' => $name]);
        $user->roles()->attach($role);
        return $user;
    }
}
