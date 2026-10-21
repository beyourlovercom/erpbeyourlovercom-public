<?php

namespace App\Modules\Logistics\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ShipmentOverseasHandlingBilling extends Model
{
    protected $table = 'shipment_overseas_handling_billing';
    protected $fillable = ['shipment_id','actual_cost','currency','billing_document_number','provider_reference','data_source','billed_at','raw_evidence'];
    protected $casts = ['actual_cost'=>'decimal:4','billed_at'=>'datetime','raw_evidence'=>'array'];
    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
}
