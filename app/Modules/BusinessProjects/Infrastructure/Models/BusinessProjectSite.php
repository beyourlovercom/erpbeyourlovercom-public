<?php

namespace App\Modules\BusinessProjects\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProjectSite extends Model
{
    protected $table = 'business_project_sites';

    protected $fillable = [
        'business_project_id', 'site_key', 'domain_normalized', 'base_url', 'status', 'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(BusinessProject::class, 'business_project_id');
    }
}
