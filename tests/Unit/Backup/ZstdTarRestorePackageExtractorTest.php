<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\RestoreFailure;
use App\Modules\Backup\Infrastructure\ZstdTarRestorePackageExtractor;
use Tests\TestCase;

final class ZstdTarRestorePackageExtractorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = '/private/tmp/byl-zstd-restore-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
        config(['backup.tooling.zstd' => '/opt/homebrew/bin/zstd', 'backup.tooling.tar' => '/usr/bin/tar']);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_valid_archive_extracts_exactly_four_regular_files(): void
    {
        $package = $this->archive(['database.sql.gz', 'storage.tar.zst', 'manifest.json', 'checksums.sha256']);
        $destination = $this->root.'/destination';
        mkdir($destination, 0700);

        (new ZstdTarRestorePackageExtractor())->extract($package, $destination);

        self::assertSame(['checksums.sha256', 'database.sql.gz', 'manifest.json', 'storage.tar.zst'], $this->entries($destination));
        foreach ($this->entries($destination) as $file) self::assertFalse(is_link($destination.'/'.$file));
    }

    public function test_unexpected_symlink_and_hardlink_entries_are_rejected(): void
    {
        foreach (['unexpected' => ['database.sql.gz', 'storage.tar.zst', 'manifest.json', 'checksums.sha256', 'extra.txt'], 'symlink' => ['database.sql.gz', 'storage.tar.zst', 'manifest.json', 'checksums.sha256'], 'hardlink' => ['database.sql.gz', 'storage.tar.zst', 'manifest.json', 'checksums.sha256']] as $kind => $files) {
            $package = $this->archive($files, $kind);
            $destination = $this->root.'/destination-'.$kind;
            mkdir($destination, 0700);
            try {
                (new ZstdTarRestorePackageExtractor())->extract($package, $destination);
                self::fail("Expected {$kind} archive to be rejected.");
            } catch (RestoreFailure $e) {
                self::assertSame('unsafe_archive_entry', $e->reasonCode, $kind);
            }
        }
    }

    public function test_path_traversal_entry_is_rejected_before_extraction(): void
    {
        $fixture = $this->root.'/traversal-source.txt';
        file_put_contents($fixture, 'malicious');
        $tar = $this->root.'/traversal.tar';
        $python = <<<'PYTHON'
import io, tarfile, sys
with tarfile.open(sys.argv[1], 'w') as archive:
    info = tarfile.TarInfo('../escape.txt')
    data = b'malicious'
    info.size = len(data)
    archive.addfile(info, io.BytesIO(data))
PYTHON;
        $this->executeCommand(['/usr/bin/python3', '-c', $python, $tar]);
        $package = $this->root.'/traversal.tar.zst';
        $this->executeCommand(['/opt/homebrew/bin/zstd', '-q', '-f', $tar, '-o', $package]);
        $destination = $this->root.'/destination-traversal';
        mkdir($destination, 0700);

        try {
            (new ZstdTarRestorePackageExtractor())->extract($package, $destination);
            self::fail('Expected traversal archive to be rejected.');
        } catch (RestoreFailure $e) {
            self::assertSame('unsafe_archive_entry', $e->reasonCode);
        }
        self::assertFileDoesNotExist($this->root.'/escape.txt');
    }

    private function archive(array $files, string $kind = 'regular'): string
    {
        $fixture = $this->root.'/fixture-'.$kind;
        mkdir($fixture, 0700, true);
        foreach ($files as $file) file_put_contents($fixture.'/'.$file, $file);
        if ($kind === 'symlink') { unlink($fixture.'/storage.tar.zst'); symlink('database.sql.gz', $fixture.'/storage.tar.zst'); }
        if ($kind === 'hardlink') { unlink($fixture.'/storage.tar.zst'); link($fixture.'/database.sql.gz', $fixture.'/storage.tar.zst'); }
        $tar = $this->root.'/'.$kind.'.tar';
        $this->executeCommand(['/usr/bin/tar', '-cf', $tar, '-C', $fixture, ...$files]);
        $package = $this->root.'/'.$kind.'.tar.zst';
        $this->executeCommand(['/opt/homebrew/bin/zstd', '-q', '-f', $tar, '-o', $package]);
        return $package;
    }

    private function executeCommand(array $command): void
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
    }

    private function entries(string $path): array
    {
        $entries = array_values(array_filter(scandir($path) ?: [], fn (string $entry): bool => ! in_array($entry, ['.', '..'], true)));
        sort($entries);
        return $entries;
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path) || is_link($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) { if (in_array($entry, ['.', '..'], true)) continue; $child = $path.'/'.$entry; if (is_dir($child) && ! is_link($child)) $this->removeTree($child); else @unlink($child); }
        @rmdir($path);
    }
}
