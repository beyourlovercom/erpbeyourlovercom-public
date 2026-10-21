<?php

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Application\Contracts\StorageRestorer;
use RuntimeException;

final class ZstdTarStorageRestorer implements StorageRestorer
{
    /** @var list<string> */
    private const ROOTS = ['storage/app/private', 'storage/app/public'];

    public function restore(string $archive): void
    {
        $realArchive = realpath($archive);
        if ($realArchive === false || ! is_file($realArchive) || is_link($archive) || ! str_ends_with($realArchive, '.tar.zst')) {
            throw new RuntimeException('Restore storage archive is unavailable.');
        }
        $roots = array_values((array) config('backup.storage.included_roots', []));
        if ($roots !== self::ROOTS) {
            throw new RuntimeException('Restore storage roots must match the frozen persistent storage scope.');
        }

        $workspace = $this->workspaceRoot();
        $staging = $workspace.'/restore-storage-'.bin2hex(random_bytes(8));
        $extract = $staging.'/extract';
        if (! mkdir($extract, 0700, true) && ! is_dir($extract)) throw new RuntimeException('Unable to create restore storage staging area.');
        $tarPath = $staging.'/storage.tar';

        try {
            $this->run([(string) config('backup.tooling.zstd', 'zstd'), '-q', '-d', '-f', $realArchive, '-o', $tarPath], $staging);
            $names = $this->archiveNames($tarPath, $staging);
            $this->assertArchiveEntries($names, $this->run([(string) config('backup.tooling.tar', 'tar'), '-tvf', $tarPath], $staging));
            $this->run([(string) config('backup.tooling.tar', 'tar'), '-xf', $tarPath, '-C', $extract], $staging);
            foreach (self::ROOTS as $root) {
                $incoming = $extract.'/'.$root;
                if (! is_dir($incoming) || is_link($incoming)) throw new RuntimeException('Restore storage archive is incomplete.');
                $this->assertTreeSafe($incoming);
            }
            $this->replaceRoots($extract, $staging);
        } finally {
            $this->removeTree($staging);
        }
    }

    /** @return list<string> */
    private function archiveNames(string $tar, string $cwd): array
    {
        $stdout = $this->run([(string) config('backup.tooling.tar', 'tar'), '-tf', $tar], $cwd);
        $names = array_values(array_filter(preg_split('/\r?\n/', trim($stdout)) ?: [], static fn (string $name): bool => $name !== ''));
        if ($names === [] || count($names) !== count(array_unique($names))) throw new RuntimeException('Restore storage archive entries are invalid.');
        return $names;
    }

    /** @param list<string> $names */
    private function assertArchiveEntries(array $names, string $verbose): void
    {
        foreach ($names as $name) {
            if (str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                throw new RuntimeException('Restore storage archive contains unsafe paths.');
            }
            $allowed = false;
            foreach (self::ROOTS as $root) {
                if ($name === $root || $name === $root.'/' || str_starts_with($name, $root.'/')) { $allowed = true; break; }
            }
            if (! $allowed) throw new RuntimeException('Restore storage archive escaped the frozen roots.');
        }
        $lines = array_values(array_filter(preg_split('/\r?\n/', trim($verbose)) ?: [], static fn (string $line): bool => trim($line) !== ''));
        if (count($lines) !== count($names)) throw new RuntimeException('Restore storage archive listing is inconsistent.');
        foreach ($lines as $line) {
            $type = ltrim($line)[0] ?? '';
            if (! in_array($type, ['-', 'd'], true) || str_contains($line, ' -> ') || str_contains($line, ' link to ')) {
                throw new RuntimeException('Restore storage archive contains links or unsupported entries.');
            }
        }
    }

    private function replaceRoots(string $extract, string $staging): void
    {
        $storageRoot = base_path('storage');
        $storageApp = base_path('storage/app');
        if (is_link($storageRoot) || is_link($storageApp) || realpath($storageRoot) === false || realpath($storageApp) === false
            || ! str_starts_with(realpath($storageApp), rtrim(realpath($storageRoot), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Restore storage target parent is unsafe.');
        }
        $swaps = [];
        try {
            foreach (self::ROOTS as $index => $root) {
                $target = base_path($root);
                $incoming = $extract.'/'.$root;
                $parent = dirname($target);
                if (! is_dir($parent) || is_link($parent)) throw new RuntimeException('Restore storage target parent is unsafe.');
                $original = $staging.'/original-'.$index;
                $hadOriginal = file_exists($target) || is_link($target);
                if ($hadOriginal) {
                    if (! is_dir($target) || is_link($target) || ! rename($target, $original)) throw new RuntimeException('Unable to stage current storage root.');
                }
                $swaps[] = ['target' => $target, 'original' => $original, 'had_original' => $hadOriginal, 'installed' => false];
                $swapIndex = array_key_last($swaps);
                if (! rename($incoming, $target)) throw new RuntimeException('Unable to install restored storage root.');
                $swaps[$swapIndex]['installed'] = true;
            }
            foreach ($swaps as $swap) if ($swap['had_original']) $this->removeTree($swap['original']);
        } catch (\Throwable $e) {
            foreach (array_reverse($swaps) as $swap) {
                if ($swap['installed'] && (file_exists($swap['target']) || is_link($swap['target']))) $this->removeTree($swap['target']);
                if ($swap['had_original'] && (file_exists($swap['original']) || is_link($swap['original']))) @rename($swap['original'], $swap['target']);
            }
            throw $e;
        }
    }

    private function assertTreeSafe(string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) if ($entry->isLink()) throw new RuntimeException('Restore storage archive extracted an unsafe link.');
    }

    private function workspaceRoot(): string
    {
        $configured = (string) config('backup.storage.workspace', 'storage/app/backups');
        $path = str_starts_with($configured, DIRECTORY_SEPARATOR) ? $configured : base_path($configured);
        if (is_link($path)) throw new RuntimeException('Restore workspace is unsafe.');
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) throw new RuntimeException('Restore workspace is unavailable.');
        $real = realpath($path);
        if ($real === false) throw new RuntimeException('Restore workspace is unavailable.');
        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    /** @param list<string> $command */
    private function run(array $command, string $cwd): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (! is_resource($process)) throw new RuntimeException('Unable to start restore storage tool.');
        $stdout = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || ! is_string($stdout)) throw new RuntimeException('Restore storage tool failed.');
        return $stdout;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (! is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) continue;
            $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
        }
        @rmdir($path);
    }
}
