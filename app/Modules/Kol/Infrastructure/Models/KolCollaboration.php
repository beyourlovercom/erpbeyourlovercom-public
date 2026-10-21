<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KolCollaboration extends Model
{
    protected $table = 'kol_collaborations';

    protected $fillable = [
        'kol_id', 'opportunity_id', 'owner_id', 'order_id', 'title', 'post_url', 'cooperation_type', 'status',
        'approval_status', 'approval_updated_at', 'approved_by_user_id', 'approved_at',
        'agreement_amount', 'currency', 'agreed_at', 'planned_publish_at',
        'deliverables', 'fulfillment_required', 'notes',
    ];

    protected $casts = [
        'agreement_amount' => 'decimal:2',
        'approval_updated_at' => 'datetime',
        'approved_by_user_id' => 'integer',
        'approved_at' => 'datetime',
        'agreed_at' => 'date',
        'planned_publish_at' => 'date',
        'fulfillment_required' => 'boolean',
    ];

    public function kol(): BelongsTo { return $this->belongsTo(Kol::class, 'kol_id'); }
    public function opportunity(): BelongsTo { return $this->belongsTo(KolOpportunity::class, 'opportunity_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by_user_id'); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class, 'order_id'); }
    public function products(): HasMany { return $this->hasMany(KolCollaborationProduct::class, 'collaboration_id')->orderBy('id'); }
    public function approvalDecisions(): HasMany { return $this->hasMany(KolCollaborationApprovalDecision::class, 'collaboration_id')->orderBy('id'); }
    public function contents(): HasMany { return $this->hasMany(KolContent::class, 'collaboration_id')->orderByDesc('published_at')->orderByDesc('id'); }
}
