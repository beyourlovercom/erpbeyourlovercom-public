<?php

namespace App\Modules\Backup\Application;

final class ChecksumService
{
    /** @param array<string,string> $files */
    public function write(string $destination, array $files): void
    {
        ksort($files); $lines = [];
        foreach ($files as $name => $path) { $checksum = hash_file('sha256', $path); if ($checksum === false) throw new \RuntimeException('Unable to checksum backup component.'); $lines[] = $checksum.'  '.$name; }
        if (file_put_contents($destination, implode("\n", $lines)."\n") === false) throw new \RuntimeException('Unable to write backup checksums.');
    }
}
