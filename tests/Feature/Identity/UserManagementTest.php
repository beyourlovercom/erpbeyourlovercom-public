<?php

namespace Tests\Feature\Identity;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Identity\Application\RolePreset;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_and_unauthorized_access_is_rejected_server_side(): void
    {
        $this->get('/settings/users')->assertRedirect('/login');

        $stranger = $this->userWith(['prototype.view'], 'stranger');
        $this->actingAs($stranger)->get('/settings/users')->assertForbidden();
        $this->actingAs($stranger)->get('/settings/users/create')->assertForbidden();
        $this->actingAs($stranger)->post('/settings/users', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_legacy_user_get_routes_redirect_to_canonical_settings_users_routes(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        $target = User::factory()->create();

        $this->actingAs($admin)->get('/user')->assertRedirect('/settings/users');
        $this->actingAs($admin)->get('/user/create')->assertRedirect('/settings/users/create');
        $this->actingAs($admin)->get('/user/'.$target->id.'/edit')->assertRedirect('/settings/users/'.$target->id.'/edit');

        $this->actingAs($admin)->post('/user', [])->assertStatus(405);
        $this->actingAs($admin)->patch('/user/'.$target->id, [])->assertNotFound();
    }

    public function test_admin_can_create_view_and_update_a_user(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);

        $response = $this->actingAs($admin)->post('/settings/users', [
            'name' => 'Logistics Operator',
            'email' => 'logistics-op@example.test',
            'password' => 'a-strong-password-123',
            'password_confirmation' => 'a-strong-password-123',
            'role' => RolePreset::Logistics->value,
        ]);
        $response->assertRedirect('/settings/users');

        $created = User::query()->where('email', 'logistics-op@example.test')->firstOrFail();
        $this->assertTrue($created->is_active);
        $this->assertDatabaseHas('audit_records', ['action' => 'user.created', 'entity_id' => (string) $created->id]);
        $this->assertDatabaseMissing('audit_records', ['after->password' => 'a-strong-password-123']);

        $role = $created->roles()->firstOrFail();
        $this->assertSame(RolePreset::Logistics->roleKey(), $role->key);
        $expectedPermissions = RolePreset::Logistics->permissions();
        sort($expectedPermissions);
        $this->assertSame($expectedPermissions, $role->permissions()->pluck('key')->sort()->values()->all());

        $this->actingAs($admin)->get('/settings/users')->assertInertia(fn (Assert $page) => $page
            ->component('Identity/Users/Index')
            ->where('users', fn ($users): bool => collect($users)->pluck('email')->contains('logistics-op@example.test')));

        $this->actingAs($admin)->patch('/settings/users/'.$created->id, ['name' => 'Renamed Operator', 'email' => 'logistics-op@example.test'])
            ->assertRedirect('/settings/users');
        $this->assertDatabaseHas('users', ['id' => $created->id, 'name' => 'Renamed Operator']);
        $this->assertDatabaseHas('audit_records', ['action' => 'user.updated', 'entity_id' => (string) $created->id]);
    }

    public function test_email_must_be_unique_on_create_and_update(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        $existing = User::factory()->create(['email' => 'taken@example.test']);

        $this->actingAs($admin)->post('/settings/users', [
            'name' => 'Dup', 'email' => 'taken@example.test',
            'password' => 'a-strong-password-123', 'password_confirmation' => 'a-strong-password-123',
            'role' => RolePreset::Writer->value,
        ])->assertSessionHasErrors('email');

        $other = User::factory()->create(['email' => 'other@example.test']);
        $this->actingAs($admin)->patch('/settings/users/'.$other->id, ['name' => 'Other', 'email' => 'taken@example.test'])
            ->assertSessionHasErrors('email');
        $this->assertSame('other@example.test', $other->fresh()->email);
        $this->assertSame('taken@example.test', $existing->fresh()->email);
    }

    public function test_admin_can_set_and_reset_a_password_without_ever_persisting_or_exposing_the_plaintext(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        $target = User::factory()->create(['password' => Hash::make('original-password-123')]);
        $originalHash = $target->password;

        $this->actingAs($admin)->post('/settings/users/'.$target->id.'/password', [
            'password' => 'brand-new-password-456',
            'password_confirmation' => 'brand-new-password-456',
        ])->assertRedirect('/settings/users');

        $target->refresh();
        $this->assertNotSame($originalHash, $target->password);
        $this->assertTrue(Hash::check('brand-new-password-456', $target->password));
        $this->assertDatabaseHas('audit_records', ['action' => 'user.password_reset', 'entity_id' => (string) $target->id]);
        $this->assertDatabaseMissing('audit_records', ['after->password' => 'brand-new-password-456']);

        $editResponse = $this->actingAs($admin)->get('/settings/users/'.$target->id.'/edit');
        $editResponse->assertOk();
        $editResponse->assertDontSee('brand-new-password-456', false);
        $editResponse->assertDontSee($target->password, false);
    }

    public function test_password_must_meet_minimum_strength_and_be_confirmed(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        $target = User::factory()->create();

        $this->actingAs($admin)->post('/settings/users/'.$target->id.'/password', [
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->actingAs($admin)->post('/settings/users/'.$target->id.'/password', [
            'password' => 'a-strong-password-123', 'password_confirmation' => 'different-password-999',
        ])->assertSessionHasErrors('password');
    }

    public function test_admin_can_enable_and_disable_another_user(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        $target = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->post('/settings/users/'.$target->id.'/disable')->assertRedirect('/settings/users');
        $this->assertFalse($target->fresh()->is_active);
        $this->assertDatabaseHas('audit_records', ['action' => 'user.disabled', 'entity_id' => (string) $target->id]);

        $this->actingAs($admin)->post('/settings/users/'.$target->id.'/enable')->assertRedirect('/settings/users');
        $this->assertTrue($target->fresh()->is_active);
        $this->assertDatabaseHas('audit_records', ['action' => 'user.enabled', 'entity_id' => (string) $target->id]);
    }

    public function test_disabling_a_user_through_this_feature_still_terminates_their_active_session_via_existing_middleware(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        $target = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->post('/settings/users/'.$target->id.'/disable')->assertRedirect('/settings/users');
        $this->assertFalse($target->fresh()->is_active);

        $this->actingAs($target)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_disabled_user_cannot_authenticate_through_fortify(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        $target = User::factory()->create(['is_active' => true, 'password' => Hash::make('target-password-123')]);
        $this->actingAs($admin)->post('/settings/users/'.$target->id.'/disable')->assertRedirect('/settings/users');
        $this->post('/logout');
        $this->assertGuest();

        $response = $this->post('/login', ['email' => $target->email, 'password' => 'target-password-123']);

        $response->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_admin_cannot_disable_or_delete_their_own_account(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);

        $this->actingAs($admin)->post('/settings/users/'.$admin->id.'/disable')->assertSessionHasErrors('user');
        $this->assertTrue($admin->fresh()->is_active);

        $this->actingAs($admin)->delete('/settings/users/'.$admin->id)->assertSessionHasErrors('user');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_admin_can_change_a_users_role_preset_and_stale_role_assignment_is_removed(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        $target = User::factory()->create();
        $legacyRole = Role::create(['key' => 'legacy_role', 'label' => 'Legacy']);
        $target->roles()->attach($legacyRole);

        $this->actingAs($admin)->post('/settings/users/'.$target->id.'/role', ['role' => RolePreset::Finance->value])
            ->assertRedirect('/settings/users');

        $target->refresh();
        $this->assertSame([RolePreset::Finance->roleKey()], $target->roles()->pluck('key')->all());
        $this->assertDatabaseHas('audit_records', ['action' => 'user.role_changed', 'entity_id' => (string) $target->id]);
    }

    public function test_administrator_preset_includes_permissions_manage_and_every_current_permission_key(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        $target = User::factory()->create();

        $this->actingAs($admin)->post('/settings/users/'.$target->id.'/role', ['role' => RolePreset::Administrator->value])
            ->assertRedirect('/settings/users');

        $role = $target->fresh()->roles()->firstOrFail();
        $grantedKeys = $role->permissions()->pluck('key')->sort()->values()->all();
        $this->assertContains('system.permissions.manage', $grantedKeys);
        $this->assertSame(RolePreset::allPermissionKeys(), $grantedKeys);
    }

    public function test_all_eight_role_presets_map_to_documented_permission_sets(): void
    {
        $expected = [
            RolePreset::Logistics->value => ['dashboard.view', 'inventory.view', 'logistics.operate', 'logistics.view', 'orders.view', 'products.view', 'sync.operate', 'sync.view'],
            RolePreset::SalesRepresentative->value => ['customers.operate', 'customers.view', 'dashboard.view', 'inventory.view', 'orders.create', 'orders.edit', 'orders.view', 'products.view'],
            RolePreset::KolSpecialist->value => ['dashboard.view', 'inventory.view', 'kol.operate', 'kol.payouts.request', 'kol.view', 'orders.create', 'orders.edit', 'orders.view', 'products.view'],
            RolePreset::KolManager->value => ['dashboard.view', 'inventory.view', 'kol.affiliates.manage', 'kol.approvals.manage', 'kol.operate', 'kol.payouts.approve', 'kol.payouts.request', 'kol.view', 'kol.views.manage', 'orders.create', 'orders.edit', 'orders.view', 'products.view'],
            RolePreset::Finance->value => ['accounting.view', 'analysis.view', 'dashboard.view', 'inventory.view', 'kol.payouts.manage', 'orders.view', 'products.view'],
            RolePreset::Writer->value => ['dashboard.view', 'guides.manage', 'guides.view', 'inventory.view', 'products.view'],
        ];
        foreach ($expected as $value => $keys) {
            $preset = RolePreset::from($value);
            $actual = $preset->permissions();
            sort($actual);
            $this->assertSame($keys, $actual, "preset {$value}");
        }
    }

    public function test_a_user_with_no_business_references_can_be_physically_deleted(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        $target = User::factory()->create();

        $this->actingAs($admin)->delete('/settings/users/'.$target->id)->assertRedirect('/settings/users');

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseHas('audit_records', ['action' => 'user.deleted']);
    }

    public function test_deletion_is_blocked_when_the_user_has_historical_order_ownership_and_disabling_is_still_possible(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        $target = User::factory()->create();
        $order = Order::create([
            'internal_order_number' => 'UMT-ORDER-1',
            'source_type' => OrderSourceType::Website,
            'external_order_number' => 'UMT-ORDER-1',
            'order_date' => now()->toDateString(),
            'owner_id' => $target->id,
            'order_status' => OrderStatus::PendingShipment,
        ]);

        $this->actingAs($admin)->delete('/settings/users/'.$target->id)->assertSessionHasErrors('user');
        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'owner_id' => $target->id]);

        $this->actingAs($admin)->post('/settings/users/'.$target->id.'/disable')->assertRedirect('/settings/users');
        $this->assertFalse($target->fresh()->is_active);
        $this->assertSame($target->name, $target->fresh()->name);
    }

    public function test_deletion_is_blocked_when_the_user_owns_a_company(): void
    {
        $admin = $this->userWith(['system.permissions.manage']);
        $target = User::factory()->create();
        Company::create(['name' => 'Owned Co', 'owner_id' => $target->id, 'status' => 'prospect']);

        $this->actingAs($admin)->delete('/settings/users/'.$target->id)->assertSessionHasErrors('user');
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    private function userWith(array $permissions, string $roleKey = 'identity_admin'): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
