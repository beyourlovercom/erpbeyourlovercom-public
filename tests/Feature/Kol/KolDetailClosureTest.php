<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolCommunication;
use App\Modules\Kol\Infrastructure\Models\KolOpportunity;
use App\Modules\Kol\Infrastructure\Models\KolSocialAccount;
use App\Modules\Kol\Infrastructure\Models\KolSocialMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KolDetailClosureTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $role = Role::create(['key' => 'kol_detail_closure_'.uniqid(), 'label' => 'KOL Detail Closure']);
        foreach (['kol.view', 'kol.operate', 'kol.views.manage'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    public function test_detail_projects_real_avatar_social_opportunity_communication_and_history_data(): void
    {
        Storage::fake('public');
        $operator = $this->operator();
        $avatarPath = 'kol/avatars/1/avatar.webp';
        Storage::disk('public')->put($avatarPath, 'fake-image-bytes');

        $kol = Kol::create([
            'display_name' => 'Closure KOL',
            'status' => 'active',
            'relationship_stage' => 'cooperating',
            'owner_id' => $operator->id,
            'avatar_path' => $avatarPath,
            'avatar_source' => 'manual_paste',
            'avatar_updated_at' => now(),
        ]);

        $social = KolSocialAccount::create(['kol_id' => $kol->id, 'platform' => 'instagram', 'handle' => 'closure', 'is_active' => true]);
        KolSocialMetric::create(['social_account_id' => $social->id, 'followers' => 123456, 'raw_followers_value' => '123456', 'measured_at' => now()]);

        $active = KolOpportunity::create(['kol_id' => $kol->id, 'title' => 'Active CRM Opportunity', 'stage' => 'communicating', 'owner_id' => $operator->id, 'status' => 'active']);
        KolOpportunity::create(['kol_id' => $kol->id, 'title' => 'Historical CRM Opportunity', 'stage' => 'success', 'owner_id' => $operator->id, 'status' => 'success']);

        KolCommunication::create(['kol_id' => $kol->id, 'opportunity_id' => $active->id, 'created_by' => $operator->id, 'channel' => 'email', 'direction' => 'outbound', 'summary' => 'Older communication', 'contacted_at' => now()->subDay()]);
        KolCommunication::create(['kol_id' => $kol->id, 'opportunity_id' => $active->id, 'created_by' => $operator->id, 'channel' => 'instagram_dm', 'direction' => 'inbound', 'summary' => 'Newest communication', 'contacted_at' => now()]);

        $response = $this->actingAs($operator)->get("/kol/library/{$kol->id}");
        $response->assertOk();
        $props = $response->original->getData()['page']['props']['kol'];

        $this->assertStringContainsString('/storage/kol/avatars/1/avatar.webp', $props['avatar_url']);
        $this->assertSame(123456, $props['social_accounts'][0]['latest_metric']['followers']);
        $this->assertSame('Active CRM Opportunity', $props['current_opportunity']['title']);
        $this->assertSame('Newest communication', $props['recent_communications'][0]['summary']);
        $this->assertSame('inbound', $props['recent_communications'][0]['direction']);
        $this->assertSame('Historical CRM Opportunity', $props['historical_opportunities'][0]['title']);
        Storage::disk('public')->assertExists($avatarPath);
    }
}
