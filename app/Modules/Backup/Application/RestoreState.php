<?php

namespace App\Modules\Backup\Application;

enum RestoreState: string
{
    case Uploaded = 'uploaded';
    case Downloading = 'downloading';
    case Verifying = 'verifying';
    case Decrypting = 'decrypting';
    case Compatible = 'compatible';
    case Snapshotting = 'snapshotting';
    case Restoring = 'restoring';
    case Migrating = 'migrating';
    case HealthChecking = 'health_checking';
    case Completed = 'completed';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
}
