<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolApprovalDecision extends Model
{
    protected $table = 'kol_approval_decisions';

    protected $fillable = [
        'kol_id',
        'policy_id',
        'outcome',
        'source',
        'actor_user_id',
        'policy_mode',
        'threshold_snapshot',
        'reason_code',
        'reason_text',
        'decided_at',
    ];

    protected $casts = [
        'threshold_snapshot' => 'array',
        'decided_at' => 'datetime',
    ];

    public function kol(): BelongsTo
    {
        return $this->belongsTo(Kol::class, 'kol_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(KolApprovalPolicy::class, 'policy_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
