<?php

namespace App\Modules\Traffic\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrafficSourceAccount extends Model
{
    protected $table = 'traffic_source_accounts';

    protected $fillable = [
        'business_project_id', 'provider', 'external_account_id', 'name',
        'currency_code', 'account_timezone', 'status', 'last_synced_at',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    public function campaigns(): HasMany
    {
        return $this->hasMany(TrafficCampaign::class, 'traffic_source_account_id')->orderBy('id');
    }
}
