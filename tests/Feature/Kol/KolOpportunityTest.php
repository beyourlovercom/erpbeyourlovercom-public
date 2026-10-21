<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolOpportunityTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'test_kol_crm_'.uniqid(), 'label' => 'Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Instagram 秋季合作',
            'cooperation_type' => '付费发帖',
            'target_products' => [],
            'stage' => 'communicating',
            'estimated_cost' => 500,
            'currency' => 'USD',
            'expected_publish_at' => '2026-09-15',
            'next_follow_up_at' => '2026-08-30 10:00:00',
            'next_action' => '确认报价',
            'status' => 'active',
            'notes' => 'CRM test',
        ], $overrides);
    }

    public function test_admin_can_create_and_update_opportunity(): void
    {
        $admin = $this->userWith(['kol.view', 'kol.operate', 'kol.views.manage']);
        $kol = Kol::create(['display_name' => 'CRM KOL', 'status' => 'active', 'relationship_stage' => 'negotiating', 'owner_id' => $admin->id]);

        $response = $this->actingAs($admin)->postJson("/kol/library/{$kol->id}/opportunities", $this->payload(['owner_id' => $admin->id]));
        $response->assertCreated()->assertJsonPath('opportunity.status', 'active');
        $opportunity = KolOpportunity::firstOrFail();

        $this->actingAs($admin)->patchJson("/kol/library/{$kol->id}/opportunities/{$opportunity->id}", $this->payload([
            'owner_id' => $admin->id,
            'stage' => 'success',
            'status' => 'completed',
        ]))->assertOk()->assertJsonPath('opportunity.status', 'completed');

        $this->assertDatabaseHas('kol_opportunities', ['id' => $opportunity->id, 'stage' => 'success', 'status' => 'completed']);
    }

    public function test_specialist_opportunity_is_forced_to_own_owner_id(): void
    {
        $specialist = $this->userWith(['kol.view', 'kol.operate']);
        $admin = $this->userWith(['kol.view', 'kol.operate', 'kol.views.manage']);
        $kol = Kol::create(['display_name' => 'Own CRM KOL', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $specialist->id]);

        $this->actingAs($specialist)->postJson("/kol/library/{$kol->id}/opportunities", $this->payload(['owner_id' => $admin->id]))->assertCreated();
        $this->assertDatabaseHas('kol_opportunities', ['kol_id' => $kol->id, 'owner_id' => $specialist->id]);
    }

    public function test_specialist_cannot_mutate_other_owner_opportunity(): void
    {
        $specialist = $this->userWith(['kol.view', 'kol.operate']);
        $admin = $this->userWith(['kol.view', 'kol.operate', 'kol.views.manage']);
        $kol = Kol::create(['display_name' => 'Other CRM KOL', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $admin->id]);
        $opportunity = KolOpportunity::create(array_merge($this->payload(), ['kol_id' => $kol->id, 'owner_id' => $admin->id]));

        $this->actingAs($specialist)->patchJson("/kol/library/{$kol->id}/opportunities/{$opportunity->id}", $this->payload())->assertForbidden();
    }
}
