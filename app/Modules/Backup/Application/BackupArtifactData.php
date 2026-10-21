<?php

namespace App\Modules\Backup\Application;

final readonly class BackupArtifactData
{
    public function __construct(
        public int $id,
        public int $runId,
        public string $path,
        public string $kind = 'encrypted',
        public int $size = 0,
        public string $checksum = '',
        public bool $encrypted = false,
        public ?string $keyId = null,
        public ?string $packageChecksum = null,
    ) {}
}
