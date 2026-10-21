<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolCollaborationApprovalDecision extends Model
{
    protected $table = 'kol_collaboration_approval_decisions';

    protected $fillable = [
        'collaboration_id', 'outcome', 'source', 'actor_user_id', 'cooperation_type',
        'agreement_amount', 'currency', 'reason_text', 'decided_at',
    ];

    protected $casts = [
        'collaboration_id' => 'integer', 'actor_user_id' => 'integer',
        'agreement_amount' => 'decimal:2', 'decided_at' => 'datetime',
    ];

    public function collaboration(): BelongsTo { return $this->belongsTo(KolCollaboration::class, 'collaboration_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
