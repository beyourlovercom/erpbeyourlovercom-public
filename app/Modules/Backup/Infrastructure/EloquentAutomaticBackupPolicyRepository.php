<?php

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Application\BackupScheduleCadence;
use App\Modules\Backup\Application\BackupScheduleSettingData;
use App\Modules\Backup\Application\Contracts\AutomaticBackupPolicyRepository;
use App\Modules\Backup\Infrastructure\Models\BackupScheduleSetting;

final class EloquentAutomaticBackupPolicyRepository implements AutomaticBackupPolicyRepository
{
    public function current(): BackupScheduleSettingData
    {
        $setting = BackupScheduleSetting::query()->find(1);
        if ($setting === null) {
            return new BackupScheduleSettingData(false, BackupScheduleCadence::Daily);
        }

        return new BackupScheduleSettingData((bool) $setting->enabled, $setting->cadence);
    }

    public function save(bool $enabled, BackupScheduleCadence $cadence): BackupScheduleSettingData
    {
        $setting = BackupScheduleSetting::query()->updateOrCreate(
            ['id' => 1],
            ['enabled' => $enabled, 'cadence' => $cadence->value],
        );

        return new BackupScheduleSettingData((bool) $setting->enabled, $setting->cadence);
    }
}
