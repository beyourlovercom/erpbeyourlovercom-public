<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\KolOperationalDashboardService;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateReferral;
use App\Modules\Kol\Infrastructure\Models\KolApprovalDecision;
use App\Modules\Kol\Infrastructure\Models\KolCollaboration;
use App\Modules\Kol\Infrastructure\Models\KolCollaborationApprovalDecision;
use App\Modules\Kol\Infrastructure\Models\KolContent;
use App\Modules\Kol\Infrastructure\Models\KolPayoutRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KolM14FApprovalDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions, string $name): User
    {
        $role = Role::create(['key' => 'm14f_'.uniqid(), 'label' => 'M1.4F Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function kol(User $owner, string $name, string $approval = 'approved', ?User $manager = null, ?User $finance = null): Kol
    {
        return Kol::create([
            'display_name' => $name, 'status' => 'active', 'relationship_stage' => 'cooperating',
            'owner_id' => $owner->id, 'approval_manager_id' => $manager?->id, 'finance_owner_id' => $finance?->id, 'approval_status' => $approval,
        ]);
    }

    private function paidPayload(float $amount = 250): array
    {
        return [
            'title' => 'Paid collaboration', 'cooperation_type' => '付费发帖', 'status' => 'confirmed',
            'agreement_amount' => $amount, 'currency' => 'USD', 'fulfillment_required' => false,
        ];
    }

    public function test_specialist_paid_collaboration_requires_manager_approval_and_amount_change_reopens_it(): void
    {
        $specialist = $this->userWith(['kol.view', 'kol.operate'], 'Specialist A');
        $manager = $this->userWith(['kol.view', 'kol.operate', 'kol.views.manage', 'kol.approvals.manage'], 'Manager A');
        $kol = $this->kol($specialist, 'Paid Creator', 'approved', $manager);

        $created = $this->actingAs($specialist)->postJson("/kol/library/{$kol->id}/collaborations", $this->paidPayload());
        $created->assertCreated()->assertJsonPath('collaboration.approval_status', 'pending');
        $id = (int) $created->json('collaboration.id');
        $this->assertDatabaseHas('kol_collaboration_approval_decisions', ['collaboration_id' => $id, 'outcome' => 'pending', 'source' => 'specialist_created']);

        $this->actingAs($manager)->post("/kol/approvals/collaborations/{$id}/approve")->assertRedirect();
        $this->assertDatabaseHas('kol_collaborations', ['id' => $id, 'approval_status' => 'approved', 'approved_by_user_id' => $manager->id]);

        $updated = $this->actingAs($specialist)->patchJson("/kol/library/{$kol->id}/collaborations/{$id}", $this->paidPayload(300));
        $updated->assertOk()->assertJsonPath('collaboration.approval_status', 'pending');
        $this->assertDatabaseHas('kol_collaboration_approval_decisions', ['collaboration_id' => $id, 'outcome' => 'pending', 'source' => 'specialist_terms_changed']);
    }

    public function test_manager_created_paid_collaboration_is_approved_and_free_collaboration_needs_no_approval(): void
    {
        $manager = $this->userWith(['kol.view', 'kol.operate', 'kol.approvals.manage'], 'Manager B');
        $kol = $this->kol($manager, 'Manager Creator');

        $paid = $this->actingAs($manager)->postJson("/kol/library/{$kol->id}/collaborations", $this->paidPayload());
        $paid->assertCreated()->assertJsonPath('collaboration.approval_status', 'approved');
        $this->assertDatabaseHas('kol_collaboration_approval_decisions', ['collaboration_id' => $paid->json('collaboration.id'), 'source' => 'privileged_creator', 'outcome' => 'approved']);

        $free = $this->actingAs($manager)->postJson("/kol/library/{$kol->id}/collaborations", [
            'title' => 'Free post', 'cooperation_type' => '免费发帖', 'status' => 'confirmed', 'agreement_amount' => 0,
            'currency' => 'USD', 'fulfillment_required' => false,
        ]);
        $free->assertCreated()->assertJsonPath('collaboration.approval_status', 'not_required');
    }

    public function test_manager_approval_modules_are_isolated_and_keep_module_history(): void
    {
        $specialist = $this->userWith(['kol.view', 'kol.operate', 'kol.payouts.request'], 'Specialist B');
        $manager = $this->userWith(['kol.view', 'kol.views.manage', 'kol.approvals.manage', 'kol.payouts.approve'], 'Manager C');
        $kol = $this->kol($specialist, 'Pending Creator', 'pending', $manager);
        KolApprovalDecision::create(['kol_id' => $kol->id, 'outcome' => 'pending', 'source' => 'specialist_policy', 'actor_user_id' => $specialist->id, 'policy_mode' => 'ALL', 'threshold_snapshot' => [], 'reason_code' => 'policy_all_requires_manager', 'decided_at' => now()]);
        $collaboration = KolCollaboration::create(['kol_id' => $kol->id, 'owner_id' => $specialist->id, 'title' => 'Pending paid', 'cooperation_type' => '付费发帖', 'status' => 'confirmed', 'approval_status' => 'pending', 'agreement_amount' => 200, 'currency' => 'USD', 'approval_updated_at' => now()]);
        KolCollaborationApprovalDecision::create(['collaboration_id' => $collaboration->id, 'outcome' => 'pending', 'source' => 'specialist_created', 'actor_user_id' => $specialist->id, 'cooperation_type' => '付费发帖', 'agreement_amount' => 200, 'currency' => 'USD', 'decided_at' => now()]);
        $payout = KolPayoutRequest::create(['connection_key' => 'test.example.com', 'kol_id' => $kol->id, 'affiliate_id' => 7, 'amount' => '10', 'currency' => 'USD', 'payout_method' => 'paypal_manual', 'payout_recipient' => 'pay@example.test', 'status' => 'pending_approval', 'payload_hash' => str_repeat('a', 64), 'idempotency_key' => (string) Str::uuid(), 'requested_by_user_id' => $specialist->id]);

        $this->actingAs($specialist)->get('/kol/approvals/kols')->assertForbidden();
        $this->actingAs($manager)->get('/kol/approvals')->assertRedirect('/kol/approvals/kols');

        $kolWorkspace = $this->actingAs($manager)->get('/kol/approvals/kols')->assertOk()->original->getData()['page']['props']['workspace'];
        $this->assertSame('kols', $kolWorkspace['module']);
        $this->assertSame(1, $kolWorkspace['pending_count']);
        $this->assertSame(0, $kolWorkspace['history_count']);
        $this->assertSame($kol->id, $kolWorkspace['pending'][0]['id']);

        $collaborationWorkspace = $this->actingAs($manager)->get('/kol/approvals/collaborations')->assertOk()->original->getData()['page']['props']['workspace'];
        $this->assertSame('collaborations', $collaborationWorkspace['module']);
        $this->assertSame(1, $collaborationWorkspace['pending_count']);
        $this->assertSame($collaboration->id, $collaborationWorkspace['pending'][0]['id']);

        $payoutWorkspace = $this->actingAs($manager)->get('/kol/approvals/payouts')->assertOk()->original->getData()['page']['props']['workspace'];
        $this->assertSame('payouts', $payoutWorkspace['module']);
        $this->assertSame(1, $payoutWorkspace['pending_count']);
        $this->assertSame($payout->id, $payoutWorkspace['pending'][0]['id']);

        $this->actingAs($manager)->post("/kol/approvals/payouts/{$payout->id}/approve")->assertRedirect();
        $this->assertSame('approved', $payout->fresh()->status);
        $this->assertSame($manager->id, $payout->fresh()->approved_by_user_id);

        $historyWorkspace = $this->actingAs($manager)->get('/kol/approvals/payouts')->assertOk()->original->getData()['page']['props']['workspace'];
        $this->assertSame(0, $historyWorkspace['pending_count']);
        $this->assertSame(1, $historyWorkspace['history_count']);
        $payoutHistory = $historyWorkspace['history'][0];
        $this->assertSame('payout:'.$payout->id, $payoutHistory['record_key']);
        $this->assertSame('approved', $payoutHistory['outcome']);
        $this->assertSame($manager->name, $payoutHistory['actor_name']);
        $this->assertSame('10.000000', $payoutHistory['amount']);
        $this->assertSame('USD', $payoutHistory['currency']);
    }

    public function test_finance_cannot_approve_payout_but_retains_completion_permission_boundary(): void
    {
        $specialist = $this->userWith(['kol.payouts.request'], 'Specialist C');
        $finance = $this->userWith(['kol.payouts.manage'], 'Finance A');
        $kol = $this->kol($specialist, 'Payout Creator');
        $payout = KolPayoutRequest::create(['connection_key' => 'test.example.com', 'kol_id' => $kol->id, 'affiliate_id' => 7, 'amount' => '10', 'currency' => 'USD', 'payout_method' => 'paypal_manual', 'payout_recipient' => 'pay@example.test', 'status' => 'pending_approval', 'payload_hash' => str_repeat('b', 64), 'idempotency_key' => (string) Str::uuid(), 'requested_by_user_id' => $specialist->id]);

        $this->actingAs($finance)->post("/kol/payouts/{$payout->id}/approve")->assertForbidden();
        $this->assertSame('pending_approval', $payout->fresh()->status);
    }

    public function test_dashboard_is_owner_scoped_for_specialist_and_deduplicates_affiliate_sales_by_order_reference(): void
    {
        $ownerA = $this->userWith(['kol.view', 'kol.operate', 'kol.payouts.request'], 'Owner A');
        $ownerB = $this->userWith(['kol.view', 'kol.operate'], 'Owner B');
        $manager = $this->userWith(['kol.view', 'kol.views.manage', 'kol.approvals.manage'], 'Manager D');
        $kolA = $this->kol($ownerA, 'A Creator');
        $kolB = $this->kol($ownerB, 'B Creator');

        KolCollaboration::create(['kol_id' => $kolA->id, 'owner_id' => $ownerA->id, 'status' => 'confirmed', 'currency' => 'USD']);
        KolCollaboration::create(['kol_id' => $kolB->id, 'owner_id' => $ownerB->id, 'status' => 'confirmed', 'currency' => 'USD']);
        KolContent::create(['kol_id' => $kolA->id, 'platform' => 'instagram', 'content_type' => 'post', 'publication_status' => 'published', 'post_url' => 'https://example.test/a', 'published_at' => now()->toDateString()]);
        KolContent::create(['kol_id' => $kolB->id, 'platform' => 'instagram', 'content_type' => 'post', 'publication_status' => 'published', 'post_url' => 'https://example.test/b', 'published_at' => now()->toDateString()]);
        foreach ([[91, $kolA, '5001', '4.00'], [92, $kolA, '5001', '6.00'], [93, $kolB, '5002', '8.00']] as [$refId, $kol, $orderRef, $amount]) {
            KolAffiliateReferral::create(['connection_key' => 'test.example.com', 'referral_id' => $refId, 'affiliate_id' => $kol->id + 100, 'kol_id' => $kol->id, 'order_reference' => $orderRef, 'commission_amount' => $amount, 'currency' => 'USD', 'provider_status' => 'unpaid', 'context' => 'woocommerce', 'referral_date' => now(), 'affiliate_resolution_status' => 'bound', 'order_resolution_status' => 'unresolved', 'reconciliation_status' => 'present', 'sync_status' => 'synced']);
        }

        $service = $this->app->make(KolOperationalDashboardService::class);
        $mine = $service->metrics($ownerA, ['period' => 'week']);
        $this->assertSame('mine', $mine['scope']);
        $this->assertSame(1, $mine['collaboration_records']);
        $this->assertSame(1, $mine['publication_records']);
        $this->assertSame(1, $mine['affiliate'][0]['sales_count']);
        $this->assertSame(2, $mine['eligible_commission'][0]['referral_count']);
        $this->assertSame('10.000000', $mine['affiliate'][0]['commission_total']);

        $all = $service->metrics($manager, ['period' => 'week']);
        $this->assertSame('all', $all['scope']);
        $this->assertSame(2, $all['collaboration_records']);
        $this->assertSame(2, $all['publication_records']);
        $this->assertSame(2, $all['affiliate'][0]['sales_count']);
    }
    public function test_manager_approval_visibility_and_actions_are_limited_to_assigned_kols(): void
    {
        $specialistA = $this->userWith(['kol.view', 'kol.operate'], 'Specialist Scope A');
        $specialistB = $this->userWith(['kol.view', 'kol.operate'], 'Specialist Scope B');
        $managerA = $this->userWith(['kol.view', 'kol.approvals.manage', 'kol.payouts.approve'], 'Manager Scope A');
        $managerB = $this->userWith(['kol.view', 'kol.approvals.manage', 'kol.payouts.approve'], 'Manager Scope B');
        $kolA = $this->kol($specialistA, 'Scope Creator A', 'pending', $managerA);
        $kolB = $this->kol($specialistB, 'Scope Creator B', 'pending', $managerB);
        foreach ([[$kolA,$specialistA],[$kolB,$specialistB]] as [$kol,$actor]) {
            KolApprovalDecision::create(['kol_id'=>$kol->id,'outcome'=>'pending','source'=>'specialist_policy','actor_user_id'=>$actor->id,'policy_mode'=>'ALL','threshold_snapshot'=>[],'reason_code'=>'policy_all_requires_manager','decided_at'=>now()]);
        }

        $workspaceA = $this->actingAs($managerA)->get('/kol/approvals/kols')->assertOk()->original->getData()['page']['props']['workspace'];
        $workspaceB = $this->actingAs($managerB)->get('/kol/approvals/kols')->assertOk()->original->getData()['page']['props']['workspace'];
        $this->assertSame([$kolA->id], collect($workspaceA['pending'])->pluck('id')->all());
        $this->assertSame([$kolB->id], collect($workspaceB['pending'])->pluck('id')->all());
        $this->actingAs($managerA)->post("/kol/approvals/kols/{$kolB->id}/approve")->assertNotFound();
        $this->actingAs($managerA)->post("/kol/approvals/kols/{$kolA->id}/approve")->assertRedirect();
        $this->assertSame('approved', $kolA->fresh()->approval_status);
        $this->assertSame('pending', $kolB->fresh()->approval_status);
    }

}
