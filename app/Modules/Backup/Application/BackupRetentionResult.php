<?php

namespace App\Modules\Backup\Application;

final readonly class BackupRetentionResult
{
    public function __construct(
        public int $deleted,
        public int $blocked,
    ) {}
}
