<?php

namespace App\Modules\Backup\Application;

final readonly class RestoreRequest
{
    public function __construct(
        public string $source,
        public string $expectedEncryptedSha256,
        public RestoreMode $mode,
        public ?int $requestedBy = null,
    ) {}
}
