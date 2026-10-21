<?php

namespace App\Modules\Backup\Application;

enum RestoreMode: string
{
    case DatabaseOnly = 'database';
    case Full = 'full';
}
