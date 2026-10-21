<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolOpportunity extends Model
{
    protected $table = 'kol_opportunities';

    protected $fillable = [
        'kol_id', 'title', 'cooperation_type', 'target_products', 'stage',
        'owner_id', 'estimated_cost', 'currency', 'expected_publish_at',
        'next_follow_up_at', 'next_action', 'status', 'notes',
    ];

    protected $casts = [
        'target_products' => 'array',
        'estimated_cost' => 'decimal:2',
        'expected_publish_at' => 'date',
        'next_follow_up_at' => 'datetime',
    ];

    public function kol(): BelongsTo
    {
        return $this->belongsTo(Kol::class, 'kol_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
