<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AffiliateWpWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_specialist_can_view_workspace_without_manager_capabilities(): void
    {
        $user = $this->userWith(['kol.view', 'kol.operate']);

        $this->actingAs($user)->get('/kol/affiliatewp')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Kol/AffiliateWp/Index')
            ->where('canManageApprovals', false)
            ->where('canManageAffiliates', false));
    }

    public function test_manager_permissions_are_exposed_independently_from_generic_kol_operate(): void
    {
        $user = $this->userWith(['kol.view', 'kol.operate', 'kol.approvals.manage', 'kol.affiliates.manage']);

        $this->actingAs($user)->get('/kol/affiliatewp')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canManageApprovals', true)
            ->where('canManageAffiliates', true));
    }

    public function test_workspace_requires_kol_view_permission(): void
    {
        $this->actingAs($this->userWith([]))->get('/kol/affiliatewp')->assertForbidden();
    }

    public function test_legacy_affiliate_path_redirects_to_canonical_affiliatewp_workspace(): void
    {
        $user = $this->userWith(['kol.view']);
        $this->actingAs($user)->get('/kol/affiliate')->assertRedirect('/kol/affiliatewp');
    }

    public function test_workspace_projects_approved_kol_provisioning_candidates_with_state_gating(): void
    {
        $manager = $this->userWith(['kol.view', 'kol.operate', 'kol.views.manage', 'kol.affiliates.manage']);
        $connectionKey = (string) config('services.andy_core.connection_key', 'beyourlover.com');

        $ready = $this->kol($manager, 'A Ready');
        $retry = $this->kol($manager, 'B Retry');
        $active = $this->kol($manager, 'C Active');
        $external = $this->kol($manager, 'D External');
        $inProgress = $this->kol($manager, 'E In Progress');
        $this->kol($manager, 'Z Pending', ['approval_status' => 'pending']);

        KolAffiliateAccount::create([
            'connection_key' => $connectionKey,
            'kol_id' => $retry->id,
            'origin' => 'erp_provisioned',
            'sync_status' => 'never_synced',
            'reconciliation_status' => 'present',
            'provisioning_status' => 'error',
            'provision_idempotency_key' => 'uat-retry-key',
            'last_error_code' => 'PROVIDER_UNAVAILABLE',
            'last_error_message' => 'Synthetic retryable provider error.',
        ]);
        KolAffiliateAccount::create([
            'connection_key' => $connectionKey,
            'kol_id' => $active->id,
            'affiliate_id' => 901,
            'wordpress_user_id' => 701,
            'provider_status' => 'active',
            'origin' => 'erp_provisioned',
            'sync_status' => 'synced',
            'reconciliation_status' => 'present',
            'provisioning_status' => 'active',
        ]);
        KolAffiliateAccount::create([
            'connection_key' => $connectionKey,
            'kol_id' => $external->id,
            'affiliate_id' => 902,
            'provider_status' => 'pending',
            'origin' => 'external_sync',
            'sync_status' => 'synced',
            'reconciliation_status' => 'present',
            'provisioning_status' => 'not_requested',
        ]);
        KolAffiliateAccount::create([
            'connection_key' => $connectionKey,
            'kol_id' => $inProgress->id,
            'origin' => 'erp_provisioned',
            'sync_status' => 'never_synced',
            'reconciliation_status' => 'present',
            'provisioning_status' => 'in_progress',
            'provision_idempotency_key' => 'uat-progress-key',
        ]);

        $this->actingAs($manager)->get('/kol/affiliatewp')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('provisioningCandidates', 5)
            ->where('provisioningCandidates.0.kol_id', $ready->id)
            ->where('provisioningCandidates.0.can_provision', true)
            ->where('provisioningCandidates.1.kol_id', $retry->id)
            ->where('provisioningCandidates.1.provisioning_status', 'error')
            ->where('provisioningCandidates.1.can_provision', true)
            ->where('provisioningCandidates.1.last_error_code', 'PROVIDER_UNAVAILABLE')
            ->where('provisioningCandidates.2.kol_id', $active->id)
            ->where('provisioningCandidates.2.can_provision', false)
            ->where('provisioningCandidates.3.kol_id', $external->id)
            ->where('provisioningCandidates.3.can_provision', false)
            ->where('provisioningCandidates.4.kol_id', $inProgress->id)
            ->where('provisioningCandidates.4.can_provision', false));
    }

    public function test_specialist_only_receives_own_approved_provisioning_rows_and_actions_are_disabled(): void
    {
        $specialist = $this->userWith(['kol.view', 'kol.operate']);
        $other = $this->userWith(['kol.view', 'kol.operate']);
        $own = $this->kol($specialist, 'Own Approved');
        $this->kol($other, 'Other Approved');

        $this->actingAs($specialist)->get('/kol/affiliatewp')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('provisioningCandidates', 1)
            ->where('provisioningCandidates.0.kol_id', $own->id)
            ->where('provisioningCandidates.0.can_provision', false)
            ->where('provisioningCandidates.0.disabled_reason', '需要 Affiliate 管理权限'));
    }

    private function kol(User $owner, string $name, array $overrides = []): Kol
    {
        return Kol::create(array_merge([
            'display_name' => $name,
            'status' => 'active',
            'relationship_stage' => 'prospect',
            'owner_id' => $owner->id,
            'approval_status' => 'approved',
            'contact_email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ], $overrides));
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'test_affiliatewp_'.uniqid(), 'label' => 'Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
