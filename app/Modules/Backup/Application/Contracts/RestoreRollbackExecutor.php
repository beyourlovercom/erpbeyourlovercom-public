<?php

namespace App\Modules\Backup\Application\Contracts;

use App\Modules\Backup\Application\BackupRunData;
use App\Modules\Backup\Application\RestoreMode;

interface RestoreRollbackExecutor
{
    public function rollback(BackupRunData $snapshot, RestoreMode $mode): void;
}
