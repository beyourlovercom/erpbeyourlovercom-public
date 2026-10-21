<?php

namespace App\Modules\Sync\Application;

enum ManualSyncStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Completed = 'completed';
    case NeedsConfirmation = 'needs_confirmation';
    case Returned = 'returned';
    case Failed = 'failed';
}
