<?php

namespace App\Modules\Kol\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KolContent extends Model
{
    protected $table = 'kol_contents';

    protected $fillable = [
        'kol_id', 'collaboration_id', 'platform', 'content_type', 'publication_status',
        'post_url', 'planned_publish_at', 'published_at',
    ];

    protected $casts = [
        'planned_publish_at' => 'date',
        'published_at' => 'date',
    ];

    public function kol(): BelongsTo { return $this->belongsTo(Kol::class, 'kol_id'); }
    public function collaboration(): BelongsTo { return $this->belongsTo(KolCollaboration::class, 'collaboration_id'); }
    public function attachments(): HasMany { return $this->hasMany(KolContentAttachment::class, 'content_id')->orderBy('id'); }

    public function evidenceClassification(): string
    {
        $hasUrl = trim((string) $this->post_url) !== '';
        $hasScreenshot = $this->relationLoaded('attachments') ? $this->attachments->isNotEmpty() : $this->attachments()->exists();
        return match (true) {
            $hasUrl && $hasScreenshot => 'url_and_screenshot',
            $hasUrl => 'url',
            $hasScreenshot => 'screenshot',
            default => 'none',
        };
    }
}
