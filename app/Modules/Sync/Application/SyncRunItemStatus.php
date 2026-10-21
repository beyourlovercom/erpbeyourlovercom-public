<?php

namespace App\Modules\Sync\Application;

enum SyncRunItemStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Retrying = 'retrying';
    case Failed = 'failed';
    case Conflict = 'conflict';
    case Skipped = 'skipped';
}
