<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolOwnerCandidateDecision extends Model
{
    protected $fillable = ['reconciliation_batch_id', 'cluster_key', 'mapped_user_id', 'source_owner_values', 'reason', 'decided_by'];
    protected $casts = ['source_owner_values' => 'array'];

    public function mappedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mapped_user_id');
    }
}
