<?php

namespace App\Modules\BusinessProjects\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessProject extends Model
{
    protected $table = 'business_projects';

    protected $fillable = [
        'project_key', 'name', 'status', 'archived_at',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(BusinessProjectSite::class, 'business_project_id')->orderBy('id');
    }
}
