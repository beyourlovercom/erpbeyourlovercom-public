<?php

namespace App\Modules\Backup\Application;

enum BackupScheduleCadence: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => '每天',
            self::Weekly => '每周',
            self::Monthly => '每月',
        };
    }
}
