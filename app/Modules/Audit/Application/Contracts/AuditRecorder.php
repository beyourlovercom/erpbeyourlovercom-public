<?php

namespace App\Modules\Audit\Application\Contracts;

use App\Modules\Audit\Application\AuditActor;

interface AuditRecorder
{
    public function record(
        AuditActor $actor,
        string $action,
        string $entityType,
        int|string|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): mixed;
}
