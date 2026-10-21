<?php

namespace App\Modules\Guides\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

final class KnowledgeBaseService
{
    private const GUIDE = 'App\\Modules\\Guides\\Infrastructure\\Models\\GuideArticle';
    private const RELEASE = 'App\\Modules\\Guides\\Infrastructure\\Models\\ReleaseNote';

    public function __construct(private readonly AuditRecorder $audit) {}

    public function createGuide(array $data, Authenticatable $actor): Model
    {
        $guideClass = self::GUIDE;
        $guide = $guideClass::create([...$data, 'slug' => $this->uniqueSlug($data['slug'] ?? $data['title']), 'status' => 'draft', 'published_at' => null, 'created_by' => $actor->getAuthIdentifier(), 'updated_by' => $actor->getAuthIdentifier()]);
        $this->record($actor, 'guide.created', $guide, null, $guide->only(['slug', 'content_type', 'title', 'status']));
        return $guide;
    }

    public function updateGuide(Model $guide, array $data, Authenticatable $actor): Model
    {
        $before = $guide->only(['slug', 'content_type', 'title', 'summary', 'markdown_body', 'video_url', 'status']);
        if (array_key_exists('slug', $data) || array_key_exists('title', $data)) {
            $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['title'], $guide->id);
        }
        $guide->fill([...$data, 'updated_by' => $actor->getAuthIdentifier()])->save();
        $this->record($actor, 'guide.updated', $guide, $before, $guide->only(array_keys($before)));
        return $guide->fresh();
    }

    public function setGuidePublished(Model $guide, bool $published, Authenticatable $actor): Model
    {
        $before = $guide->only(['status', 'published_at']);
        $guide->forceFill(['status' => $published ? 'published' : 'draft', 'published_at' => $published ? now() : null, 'updated_by' => $actor->getAuthIdentifier()])->save();
        $this->record($actor, $published ? 'guide.published' : 'guide.unpublished', $guide, $before, $guide->only(['status', 'published_at']));
        return $guide->fresh();
    }

    public function createRelease(array $data, Authenticatable $actor): Model
    {
        $releaseClass = self::RELEASE;
        $note = $releaseClass::create([...$data, 'status' => 'draft', 'published_at' => null, 'created_by' => $actor->getAuthIdentifier(), 'updated_by' => $actor->getAuthIdentifier()]);
        $this->record($actor, 'release_note.created', $note, null, $note->only(['version', 'title', 'status', 'released_on']));
        return $note;
    }

    public function updateRelease(Model $note, array $data, Authenticatable $actor): Model
    {
        $before = $note->only(['version', 'title', 'summary', 'markdown_body', 'released_on', 'status']);
        $note->fill([...$data, 'updated_by' => $actor->getAuthIdentifier()])->save();
        $this->record($actor, 'release_note.updated', $note, $before, $note->only(array_keys($before)));
        return $note->fresh();
    }

    public function setReleasePublished(Model $note, bool $published, Authenticatable $actor): Model
    {
        $before = $note->only(['status', 'published_at']);
        $note->forceFill(['status' => $published ? 'published' : 'draft', 'published_at' => $published ? now() : null, 'updated_by' => $actor->getAuthIdentifier()])->save();
        $this->record($actor, $published ? 'release_note.published' : 'release_note.unpublished', $note, $before, $note->only(['status', 'published_at']));
        return $note->fresh();
    }

    private function uniqueSlug(string $raw, ?int $ignoreId = null): string
    {
        $base = Str::slug($raw) ?: 'guide';
        $slug = $base;
        $suffix = 2;
        $guideClass = self::GUIDE;
        while ($guideClass::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$suffix++;
        }
        return $slug;
    }

    private function record(Authenticatable $actor, string $action, object $entity, ?array $before, ?array $after): void
    {
        $this->audit->record(AuditActor::user($actor, AuditSource::Web), $action, $entity::class, $entity->getKey(), before: $before, after: $after, reason: 'knowledge_base_content_change');
    }
}
