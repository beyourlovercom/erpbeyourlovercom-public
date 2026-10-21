<?php

namespace App\Modules\Backup\Application;

final readonly class GithubReleasePublicationData
{
    public function __construct(
        public string $repository,
        public string $releaseId,
        public string $tag,
        public string $releaseUrl,
        public string $assetId,
        public string $assetName,
        public string $assetUrl,
        public int $assetSize,
        public string $checksum,
        public string $publishedAt,
    ) {}

    /** @return array<string, string|int> */
    public function metadata(): array
    {
        return ['repository' => $this->repository, 'release_id' => $this->releaseId, 'release_tag' => $this->tag, 'release_url' => $this->releaseUrl, 'asset_id' => $this->assetId, 'asset_name' => $this->assetName, 'asset_url' => $this->assetUrl, 'asset_size' => $this->assetSize, 'checksum' => $this->checksum, 'published_at' => $this->publishedAt];
    }
}
