<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Dashboard\Application\DashboardRoleWorkspaceService;
use App\Modules\Dashboard\Application\DashboardWorkspaceResolver;
use App\Modules\Guides\Infrastructure\Models\GuideArticle;
use App\Modules\Identity\Application\RolePreset;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolCollaboration;
use App\Modules\Kol\Infrastructure\Models\KolContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class DashboardRoleWorkspacesTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPreset(RolePreset $preset, string $name): User
    {
        $role = Role::firstOrCreate(
            ['key' => $preset->roleKey()],
            ['label' => $preset->label()],
        );

        foreach ($preset->permissions() as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create([
            'name' => $name,
            'is_active' => true,
            'permission_mode' => 'role',
        ]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_kol_specialist_dashboard_renders_real_role_workspace_instead_of_empty_state(): void
    {
        $emma = $this->userWithPreset(RolePreset::KolSpecialist, 'Emma');
        $kol = Kol::create([
            'display_name' => 'Creator A',
            'status' => 'active',
            'relationship_stage' => 'cooperating',
            'owner_id' => $emma->id,
            'approval_status' => 'approved',
        ]);
        KolCollaboration::create([
            'kol_id' => $kol->id,
            'owner_id' => $emma->id,
            'title' => 'Autumn post',
            'status' => 'confirmed',
            'approval_status' => 'approved',
            'currency' => 'USD',
            'fulfillment_required' => true,
        ]);
        KolContent::create([
            'kol_id' => $kol->id,
            'platform' => 'instagram',
            'content_type' => 'post',
            'publication_status' => 'draft',
        ]);

        $this->actingAs($emma)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('workspace.role', 'kol_specialist')
                ->where('workspace.title', '今日网红运营')
                ->where('roleSnapshot.kind', 'kol')
                ->where('roleSnapshot.cards.0.label', '我的网红')
                ->where('roleSnapshot.cards.0.value', 1)
                ->where('roleSnapshot.cards.1.value', 1)
                ->where('roleSnapshot.cards.2.value', 1)
                ->where('roleSnapshot.cards.4.value', 1)
                ->where('visibility.operations', false)
                ->where('visibility.inventory', false)
                ->where('visibility.sync', false)
                ->where('visibility.finance', false)
                ->where('visibility.procurement', false)
            );
    }

    public function test_workspace_resolver_is_deterministic_for_multi_role_users(): void
    {
        $user = $this->userWithPreset(RolePreset::Writer, 'Multi Role');
        $managerRole = Role::firstOrCreate(
            ['key' => RolePreset::KolManager->roleKey()],
            ['label' => RolePreset::KolManager->label()],
        );
        $user->roles()->attach($managerRole);

        $workspace = $this->app->make(DashboardWorkspaceResolver::class)->resolve($user);

        $this->assertSame('kol_manager', $workspace['role']);
        $this->assertSame('网红工作台', $workspace['eyebrow']);
    }

    public function test_sales_and_writer_workspaces_use_existing_owned_business_evidence(): void
    {
        $sales = $this->userWithPreset(RolePreset::SalesRepresentative, 'Sales A');
        Customer::create([
            'name' => 'Customer A',
            'email' => 'customer@example.test',
            'source_type' => 'manual',
            'owner_id' => $sales->id,
        ]);

        $service = $this->app->make(DashboardRoleWorkspaceService::class);
        $salesSnapshot = $service->snapshot('sales_representative', $sales);

        $this->assertSame('sales', $salesSnapshot['kind']);
        $this->assertSame(1, $salesSnapshot['cards'][0]['value']);
        $this->assertSame(1, $salesSnapshot['cards'][1]['value']);

        $writer = $this->userWithPreset(RolePreset::Writer, 'Writer A');
        GuideArticle::create([
            'slug' => 'writer-a-draft',
            'content_type' => 'tutorial',
            'title' => 'Writer A Draft',
            'summary' => 'Draft',
            'markdown_body' => '# Draft',
            'status' => 'draft',
            'created_by' => $writer->id,
            'updated_by' => $writer->id,
        ]);

        $writerSnapshot = $service->snapshot('writer', $writer);

        $this->assertSame('writer', $writerSnapshot['kind']);
        $this->assertSame(1, $writerSnapshot['cards'][0]['value']);
        $this->assertSame(1, $writerSnapshot['cards'][1]['value']);
    }
}
