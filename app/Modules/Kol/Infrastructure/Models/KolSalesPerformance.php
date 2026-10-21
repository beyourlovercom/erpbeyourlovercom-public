<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolSalesPerformance extends Model
{
    protected $table = 'kol_sales_performance';

    protected $fillable = [
        'kol_id',
        'order_count',
        'recorded_at',
        'source',
        'note',
        'created_by',
    ];

    protected $casts = [
        'order_count' => 'integer',
        'recorded_at' => 'datetime',
    ];

    public function kol(): BelongsTo
    {
        return $this->belongsTo(Kol::class, 'kol_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
