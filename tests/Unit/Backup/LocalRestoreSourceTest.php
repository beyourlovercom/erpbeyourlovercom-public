<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\RestoreFailure;
use App\Modules\Backup\Infrastructure\LocalRestoreSource;
use Tests\TestCase;

final class LocalRestoreSourceTest extends TestCase
{
    private string $root;
    private string $runDirectory;
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = '/private/tmp/byl-local-restore-'.bin2hex(random_bytes(4));
        $this->runDirectory = $this->root.'/restore/run-1';
        mkdir($this->runDirectory, 0700, true);
        config(['backup.storage.workspace' => $this->root]);
        $this->source = $this->root.'/byl-erp-full-20260901-000000.tar.zst.enc';
        file_put_contents($this->source, 'encrypted-source');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_confined_encrypted_source_is_copied_and_reverified(): void
    {
        $destination = $this->runDirectory.'/source.tar.zst.enc';
        $sha = hash_file('sha256', $this->source);
        (new LocalRestoreSource())->materialize($this->source, $destination, $sha);
        self::assertFileExists($destination);
        self::assertSame($sha, hash_file('sha256', $destination));
    }

    public function test_suffix_and_checksum_mismatch_fail_closed(): void
    {
        $wrong = $this->root.'/wrong.bin';
        file_put_contents($wrong, 'encrypted-source');
        $this->assertReason('invalid_source_suffix', function () use ($wrong): void {
            (new LocalRestoreSource())->materialize(
                $wrong,
                $this->runDirectory.'/source.tar.zst.enc',
                hash_file('sha256', $wrong),
            );
        });

        $this->assertReason('encrypted_checksum_mismatch', function (): void {
            (new LocalRestoreSource())->materialize(
                $this->source,
                $this->runDirectory.'/source.tar.zst.enc',
                str_repeat('0', 64),
            );
        });
    }

    public function test_source_outside_backup_root_is_rejected(): void
    {
        $outside = sys_get_temp_dir().'/outside-'.bin2hex(random_bytes(4)).'.tar.zst.enc';
        file_put_contents($outside, 'outside');
        try {
            $this->assertReason('source_path_outside_allowed_root', function () use ($outside): void {
                (new LocalRestoreSource())->materialize(
                    $outside,
                    $this->runDirectory.'/source.tar.zst.enc',
                    hash_file('sha256', $outside),
                );
            });
        } finally {
            @unlink($outside);
        }
    }

    private function assertReason(string $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected restore failure [{$reason}].");
        } catch (RestoreFailure $e) {
            self::assertSame($reason, $e->reasonCode);
        }
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) continue;
            $child = $path.'/'.$entry;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
