<?php

namespace App\Modules\Kol\Application\Approval;

final readonly class KolApprovalDecision
{
    public function __construct(
        public string $outcome,
        public string $reasonCode,
        public array $evidence = [],
    ) {}

    public function isApproved(): bool
    {
        return $this->outcome === 'approved';
    }

    public function requiresManagerApproval(): bool
    {
        return $this->outcome === 'pending';
    }
}
