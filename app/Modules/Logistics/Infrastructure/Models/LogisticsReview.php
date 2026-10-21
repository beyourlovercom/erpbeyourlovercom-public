<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsReview extends Model
{
    protected $fillable = ['order_id', 'status', 'requested_by', 'reviewed_by', 'requested_at', 'reviewed_at', 'reason', 'trigger', 'destination', 'decision_note'];
    protected $casts = ['requested_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function order(): BelongsTo { return $this->belongsTo('App\\Modules\\Orders\\Infrastructure\\Models\\Order'); }
    public function history(): HasMany { return $this->hasMany(LogisticsReviewHistory::class); }
}
