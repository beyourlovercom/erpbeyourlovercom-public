<?php

namespace App\Modules\Backup\Infrastructure\Models;

use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\BackupType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BackupRun extends Model
{
    protected $fillable = ['run_uuid', 'backup_type', 'state', 'requested_by', 'error_code', 'error_message', 'metadata', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['backup_type' => BackupType::class, 'state' => BackupState::class, 'metadata' => 'array', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(BackupArtifact::class);
    }
}
