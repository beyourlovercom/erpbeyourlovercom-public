<?php

namespace App\Modules\Guides\Infrastructure\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class GuideArticle extends Model
{
    protected $fillable = ['slug', 'content_type', 'title', 'summary', 'markdown_body', 'video_url', 'status', 'published_at', 'created_by', 'updated_by'];

    protected $casts = ['published_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}
