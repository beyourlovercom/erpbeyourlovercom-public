<?php

namespace App\Modules\Backup\Infrastructure\Models;

use App\Modules\Backup\Application\BackupScheduleCadence;
use Illuminate\Database\Eloquent\Model;

final class BackupScheduleSetting extends Model
{
    protected $fillable = ['id', 'enabled', 'cadence'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'cadence' => BackupScheduleCadence::class,
        ];
    }
}
