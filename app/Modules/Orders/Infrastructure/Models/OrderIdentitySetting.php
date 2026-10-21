<?php

namespace App\Modules\Orders\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderIdentitySetting extends Model
{
    protected $table = 'order_identity_settings';
    protected $fillable = ['prefix_token', 'next_customer_number', 'updated_by'];
    protected $casts = ['next_customer_number' => 'integer', 'updated_by' => 'integer'];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'updated_by');
    }
}
