<?php

namespace App\Modules\Sync\Application;

enum SyncRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
}
