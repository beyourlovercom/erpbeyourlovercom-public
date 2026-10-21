<?php

namespace App\Modules\Backup\Application;

enum BackupState: string
{
    case Queued = 'queued';
    case Dumping = 'dumping';
    case Packaging = 'packaging';
    case Checksumming = 'checksumming';
    case Encrypting = 'encrypting';
    case Ready = 'ready';
    case Publishing = 'publishing';
    case Verified = 'verified';
    case Failed = 'failed';
    case Deleted = 'deleted';
}
