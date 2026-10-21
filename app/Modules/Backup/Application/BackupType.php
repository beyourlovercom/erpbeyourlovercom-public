<?php

namespace App\Modules\Backup\Application;

enum BackupType: string
{
    case Database = 'database';
    case Full = 'full';
    case PreRestore = 'pre_restore';
}
