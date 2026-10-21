<?php

namespace App\Modules\Backup\Application;

final readonly class GithubReleasePublishData
{
    public function __construct(
        public string $repository,
        public string $tag,
        public string $runUuid,
        public string $backupType,
        public string $assetName,
        public string $assetPath,
        public int $assetSize,
        public string $checksum,
        public int $formatVersion,
    ) {}
}
