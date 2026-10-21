<?php

namespace App\Modules\Backup\Application;

use RuntimeException;

final class RestoreFailure extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct('Restore operation failed.');
    }
}
