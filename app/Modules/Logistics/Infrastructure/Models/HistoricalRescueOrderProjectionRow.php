<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalRescueOrderProjectionRow extends Model
{
    protected $table = 'logistics_historical_rescue_order_projection_rows';

    protected $fillable = [
        'projection_batch_id', 'source_row_number', 'source_order_no',
        'decision', 'decision_reason', 'order_id', 'projection_manifest',
    ];

    protected $casts = ['projection_manifest' => 'array'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(HistoricalRescueOrderProjectionBatch::class, 'projection_batch_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
