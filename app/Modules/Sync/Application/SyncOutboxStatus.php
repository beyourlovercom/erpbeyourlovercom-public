<?php

namespace App\Modules\Sync\Application;

enum SyncOutboxStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
