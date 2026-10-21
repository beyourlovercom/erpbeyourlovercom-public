<?php

namespace Tests\Feature\Backup;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class BackupAutomaticSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_page_exposes_disabled_daily_default_and_fixed_retention(): void
    {
        $user = $this->userWith(['backup.view']);

        $this->actingAs($user)->get('/settings/backup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Backup/Index')
                ->where('automaticBackup.enabled', false)
                ->where('automaticBackup.cadence', 'daily')
                ->where('automaticBackup.retentionCount', 5)
                ->where('automaticBackup.canManage', false)
                ->where('automaticBackup.options.0.detail', '每天 02:00 UTC')
                ->where('automaticBackup.options.1.detail', '每周一 02:00 UTC')
                ->where('automaticBackup.options.2.detail', '每月 1 日 02:00 UTC'));
    }

    public function test_backup_manage_permission_is_required_to_change_automatic_policy(): void
    {
        $viewOnly = $this->userWith(['backup.view']);
        $this->actingAs($viewOnly)->put('/settings/backup/automatic', [
            'enabled' => true,
            'cadence' => 'weekly',
        ])->assertForbidden();

        $manager = $this->userWith(['backup.view', 'backup.manage']);
        $this->actingAs($manager)->put('/settings/backup/automatic', [
            'enabled' => true,
            'cadence' => 'weekly',
        ])->assertRedirect('/settings/backup');

        $this->assertDatabaseHas('backup_schedule_settings', [
            'id' => 1,
            'enabled' => 1,
            'cadence' => 'weekly',
        ]);
    }

    public function test_invalid_cadence_is_rejected_without_mutating_setting(): void
    {
        $manager = $this->userWith(['backup.view', 'backup.manage']);
        $before = DB::table('backup_schedule_settings')->where('id', 1)->first();

        $this->actingAs($manager)->from('/settings/backup')->put('/settings/backup/automatic', [
            'enabled' => true,
            'cadence' => 'hourly',
        ])->assertRedirect('/settings/backup')->assertSessionHasErrors('cadence');

        $after = DB::table('backup_schedule_settings')->where('id', 1)->first();
        $this->assertSame($before->cadence, $after->cadence);
        $this->assertSame((int) $before->enabled, (int) $after->enabled);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'backup-auto-'.uniqid(), 'label' => 'Backup auto']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
