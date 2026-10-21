<?php

namespace App\Modules\Kol\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final class SavedViewService
{
    private const SAVED_VIEW = 'App\\Modules\\Kol\\Infrastructure\\Models\\SavedView';

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(string $module, int $userId, bool $canSeeShared): array
    {
        $svClass = self::SAVED_VIEW;

        return $svClass::forModule($module)
            ->visibleTo($userId, $canSeeShared)
            ->orderBy('is_pinned', 'desc')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Model $v): array => [
                'id' => $v->id,
                'name' => $v->name,
                'view_type' => $v->view_type,
                'visibility' => $v->visibility,
                'filters_json' => $v->filters_json,
                'sort_json' => $v->sort_json,
                'is_pinned' => $v->is_pinned,
                'is_default' => $v->is_default,
                'created_by' => $v->created_by,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $module, array $data, Authenticatable $actor): Model
    {
        $svClass = self::SAVED_VIEW;
        $view = $svClass::create([
            'module' => $module,
            'name' => $data['name'],
            'view_type' => 'custom',
            'created_by' => (int) $actor->getAuthIdentifier(),
            'visibility' => $data['visibility'] ?? 'private',
            'filters_json' => $data['filters_json'] ?? [],
            'sort_json' => $data['sort_json'] ?? null,
            'is_pinned' => $data['is_pinned'] ?? false,
            'is_default' => false,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            $module.'.saved_view.created',
            'saved_view',
            $view->id,
            null,
            ['name' => $view->name, 'visibility' => $view->visibility, 'module' => $module],
        );

        return $view;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Model $view, array $data, Authenticatable $actor): Model
    {
        $before = ['name' => $view->name, 'visibility' => $view->visibility, 'is_pinned' => $view->is_pinned];

        $view->update(array_filter([
            'name' => $data['name'] ?? null,
            'visibility' => $data['visibility'] ?? null,
            'filters_json' => $data['filters_json'] ?? null,
            'sort_json' => $data['sort_json'] ?? null,
            'is_pinned' => $data['is_pinned'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
        ], fn ($v) => $v !== null));

        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            $view->module.'.saved_view.updated',
            'saved_view',
            $view->id,
            $before,
            ['name' => $view->name, 'visibility' => $view->visibility, 'is_pinned' => $view->is_pinned],
        );

        return $view->fresh() ?? $view;
    }

    public function delete(Model $view, Authenticatable $actor): void
    {
        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            $view->module.'.saved_view.deleted',
            'saved_view',
            $view->id,
            ['name' => $view->name, 'module' => $view->module],
        );

        $view->delete();
    }
}
