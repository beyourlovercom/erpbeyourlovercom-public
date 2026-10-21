<?php

namespace App\Modules\Backup\Application;

use App\Modules\Backup\Application\Contracts\AutomaticBackupPolicyRepository;

final readonly class BackupScheduleSettings
{
    public function __construct(private AutomaticBackupPolicyRepository $repository) {}

    public function current(): BackupScheduleSettingData
    {
        return $this->repository->current();
    }

    public function update(bool $enabled, BackupScheduleCadence $cadence): BackupScheduleSettingData
    {
        return $this->repository->save($enabled, $cadence);
    }
}
