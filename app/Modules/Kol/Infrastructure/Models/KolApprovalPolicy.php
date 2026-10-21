<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KolApprovalPolicy extends Model
{
    protected $table = 'kol_approval_policies';

    protected $fillable = [
        'version',
        'mode',
        'thresholds',
        'effective_at',
        'created_by',
    ];

    protected $casts = [
        'thresholds' => 'array',
        'effective_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(KolApprovalDecision::class, 'policy_id');
    }
}
