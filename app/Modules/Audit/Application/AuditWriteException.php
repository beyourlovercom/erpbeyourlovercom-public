<?php

namespace App\Modules\Audit\Application;

use RuntimeException;

final class AuditWriteException extends RuntimeException
{
    public static function failed(): self
    {
        return new self('A required audit record could not be written.');
    }
}
