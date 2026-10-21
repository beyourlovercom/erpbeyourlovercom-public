<?php

namespace App\Modules\Kol\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolContentAttachment extends Model
{
    protected $table = 'kol_content_attachments';

    protected $fillable = ['content_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'evidence_type'];

    public function content(): BelongsTo { return $this->belongsTo(KolContent::class, 'content_id'); }
}
