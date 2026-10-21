<?php

namespace App\Modules\Backup\Application\Contracts;

use App\Modules\Backup\Application\BackupScheduleCadence;
use App\Modules\Backup\Application\BackupScheduleSettingData;

interface AutomaticBackupPolicyRepository
{
    public function current(): BackupScheduleSettingData;

    public function save(bool $enabled, BackupScheduleCadence $cadence): BackupScheduleSettingData;
}
