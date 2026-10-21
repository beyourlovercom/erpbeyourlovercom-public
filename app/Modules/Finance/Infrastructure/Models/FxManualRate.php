<?php

namespace App\Modules\Finance\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class FxManualRate extends Model
{
    protected $table = 'finance_fx_manual_rates';
    protected $primaryKey = 'currency';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['currency', 'manual_rate_to_cny', 'use_auto', 'updated_by'];

    protected function casts(): array
    {
        return [
            'manual_rate_to_cny' => 'decimal:8',
            'use_auto' => 'boolean',
        ];
    }
}
