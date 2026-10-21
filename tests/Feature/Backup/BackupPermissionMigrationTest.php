<?php

namespace Tests\Feature\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BackupPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = [
        'backup.view', 'backup.create', 'backup.download', 'backup.publish_github',
        'backup.delete', 'backup.restore', 'backup.manage',
    ];

    public function test_registration_is_idempotent_and_attaches_existing_administrator(): void
    {
        $roleId = DB::table('roles')->insertGetId(['key' => 'role_preset_administrator', 'label' => '管理员', 'created_at' => now(), 'updated_at' => now()]);
        $migration = require base_path('database/migrations/2026_09_01_210000_register_backup_permissions.php');

        $migration->up();
        $migration->up();

        $this->assertSame(7, DB::table('permissions')->whereIn('key', self::KEYS)->count());
        $this->assertSame(7, DB::table('permission_role')->where('role_id', $roleId)->count());
        $this->assertSame(7, DB::table('permission_role')->where('role_id', $roleId)->distinct('permission_id')->count('permission_id'));
    }

    public function test_registration_does_not_require_an_administrator_role(): void
    {
        $migration = require base_path('database/migrations/2026_09_01_210000_register_backup_permissions.php');
        $migration->up();

        $this->assertSame(7, DB::table('permissions')->whereIn('key', self::KEYS)->count());
        $this->assertSame(0, DB::table('permission_role')->whereIn('permission_id', DB::table('permissions')->whereIn('key', self::KEYS)->pluck('id'))->count());
    }
}
