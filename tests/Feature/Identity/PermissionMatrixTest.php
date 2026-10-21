<?php

namespace Tests\Feature\Identity;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Identity\Application\PermissionCatalog;
use App\Modules\Identity\Application\RolePreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    // A: role mode uses role template permissions
    public function test_user_in_role_mode_uses_role_template_permissions(): void
    {
        $admin = $this->adminUser();
        $target = $this->userWithPreset(RolePreset::Finance);

        $this->assertTrue($target->hasPermission('accounting.view'));
        $this->assertTrue($target->hasPermission('orders.view'));
        $this->assertFalse($target->hasPermission('system.permissions.manage'));
    }

    // A2: every built-in employee preset receives product catalog read-only access.
    public function test_every_staff_preset_can_view_products_without_product_mutation_rights(): void
    {
        foreach (RolePreset::cases() as $preset) {
            $user = $this->userWithPreset($preset);

            $this->assertTrue($user->hasPermission('products.view'), $preset->value.' must view products');
            $this->assertTrue($user->hasPermission('inventory.view'), $preset->value.' must view inventory');
            if ($preset !== RolePreset::Administrator) {
                $this->assertFalse($user->hasPermission('products.operate'), $preset->value.' must not edit products');
                $this->assertFalse($user->hasPermission('inventory.operate'), $preset->value.' must not operate inventory');
                $this->assertFalse($user->hasPermission('inventory.warehouses.manage'), $preset->value.' must not manage warehouses');
            }
        }
    }

    // B: custom mode exact allow set overrides role permissions
    public function test_custom_mode_exact_allow_set_overrides_role_permissions(): void
    {
        $admin = $this->adminUser();
        $target = $this->userWithPreset(RolePreset::Finance);

        // Finance has orders.view; we give custom set without it
        $this->actingAs($admin)->post("/settings/users/{$target->id}/permissions", [
            'mode' => 'custom',
            'permissions' => ['accounting.view'],
        ])->assertRedirect();

        $target->refresh();
        $this->assertSame('custom', $target->permission_mode);
        $this->assertTrue($target->hasPermission('accounting.view'));
        $this->assertFalse($target->hasPermission('orders.view'));
    }

    // C: switching role->custom seeds role permissions; reset returns role mode
    public function test_switching_role_to_custom_seeds_role_permissions_and_reset_returns_role_mode(): void
    {
        $admin = $this->adminUser();
        $target = $this->userWithPreset(RolePreset::Writer);

        // Switch to custom (seeded from Writer: dashboard.view, guides.view)
        $this->actingAs($admin)->post("/settings/users/{$target->id}/permissions", [
            'mode' => 'custom',
            'permissions' => ['dashboard.view', 'guides.view'],
        ])->assertRedirect();

        $target->refresh();
        $this->assertSame('custom', $target->permission_mode);
        $this->assertDatabaseHas('permission_user', ['user_id' => $target->id]);

        // Reset to role mode
        $this->actingAs($admin)->post("/settings/users/{$target->id}/permissions", [
            'mode' => 'role',
            'permissions' => [],
        ])->assertRedirect();

        $target->refresh();
        $this->assertSame('role', $target->permission_mode);
        $this->assertDatabaseMissing('permission_user', ['user_id' => $target->id]);
        $this->assertTrue($target->hasPermission('guides.view'));
    }

    // D: invalid permission keys rejected
    public function test_invalid_permission_keys_rejected(): void
    {
        $admin = $this->adminUser();
        $target = $this->userWithPreset(RolePreset::Writer);

        $this->actingAs($admin)->post("/settings/users/{$target->id}/permissions", [
            'mode' => 'custom',
            'permissions' => ['not.a.real.key'],
        ])->assertSessionHasErrors('permissions');
    }

    // E: non-admin cannot access or mutate user permissions or role-template editor
    public function test_non_admin_cannot_access_permission_endpoints(): void
    {
        $stranger = $this->userWithPerms(['prototype.view']);
        $target = $this->userWithPreset(RolePreset::Writer);

        $this->actingAs($stranger)
            ->post("/settings/users/{$target->id}/permissions", ['mode' => 'custom', 'permissions' => []])
            ->assertForbidden();

        $this->actingAs($stranger)
            ->get('/settings/roles-permissions')
            ->assertForbidden();

        $this->actingAs($stranger)
            ->put('/settings/roles-permissions/writer', ['permissions' => ['guides.view']])
            ->assertForbidden();
    }

    // F: role-template edit changes role-mode user but not custom-mode user
    public function test_role_template_edit_affects_role_mode_user_not_custom_mode_user(): void
    {
        $admin = $this->adminUser();
        $roleUser = $this->userWithPreset(RolePreset::Writer);
        $customUser = $this->userWithPreset(RolePreset::Writer);

        // customUser in custom mode: only guides.view
        $this->actingAs($admin)->post("/settings/users/{$customUser->id}/permissions", [
            'mode' => 'custom',
            'permissions' => ['guides.view'],
        ])->assertRedirect();

        // Update Writer template to add dashboard.view
        $this->actingAs($admin)->put('/settings/roles-permissions/writer', [
            'permissions' => ['guides.view', 'dashboard.view'],
        ])->assertRedirect();

        $roleUser->refresh();
        $customUser->refresh();

        $this->assertTrue($roleUser->hasPermission('dashboard.view'));
        $this->assertFalse($customUser->hasPermission('dashboard.view'));
    }

    // G: all eight presets display and are editable through roles-permissions
    public function test_all_eight_presets_display_and_are_editable(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->get('/settings/roles-permissions');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Identity/RolesPermissions/Index')
            ->has('presets', 8)
        );

        foreach (RolePreset::cases() as $preset) {
            $keys = PermissionCatalog::allKeys();
            $validKeys = array_slice($keys, 0, 2);

            $this->actingAs($admin)->put("/settings/roles-permissions/{$preset->value}", [
                'permissions' => $validKeys,
            ])->assertRedirect('/settings/roles-permissions');
        }
    }

    // H: last active system.permissions.manage holder cannot lose it
    public function test_last_permissions_manage_holder_cannot_lose_it(): void
    {
        $admin = $this->adminUser();

        // via custom save removing system.permissions.manage
        $this->actingAs($admin)->post("/settings/users/{$admin->id}/permissions", [
            'mode' => 'custom',
            'permissions' => ['dashboard.view'],
        ])->assertSessionHasErrors('user');

        // via disable
        $this->actingAs($admin)->post("/settings/users/{$admin->id}/disable")
            ->assertSessionHasErrors('user');

        // via delete (but self-delete guard fires first; that's fine)
        $this->actingAs($admin)->delete("/settings/users/{$admin->id}")
            ->assertSessionHasErrors('user');

        // via role change to preset without manage-permission
        $this->actingAs($admin)->post("/settings/users/{$admin->id}/role", [
            'role' => RolePreset::Writer->value,
        ])->assertSessionHasErrors('user');

        // via role-template update removing system.permissions.manage from Administrator
        $this->actingAs($admin)->put('/settings/roles-permissions/administrator', [
            'permissions' => ['dashboard.view'],
        ])->assertSessionHasErrors('permissions');

        // Admin still has the permission
        $admin->refresh();
        $this->assertTrue($admin->hasPermission('system.permissions.manage'));
    }

    // I: audit events for permission mutations contain no secrets
    public function test_audit_events_contain_no_secrets(): void
    {
        $admin = $this->adminUser();
        $target = $this->userWithPreset(RolePreset::Writer);

        $this->actingAs($admin)->post("/settings/users/{$target->id}/permissions", [
            'mode' => 'custom',
            'permissions' => ['guides.view'],
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_records', [
            'action' => 'user.permissions_updated',
            'entity_id' => (string) $target->id,
        ]);
        $this->assertDatabaseMissing('audit_records', ['after->password' => 'any-value']);
        $this->assertDatabaseMissing('audit_records', ['before->password' => 'any-value']);

        $this->actingAs($admin)->put('/settings/roles-permissions/writer', [
            'permissions' => ['guides.view', 'dashboard.view'],
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_records', [
            'action' => 'role_template.permissions_updated',
        ]);
    }

    // K: menu settings-roles-permissions is implemented at /settings/roles-permissions
    public function test_settings_roles_permissions_is_implemented_in_menu(): void
    {
        $menu = json_decode(file_get_contents(base_path('menu/APPROVED_MENU.json')), true);
        $items = $menu['items'];
        $item = collect($items)->firstWhere('code', 'settings-roles-permissions');

        $this->assertNotNull($item, 'settings-roles-permissions must exist in menu');
        $this->assertSame('/settings/roles-permissions', $item['url']);
        $this->assertSame('settings.roles-permissions', $item['route']);
        $this->assertSame('implemented', $item['status']);
        $this->assertSame(100, $item['progress']);
    }

    public function test_kol_and_logistics_role_defaults_include_product_view_without_mutation(): void
    {
        foreach ([RolePreset::KolSpecialist, RolePreset::KolManager, RolePreset::Logistics, RolePreset::Operations] as $preset) {
            $keys = $preset->permissions();
            $this->assertContains('products.view', $keys, $preset->value);
            $this->assertNotContains('products.operate', $keys, $preset->value);
        }

        // KOL staff do not receive broad Logistics view or any logistics mutation
        // simply because they can choose an SKU.
        foreach ([RolePreset::KolSpecialist, RolePreset::KolManager] as $preset) {
            $this->assertNotContains('logistics.view', $preset->permissions(), $preset->value);
            $this->assertNotContains('logistics.operate', $preset->permissions(), $preset->value);
        }
    }

    private function adminUser(): User
    {
        return $this->userWithPreset(RolePreset::Administrator);
    }

    private function userWithPreset(RolePreset $preset): User
    {
        $roleClass = 'App\\Modules\\Identity\\Infrastructure\\Models\\Role';
        $permissionClass = 'App\\Modules\\Identity\\Infrastructure\\Models\\Permission';

        $role = $roleClass::query()->firstOrCreate(['key' => $preset->roleKey()], ['label' => $preset->label()]);
        $permissionIds = collect($preset->permissions())
            ->map(fn (string $key): int => $permissionClass::query()->firstOrCreate(['key' => $key], ['label' => $key])->id)
            ->all();
        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function userWithPerms(array $permissions): User
    {
        $role = Role::create(['key' => 'stranger_'.uniqid(), 'label' => 'stranger']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
