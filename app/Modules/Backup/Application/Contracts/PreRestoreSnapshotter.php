<?php

namespace App\Modules\Backup\Application\Contracts;

use App\Modules\Backup\Application\BackupRunData;

interface PreRestoreSnapshotter
{
    public function createAndVerify(): BackupRunData;
}
