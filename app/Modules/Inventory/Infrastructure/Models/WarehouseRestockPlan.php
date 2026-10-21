<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WarehouseRestockPlan extends Model
{
    protected $fillable = [
        'warehouse_id', 'reference', 'status', 'source_checksum', 'snapshot_checksum',
        'rule_snapshot', 'summary_snapshot', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'warehouse_id' => 'integer',
            'rule_snapshot' => 'array',
            'summary_snapshot' => 'array',
            'confirmed_by' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function confirmer(): BelongsTo { return $this->belongsTo(User::class, 'confirmed_by'); }
    public function items(): HasMany { return $this->hasMany(WarehouseRestockPlanItem::class, 'restock_plan_id')->orderBy('sort_order')->orderBy('id'); }
}
