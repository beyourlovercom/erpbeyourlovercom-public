<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\BackupArtifactCleanupService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class BackupArtifactCleanupServiceTest extends TestCase
{
    public function test_workspace_cleanup_refuses_a_path_outside_configured_workspace(): void
    {
        $workspace = base_path('storage/framework/testing/backup-workspace');
        $outside = base_path('storage/framework/testing/backup-outside');
        @mkdir($workspace, 0700, true); @mkdir($outside, 0700, true);
        $sentinel = $outside.'/sentinel.txt'; file_put_contents($sentinel, 'keep'); Config::set('backup.storage.workspace', $workspace);

        (new BackupArtifactCleanupService($this->app->make(\App\Modules\Backup\Application\Contracts\BackupRepository::class)))->cleanupWorkspace($outside);

        $this->assertFileExists($sentinel); @unlink($sentinel); @rmdir($outside); @rmdir($workspace);
    }

    public function test_untracked_final_asset_deletion_is_confined_to_backup_root(): void
    {
        $workspace = base_path('storage/framework/testing/backup-workspace-assets');
        $outside = base_path('storage/framework/testing/backup-outside-assets');
        @mkdir($workspace, 0700, true); @mkdir($outside, 0700, true);
        $insideAsset = $workspace.'/byl-erp-database-test.tar.zst.enc';
        $outsideAsset = $outside.'/byl-erp-database-test.tar.zst.enc';
        file_put_contents($insideAsset, 'inside'); file_put_contents($outsideAsset, 'outside');
        Config::set('backup.storage.workspace', $workspace);

        $cleanup = new BackupArtifactCleanupService($this->app->make(\App\Modules\Backup\Application\Contracts\BackupRepository::class));
        $cleanup->deleteUntrackedFinalAsset($insideAsset);
        $cleanup->deleteUntrackedFinalAsset($outsideAsset);

        $this->assertFileDoesNotExist($insideAsset);
        $this->assertFileExists($outsideAsset);
        @unlink($outsideAsset); @rmdir($outside); @rmdir($workspace);
    }
}
