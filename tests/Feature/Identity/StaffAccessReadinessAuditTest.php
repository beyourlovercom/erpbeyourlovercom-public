<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StaffAccessReadinessAuditTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function audit(string $environment = 'testing', int $expectedExit = 0): array
    {
        $code = Artisan::call('identity:staff-access-audit', [
            '--expected-environment' => $environment,
            '--json' => true,
        ]);
        $this->assertSame($expectedExit, $code);

        return json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedRole(RolePreset $preset, array $grants): Role
    {
        $role = Role::query()->create(['key' => $preset->roleKey(), 'label' => $preset->label()]);
        foreach ($grants as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission->id);
        }

        return $role;
    }

    private function seedAllRoles(): array
    {
        return [
            'kol_specialist' => $this->seedRole(RolePreset::KolSpecialist, [
                'kol.view', 'orders.view', 'orders.create', 'products.view',
            ]),
            'kol_manager' => $this->seedRole(RolePreset::KolManager, [
                'kol.view', 'kol.views.manage', 'orders.view', 'orders.create', 'products.view',
            ]),
            'logistics' => $this->seedRole(RolePreset::Logistics, ['logistics.view', 'products.view']),
            'operations' => $this->seedRole(RolePreset::Operations, [
                'logistics.view', 'kol.view', 'kol.views.manage', 'products.view',
            ]),
        ];
    }

    public function test_missing_existing_role_permission_is_explicitly_reported_without_pii(): void
    {
        $roles = $this->seedAllRoles();
        $roles['kol_specialist']->permissions()->detach(
            Permission::query()->where('key', 'products.view')->value('id'),
        );
        $emma = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $emma->roles()->attach($roles['kol_specialist']);

        $report = $this->audit(expectedExit: 1);
        $this->assertSame('review_required', $report['status']);
        $this->assertSame('PERSISTED_ROLE_ACCESS_DRIFT', $report['code']);
        $specialist = $report['roles']['kol_specialist'];
        $this->assertSame(['products.view'], $specialist['missing_template_permissions']);
        $this->assertSame(1, $specialist['active_assignees']);
        $this->assertSame(1, $specialist['users_missing_any_required_permission']);
        $this->assertSame(1, $specialist['missing_permission_user_counts']['products.view']);
        $this->assertStringNotContainsString($emma->email, json_encode($report));
    }

    public function test_custom_mode_permission_gap_is_visible_without_mutation(): void
    {
        $roles = $this->seedAllRoles();
        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'custom']);
        $user->roles()->attach($roles['kol_manager']);
        foreach (['kol.view', 'kol.views.manage', 'orders.view', 'orders.create'] as $key) {
            $user->customPermissions()->attach(Permission::query()->where('key', $key)->value('id'));
        }
        $before = DB::table('permission_user')->count();

        $report = $this->audit(expectedExit: 1);
        $manager = $report['roles']['kol_manager'];
        $this->assertSame([], $manager['missing_template_permissions']);
        $this->assertSame(1, $manager['custom_permission_mode_assignees']);
        $this->assertSame(1, $manager['users_missing_any_required_permission']);
        $this->assertSame(1, $manager['missing_permission_user_counts']['products.view']);
        $this->assertSame($before, DB::table('permission_user')->count());
    }

    public function test_complete_roles_and_assignees_pass_without_database_writes(): void
    {
        $roles = $this->seedAllRoles();
        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $user->roles()->attach($roles['kol_specialist']);

        $writes = [];
        DB::listen(function ($event) use (&$writes): void {
            if (preg_match('/^\\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });

        $report = $this->audit();
        $this->assertSame('pass', $report['status']);
        $this->assertSame('ROLE_ACCESS_READY', $report['code']);
        $this->assertSame([], $writes, 'Readiness audit must never mutate the database');
    }

    public function test_production_environment_is_always_blocked_even_if_explicitly_confirmed(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $report = $this->audit('production', expectedExit: 1);
        $this->assertSame('blocked', $report['status']);
        $this->assertSame('DEV_ENVIRONMENT_GATE_CLOSED', $report['code']);
        $this->assertSame([], $report['roles']);
    }

    public function test_wrong_environment_confirmation_fails_closed_before_queries(): void
    {
        $report = $this->audit('local', expectedExit: 1);
        $this->assertSame('blocked', $report['status']);
        $this->assertSame('DEV_ENVIRONMENT_GATE_CLOSED', $report['code']);
        $this->assertSame([], $report['roles']);
    }
}
