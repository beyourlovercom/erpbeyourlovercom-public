<?php

namespace App\Modules\Backup\Application;

use Carbon\CarbonImmutable;

final readonly class BackupRunData
{
    public function __construct(
        public int $id,
        public string $runUuid,
        public BackupType $backupType,
        public BackupState $state,
        public ?int $requestedBy = null,
        public ?CarbonImmutable $startedAt = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $metadata = [],
        public ?CarbonImmutable $completedAt = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}

    public function withState(BackupState $state): self
    {
        return new self($this->id, $this->runUuid, $this->backupType, $state, $this->requestedBy, $this->startedAt, $this->errorCode, $this->errorMessage, $this->metadata, $this->completedAt, $this->createdAt);
    }
}
