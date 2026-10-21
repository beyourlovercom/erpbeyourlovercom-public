<?php

namespace Tests\Feature\Backup;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class BackupRouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'backup-test-'.uniqid(), 'label' => 'Backup test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    public function test_backup_index_requires_backup_view_and_renders_the_pre_engine_page(): void
    {
        $this->actingAs($this->userWith([]))->get('/settings/backup')->assertForbidden();
        $this->actingAs($this->userWith(['backup.view']))->get('/settings/backup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Backup/Index')
                ->where('actions.0.available', false)
                ->where('history', []));
    }

    public function test_legacy_singular_path_redirects_to_canonical_backup_route(): void
    {
        $this->actingAs($this->userWith(['backup.view']))->get('/setting/backup')->assertRedirect('/settings/backup');
    }

    public function test_backup_surface_registers_no_operational_http_methods(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route) => in_array($route->uri(), ['settings/backup', 'setting/backup'], true));
        $this->assertSame(['GET', 'GET'], $routes->map(fn ($route) => $route->methods()[0])->values()->all());
        $this->assertFalse($routes->contains(fn ($route) => in_array('POST', $route->methods(), true) || in_array('DELETE', $route->methods(), true)));
    }
}
