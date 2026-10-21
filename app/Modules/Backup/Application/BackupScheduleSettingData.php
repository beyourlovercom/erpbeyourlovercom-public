<?php

namespace App\Modules\Backup\Application;

final readonly class BackupScheduleSettingData
{
    public function __construct(
        public bool $enabled,
        public BackupScheduleCadence $cadence,
    ) {}
}
