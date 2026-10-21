<?php

namespace App\Modules\Backup\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BackupArtifact extends Model
{
    protected $fillable = ['backup_run_id', 'kind', 'path', 'size', 'checksum', 'package_checksum', 'encrypted', 'key_id'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'encrypted' => 'boolean'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class, 'backup_run_id');
    }
}
