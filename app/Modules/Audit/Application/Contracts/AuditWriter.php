<?php

namespace App\Modules\Audit\Application\Contracts;

interface AuditWriter
{
    /** @param array<string, mixed> $attributes */
    public function write(array $attributes): mixed;
}
