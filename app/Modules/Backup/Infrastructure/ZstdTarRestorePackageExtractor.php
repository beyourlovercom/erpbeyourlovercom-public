<?php

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Application\Contracts\RestorePackageExtractor;
use App\Modules\Backup\Application\RestoreFailure;

final class ZstdTarRestorePackageExtractor implements RestorePackageExtractor
{
    /** @var list<string> */
    private const FILES = ['database.sql.gz', 'storage.tar.zst', 'manifest.json', 'checksums.sha256'];

    public function extract(string $package, string $destination): void
    {
        if (! is_file($package) || is_link($package) || ! is_dir($destination) || is_link($destination)) {
            throw new RestoreFailure('package_unavailable');
        }
        $existing = array_values(array_filter(scandir($destination) ?: [], static fn (string $entry): bool => ! in_array($entry, ['.', '..'], true)));
        if ($existing !== []) {
            throw new RestoreFailure('package_destination_not_empty');
        }

        $tarPath = $destination.'/package.tar';
        try {
            $this->run([(string) config('backup.tooling.zstd', 'zstd'), '-q', '-d', '-f', $package, '-o', $tarPath], $destination, 'package_decompression_failed');
            $tarSize = @filesize($tarPath);
            $maxTarBytes = (int) config('backup.restore.max_tar_bytes', 4294967296);
            if ($tarSize === false || $tarSize < 1 || $tarSize > $maxTarBytes) {
                throw new RestoreFailure('package_tar_size_invalid');
            }

            $tar = (string) config('backup.tooling.tar', 'tar');
            $list = $this->run([$tar, '-tf', $tarPath], $destination, 'package_listing_failed');
            $names = array_values(array_filter(preg_split('/\r?\n/', trim($list)) ?: [], static fn (string $name): bool => $name !== ''));
            if (count($names) !== count(self::FILES) || count(array_unique($names)) !== count(self::FILES)) {
                throw new RestoreFailure('unsafe_archive_entry');
            }
            $sortedNames = $names;
            sort($sortedNames);
            $expected = self::FILES;
            sort($expected);
            if ($sortedNames !== $expected) {
                throw new RestoreFailure('unsafe_archive_entry');
            }

            $verbose = $this->run([$tar, '-tvf', $tarPath], $destination, 'package_listing_failed');
            $lines = array_values(array_filter(preg_split('/\r?\n/', trim($verbose)) ?: [], static fn (string $line): bool => trim($line) !== ''));
            if (count($lines) !== count(self::FILES)) {
                throw new RestoreFailure('unsafe_archive_entry');
            }
            foreach ($lines as $line) {
                $trimmed = ltrim($line);
                if ($trimmed === '' || $trimmed[0] !== '-' || str_contains($line, ' -> ') || str_contains($line, ' link to ')) {
                    throw new RestoreFailure('unsafe_archive_entry');
                }
            }

            $this->run([$tar, '-xf', $tarPath, '-C', $destination, ...self::FILES], $destination, 'package_extraction_failed');
            foreach (self::FILES as $file) {
                $path = $destination.'/'.$file;
                if (! is_file($path) || is_link($path)) {
                    throw new RestoreFailure('unsafe_archive_entry');
                }
            }
        } finally {
            @unlink($tarPath);
        }
    }

    private function run(array $command, string $cwd, string $failureCode): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (! is_resource($process)) {
            throw new RestoreFailure($failureCode);
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0 || ! is_string($stdout)) {
            throw new RestoreFailure($failureCode);
        }
        return $stdout;
    }
}
