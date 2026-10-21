<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolCommunicationTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'test_comm_'.uniqid(), 'label' => 'Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function makeKol(User $owner): Kol
    {
        return Kol::create(['display_name' => 'Comm KOL', 'status' => 'active', 'relationship_stage' => 'contacted', 'owner_id' => $owner->id]);
    }

    public function test_multiple_users_can_record_communications_for_same_kol_when_authorized(): void
    {
        $admin = $this->userWith(['kol.view', 'kol.operate', 'kol.views.manage']);
        $operator = $this->userWith(['kol.view', 'kol.operate', 'kol.views.manage']);
        $kol = $this->makeKol($admin);
        $opportunity = KolOpportunity::create(['kol_id' => $kol->id, 'title' => 'Campaign', 'stage' => 'contacted', 'status' => 'active']);

        foreach ([[$admin, 'outbound', 'First'], [$operator, 'inbound', 'Second']] as [$user, $direction, $summary]) {
            $this->actingAs($user)->post("/kol/library/{$kol->id}/communications", [
                'opportunity_id' => $opportunity->id, 'channel' => 'email', 'direction' => $direction,
                'summary' => $summary, 'contacted_at' => '2026-08-28 12:00:00',
            ])->assertCreated();
        }

        $this->assertDatabaseCount('kol_communications', 2);
        $this->assertDatabaseHas('kol_communications', ['kol_id' => $kol->id, 'created_by' => $admin->id, 'summary' => 'First']);
        $this->assertDatabaseHas('kol_communications', ['kol_id' => $kol->id, 'created_by' => $operator->id, 'summary' => 'Second']);
    }

    public function test_communication_can_be_updated_and_cross_kol_opportunity_is_rejected(): void
    {
        $admin = $this->userWith(['kol.view', 'kol.operate', 'kol.views.manage']);
        $kol = $this->makeKol($admin);
        $other = $this->makeKol($admin);
        $otherOpportunity = KolOpportunity::create(['kol_id' => $other->id, 'title' => 'Other', 'stage' => 'contacted', 'status' => 'active']);

        $created = $this->actingAs($admin)->post("/kol/library/{$kol->id}/communications", [
            'channel' => 'instagram_dm', 'direction' => 'outbound', 'summary' => 'Hello', 'contacted_at' => '2026-08-28 12:00:00',
        ])->json('communication');

        $this->actingAs($admin)->patch("/kol/library/{$kol->id}/communications/{$created['id']}", [
            'channel' => 'x_dm', 'direction' => 'inbound', 'summary' => 'Reply', 'contacted_at' => '2026-08-28 13:00:00',
        ])->assertOk();
        $this->assertDatabaseHas('kol_communications', ['id' => $created['id'], 'channel' => 'x_dm', 'direction' => 'inbound', 'summary' => 'Reply']);

        $this->actingAs($admin)->patch("/kol/library/{$kol->id}/communications/{$created['id']}", [
            'opportunity_id' => $otherOpportunity->id, 'channel' => 'email', 'direction' => 'outbound', 'summary' => 'Bad link', 'contacted_at' => '2026-08-28 14:00:00',
        ])->assertSessionHasErrors('opportunity_id');
    }

    public function test_specialist_cannot_write_other_owner_kol_communication(): void
    {
        $specialist = $this->userWith(['kol.view', 'kol.operate']);
        $other = $this->userWith(['kol.view', 'kol.operate', 'kol.views.manage']);
        $kol = $this->makeKol($other);
        $this->actingAs($specialist)->post("/kol/library/{$kol->id}/communications", [
            'channel' => 'email', 'direction' => 'outbound', 'summary' => 'No access', 'contacted_at' => '2026-08-28 12:00:00',
        ])->assertForbidden();
    }
}
