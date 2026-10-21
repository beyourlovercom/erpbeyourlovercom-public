<?php

namespace App\Modules\Guides\Infrastructure\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ReleaseNote extends Model
{
    protected $fillable = ['version', 'title', 'summary', 'markdown_body', 'status', 'released_on', 'published_at', 'created_by', 'updated_by'];

    protected $casts = ['released_on' => 'date', 'published_at' => 'datetime'];

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}
