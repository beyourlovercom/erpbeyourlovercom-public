<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsReviewHistory extends Model
{
    public $timestamps = false;
    protected $fillable = ['logistics_review_id', 'order_id', 'event', 'status', 'actor_id', 'destination', 'note', 'snapshot', 'created_at'];
    protected $casts = ['snapshot' => 'array', 'created_at' => 'datetime'];
    public function review(): BelongsTo { return $this->belongsTo(LogisticsReview::class, 'logistics_review_id'); }
}
