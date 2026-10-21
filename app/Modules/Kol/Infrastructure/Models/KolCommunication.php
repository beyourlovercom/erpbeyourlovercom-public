<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolCommunication extends Model
{
    protected $table = 'kol_communications';

    protected $fillable = [
        'kol_id', 'opportunity_id', 'created_by', 'channel', 'direction', 'summary',
        'contacted_at', 'next_action', 'next_follow_up_at',
    ];

    protected $casts = [
        'contacted_at' => 'datetime',
        'next_follow_up_at' => 'datetime',
    ];

    public function kol(): BelongsTo { return $this->belongsTo(Kol::class, 'kol_id'); }
    public function opportunity(): BelongsTo { return $this->belongsTo(KolOpportunity::class, 'opportunity_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
