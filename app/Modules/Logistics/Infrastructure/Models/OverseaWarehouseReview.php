<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OverseaWarehouseReview extends Model
{
    protected $fillable = [
        'order_id', 'submitted_warehouse_id', 'reviewed_warehouse_id', 'status',
        'progress', 'note', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function submittedWarehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'submitted_warehouse_id'); }
    public function reviewedWarehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'reviewed_warehouse_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
