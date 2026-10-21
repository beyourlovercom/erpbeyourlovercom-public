<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\Approval\KolApprovalService;
use App\Modules\Kol\Application\KolService;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolApprovalDecision;
use App\Modules\Kol\Infrastructure\Models\KolApprovalPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private KolApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(KolApprovalService::class);
    }

    // --- Policy tests ---

    public function test_current_policy_returns_null_when_no_policy_exists(): void
    {
        self::assertNull($this->service->currentPolicy());
    }

    public function test_current_policy_returns_latest_by_version(): void
    {
        KolApprovalPolicy::create(['version' => 1, 'mode' => 'NONE', 'thresholds' => [], 'effective_at' => now()->subDays(2)]);
        KolApprovalPolicy::create(['version' => 2, 'mode' => 'ALL', 'thresholds' => [], 'effective_at' => now()->subDay()]);

        $policy = $this->service->currentPolicy();
        self::assertSame(2, $policy->version);
        self::assertSame('ALL', $policy->mode);
    }

    // --- evaluateAndRecord: specialist + ALL ---

    public function test_specialist_under_all_policy_is_pending(): void
    {
        KolApprovalPolicy::create(['version' => 1, 'mode' => 'ALL', 'thresholds' => [], 'effective_at' => now()]);

        $actor = $this->userWith([]);
        $kol = $this->makeKol($actor);

        $this->service->evaluateAndRecord($kol, $actor, false);

        $kol->refresh();
        self::assertSame('pending', $kol->approval_status);
        self::assertNotNull($kol->approval_updated_at);

        $decision = KolApprovalDecision::where('kol_id', $kol->id)->latest('id')->first();
        self::assertSame('pending', $decision->outcome);
        self::assertSame('specialist_policy', $decision->source);
        self::assertSame('policy_all_requires_manager', $decision->reason_code);
        self::assertSame('ALL', $decision->policy_mode);
    }

    // --- evaluateAndRecord: specialist + NONE ---

    public function test_specialist_under_none_policy_is_auto_approved(): void
    {
        KolApprovalPolicy::create(['version' => 1, 'mode' => 'NONE', 'thresholds' => [], 'effective_at' => now()]);

        $actor = $this->userWith([]);
        $kol = $this->makeKol($actor);

        $this->service->evaluateAndRecord($kol, $actor, false);

        $kol->refresh();
        self::assertSame('approved', $kol->approval_status);
        $decision = KolApprovalDecision::where('kol_id', $kol->id)->latest('id')->first();
        self::assertSame('approved', $decision->outcome);
        self::assertSame('policy_none_auto_approved', $decision->reason_code);
    }

    // --- evaluateAndRecord: privileged creator bypasses ALL ---

    public function test_privileged_creator_bypasses_all_policy(): void
    {
        KolApprovalPolicy::create(['version' => 1, 'mode' => 'ALL', 'thresholds' => [], 'effective_at' => now()]);

        $actor = $this->userWith(['kol.approvals.manage']);
        $kol = $this->makeKol($actor);

        $this->service->evaluateAndRecord($kol, $actor, true);

        $kol->refresh();
        self::assertSame('approved', $kol->approval_status);
        $decision = KolApprovalDecision::where('kol_id', $kol->id)->latest('id')->first();
        self::assertSame('approved', $decision->outcome);
        self::assertSame('privileged_creator', $decision->source);
        self::assertSame('privileged_creator', $decision->reason_code);
    }

    // --- evaluateAndRecord: THRESHOLD with sufficient followers ---

    public function test_threshold_policy_auto_approves_when_followers_meet_threshold(): void
    {
        KolApprovalPolicy::create([
            'version' => 1,
            'mode' => 'THRESHOLD',
            'thresholds' => ['instagram' => 10000],
            'effective_at' => now(),
        ]);

        $actor = $this->userWith([]);
        $kol = $this->makeKol($actor);
        $sa = $kol->socialAccounts()->create(['platform' => 'instagram', 'is_active' => true]);
        $sa->metrics()->create(['followers' => 15000, 'measured_at' => now()]);

        $this->service->evaluateAndRecord($kol, $actor, false);

        $kol->refresh();
        self::assertSame('approved', $kol->approval_status);
        $decision = KolApprovalDecision::where('kol_id', $kol->id)->latest('id')->first();
        self::assertSame('threshold_reached', $decision->reason_code);
    }

    // --- evaluateAndRecord: THRESHOLD below threshold ---

    public function test_threshold_policy_keeps_pending_when_followers_below_threshold(): void
    {
        KolApprovalPolicy::create([
            'version' => 1,
            'mode' => 'THRESHOLD',
            'thresholds' => ['instagram' => 10000],
            'effective_at' => now(),
        ]);

        $actor = $this->userWith([]);
        $kol = $this->makeKol($actor);
        $sa = $kol->socialAccounts()->create(['platform' => 'instagram', 'is_active' => true]);
        $sa->metrics()->create(['followers' => 5000, 'measured_at' => now()]);

        $this->service->evaluateAndRecord($kol, $actor, false);

        $kol->refresh();
        self::assertSame('pending', $kol->approval_status);
        $decision = KolApprovalDecision::where('kol_id', $kol->id)->latest('id')->first();
        self::assertSame('thresholds_below_requirement', $decision->reason_code);
    }

    // --- evaluateAndRecord: no policy defaults to ALL ---

    public function test_no_policy_defaults_to_all_for_specialist(): void
    {
        $actor = $this->userWith([]);
        $kol = $this->makeKol($actor);

        $this->service->evaluateAndRecord($kol, $actor, false);

        $kol->refresh();
        self::assertSame('pending', $kol->approval_status);
        $decision = KolApprovalDecision::where('kol_id', $kol->id)->latest('id')->first();
        self::assertNull($decision->policy_id);
        self::assertSame('policy_all_requires_manager', $decision->reason_code);
    }

    // --- decision records policy snapshot ---

    public function test_decision_records_threshold_snapshot(): void
    {
        $policy = KolApprovalPolicy::create([
            'version' => 1,
            'mode' => 'THRESHOLD',
            'thresholds' => ['tiktok' => 50000],
            'effective_at' => now(),
        ]);

        $actor = $this->userWith([]);
        $kol = $this->makeKol($actor);

        $this->service->evaluateAndRecord($kol, $actor, false);

        $decision = KolApprovalDecision::where('kol_id', $kol->id)->latest('id')->first();
        self::assertSame($policy->id, $decision->policy_id);
        self::assertSame(['tiktok' => 50000], $decision->threshold_snapshot);
    }

    // --- KolService integration: KOL create triggers approval ---

    public function test_kol_create_via_service_records_approval_decision(): void
    {
        KolApprovalPolicy::create(['version' => 1, 'mode' => 'NONE', 'thresholds' => [], 'effective_at' => now()]);

        $actor = $this->userWith(['kol.operate']);
        $kolService = $this->app->make(KolService::class);

        $kol = $kolService->create(
            ['display_name' => 'Test KOL', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $actor->id],
            [],
            $actor,
        );

        self::assertSame('approved', $kol->fresh()->approval_status);
        self::assertDatabaseHas('kol_approval_decisions', [
            'kol_id' => $kol->id,
            'outcome' => 'approved',
            'source' => 'specialist_policy',
        ]);
    }

    public function test_current_policy_ignores_future_effective_version(): void
    {
        $manager = $this->userWith(['kol.approvals.manage']);
        $effective = $this->service->createPolicy('NONE', [], $manager, now()->subHour());
        $this->service->createPolicy('ALL', [], $manager, now()->addDay());

        self::assertSame($effective->id, $this->service->currentPolicy()?->id);
        self::assertSame('NONE', $this->service->currentPolicy()?->mode);
    }

    public function test_policy_changes_append_versions_without_mutating_history(): void
    {
        $manager = $this->userWith(['kol.approvals.manage']);
        $first = $this->service->createPolicy('THRESHOLD', ['Instagram' => 10000], $manager, now()->subMinute());
        $second = $this->service->createPolicy('NONE', ['instagram' => 99999], $manager, now());

        self::assertSame(1, $first->version);
        self::assertSame(['instagram' => 10000], $first->thresholds);
        self::assertSame(2, $second->version);
        self::assertSame([], $second->thresholds);
        self::assertSame(2, KolApprovalPolicy::count());
        self::assertSame('THRESHOLD', $first->fresh()->mode);
        self::assertSame(['instagram' => 10000], $first->fresh()->thresholds);
    }

    public function test_policy_creation_requires_manage_permission_and_canonical_thresholds(): void
    {
        $specialist = $this->userWith([]);
        try {
            $this->service->createPolicy('NONE', [], $specialist);
            self::fail('Expected authorization failure.');
        } catch (AuthorizationException) {
            self::assertSame(0, KolApprovalPolicy::count());
        }

        $manager = $this->userWith(['kol.approvals.manage']);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createPolicy('THRESHOLD', ['linkedin' => 1000], $manager);
    }

    public function test_manual_approve_requires_manage_permission(): void
    {
        $specialist = $this->userWith([]);
        $kol = $this->makeKol($specialist);
        $this->service->evaluateAndRecord($kol, $specialist, false);

        $this->expectException(AuthorizationException::class);
        $this->service->approve($kol, $specialist, 'Looks good');
    }

    public function test_manager_approve_appends_history_and_preserves_business_status(): void
    {
        $specialist = $this->userWith([]);
        $manager = $this->userWith(['kol.approvals.manage']);
        $kol = $this->makeKol($specialist);
        $this->service->evaluateAndRecord($kol, $specialist, false);

        $decision = $this->service->approve($kol, $manager, 'Approved by manager');

        self::assertSame('manager_manual', $decision->source);
        self::assertSame('manager_approved', $decision->reason_code);
        self::assertSame('Approved by manager', $decision->reason_text);
        self::assertSame('approved', $kol->approval_status);
        self::assertSame('active', $kol->status);
        self::assertSame(2, KolApprovalDecision::where('kol_id', $kol->id)->count());
    }

    public function test_manager_reject_preserves_original_policy_snapshot_after_policy_change(): void
    {
        $specialist = $this->userWith([]);
        $manager = $this->userWith(['kol.approvals.manage']);
        $policy = $this->service->createPolicy('ALL', [], $manager, now()->subMinute());
        $kol = $this->makeKol($specialist);
        $this->service->evaluateAndRecord($kol, $specialist, false);
        $pending = KolApprovalDecision::where('kol_id', $kol->id)->latest('id')->firstOrFail();

        $this->service->createPolicy('NONE', [], $manager, now());
        $decision = $this->service->reject($kol, $manager, 'Does not fit current program');

        self::assertSame($policy->id, $pending->policy_id);
        self::assertSame($pending->policy_id, $decision->policy_id);
        self::assertSame('ALL', $decision->policy_mode);
        self::assertSame([], $decision->threshold_snapshot);
        self::assertSame('rejected', $kol->approval_status);
        self::assertSame('active', $kol->status);
        self::assertSame('manager_rejected', $decision->reason_code);
        self::assertSame(2, KolApprovalDecision::where('kol_id', $kol->id)->count());
    }

    public function test_manual_decision_only_transitions_pending_kols(): void
    {
        $specialist = $this->userWith([]);
        $manager = $this->userWith(['kol.approvals.manage']);
        $kol = $this->makeKol($specialist);
        $this->service->evaluateAndRecord($kol, $specialist, false);
        $this->service->approve($kol, $manager);

        $this->expectException(\LogicException::class);
        $this->service->reject($kol, $manager);
    }

    public function test_legacy_backfill_rollback_and_reapply_keeps_one_decision(): void
    {
        $actor = $this->userWith([]);
        $kol = $this->makeKol($actor);
        $migration = require database_path('migrations/2026_08_29_130000_backfill_legacy_kol_approval_decisions.php');

        $migration->up();
        self::assertSame('approved', $kol->fresh()->approval_status);
        self::assertSame(1, KolApprovalDecision::where('kol_id', $kol->id)->where('source', 'legacy_backfill')->count());

        $migration->down();
        self::assertSame(0, KolApprovalDecision::where('kol_id', $kol->id)->where('source', 'legacy_backfill')->count());

        $migration->up();
        self::assertSame(1, KolApprovalDecision::where('kol_id', $kol->id)->where('source', 'legacy_backfill')->count());
    }

    // --- helpers ---

    private function makeKol(User $actor): Kol
    {
        return Kol::create([
            'display_name' => 'Test KOL '.uniqid(),
            'status' => 'active',
            'relationship_stage' => 'prospect',
            'owner_id' => $actor->id,
        ]);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'test_approval_'.uniqid(), 'label' => 'Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
