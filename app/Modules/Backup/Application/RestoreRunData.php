<?php

namespace App\Modules\Backup\Application;

final readonly class RestoreRunData
{
    public function __construct(
        public string $runUuid,
        public RestoreState $state,
        public RestoreMode $mode,
        public array $metadata = [],
        public ?string $errorCode = null,
    ) {}
}
