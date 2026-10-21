<?php

namespace App\Modules\Procurement\Infrastructure\Models;

use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementOrder extends Model
{
    protected $fillable = ['procurement_number', 'source_order_id', 'status', 'carrier', 'tracking_number', 'completion_reason', 'completed_at', 'created_by', 'updated_by', 'status_updated_at'];

    protected function casts(): array
    {
        return ['status_updated_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function sourceOrder(): BelongsTo { return $this->belongsTo(Order::class, 'source_order_id'); }
    public function creator(): BelongsTo { return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'created_by'); }
    public function updater(): BelongsTo { return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'updated_by'); }
    public function items(): HasMany { return $this->hasMany(ProcurementOrderItem::class); }
}
