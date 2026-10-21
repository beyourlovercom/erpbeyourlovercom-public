<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PackagingMeasurementMedia extends Model
{
    protected $table = 'packaging_measurement_media';
    protected $fillable = ['record_id','kind','disk','path','original_name','mime_type','size_bytes','sort_order'];
    protected $casts = ['size_bytes'=>'integer','sort_order'=>'integer'];
    public function record(): BelongsTo { return $this->belongsTo(PackagingMeasurementRecord::class, 'record_id'); }
}
