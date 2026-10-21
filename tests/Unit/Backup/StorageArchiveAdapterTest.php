<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\StorageArchiveAdapter;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

final class StorageArchiveAdapterTest extends TestCase
{
    public function test_workspace_nested_under_included_root_is_rejected_before_external_tools(): void
    {
        Config::set('backup.storage.included_roots', ['storage/app/private']);
        Config::set('backup.storage.workspace', 'storage/app/private/future-backup-workspace');
        Config::set('backup.tooling.tar', '/definitely/not-invoked/tar');
        Config::set('backup.tooling.zstd', '/definitely/not-invoked/zstd');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('overlaps');
        (new StorageArchiveAdapter())->archive(base_path('storage/framework/testing/archive-output'));
    }
}
