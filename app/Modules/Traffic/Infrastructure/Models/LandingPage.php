<?php

namespace App\Modules\Traffic\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class LandingPage extends Model
{
    protected $table = 'landing_pages';

    protected $fillable = [
        'business_project_id', 'business_project_site_id', 'page_key', 'name',
        'canonical_url', 'path', 'status',
    ];
}
