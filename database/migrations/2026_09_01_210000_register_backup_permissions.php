<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const PERMISSIONS = [
        'backup.view' => '查看备份与迁移',
        'backup.create' => '创建备份',
        'backup.download' => '下载备份',
        'backup.publish_github' => '发布备份到 GitHub',
        'backup.delete' => '删除备份文件',
        'backup.restore' => '执行恢复',
        'backup.manage' => '管理备份策略',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $key => $label) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                ['label' => $label, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $administratorId = DB::table('roles')->where('key', 'role_preset_administrator')->value('id');
        if ($administratorId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', array_keys(self::PERMISSIONS))
            ->pluck('id', 'key');

        foreach ($permissionIds as $permissionId) {
            DB::table('permission_role')->updateOrInsert([
                'permission_id' => $permissionId,
                'role_id' => $administratorId,
            ], []);
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
        $administratorId = DB::table('roles')->where('key', 'role_preset_administrator')->value('id');

        if ($administratorId !== null) {
            DB::table('permission_role')->where('role_id', $administratorId)->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->delete();
    }
};
