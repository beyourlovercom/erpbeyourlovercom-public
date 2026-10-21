<?php

namespace App\Modules\Backup\Application;

use RuntimeException;

final class PackageBuilder
{
    public function build(string $directory, string $destination): void
    {
        $tar = (string) config('backup.tooling.tar', 'tar'); $zstd = (string) config('backup.tooling.zstd', 'zstd');
        $tarPath = $destination.'.tar';
        $process = proc_open([$tar, '-cf', $tarPath, '-C', $directory, 'database.sql.gz', 'storage.tar.zst', 'manifest.json', 'checksums.sha256'], [2 => ['pipe', 'w']], $pipes, $directory);
        if (! is_resource($process)) throw new RuntimeException('Unable to create backup package.');
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Backup package creation failed.');
        $compress = proc_open([$zstd, '-q', '-T0', '-f', $tarPath, '-o', $destination], [2 => ['pipe', 'w']], $z, $directory);
        if (! is_resource($compress)) throw new RuntimeException('Unable to compress backup package.');
        $error = stream_get_contents($z[2]); fclose($z[2]);
        if (proc_close($compress) !== 0) { @unlink($destination); throw new RuntimeException('Backup package compression failed.'); }
        @unlink($tarPath);
    }
}
