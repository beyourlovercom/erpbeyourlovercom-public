<?php

namespace App\Modules\NotificationCenter\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Notifications\DatabaseNotification;

final class NotificationCenterProjector
{
    public function summary(User $user, int $limit = 5): array
    {
        $visible = $user->notifications()->latest()->limit(max(25, $limit * 5))->get()->filter(fn (DatabaseNotification $n) => $this->allowed($user, $n));
        $unreadCount = $user->unreadNotifications()->get()->filter(fn (DatabaseNotification $n) => $this->allowed($user, $n))->count();
        return ['unread_count'=>$unreadCount, 'items'=>$visible->take($limit)->map(fn (DatabaseNotification $n) => $this->item($n))->values()->all()];
    }

    public function page(User $user, int $perPage = 30): array
    {
        $visible = $user->notifications()->latest()->get()->filter(fn (DatabaseNotification $n) => $this->allowed($user, $n));
        return ['items'=>$visible->take($perPage)->map(fn (DatabaseNotification $n) => $this->item($n))->values()->all(), 'unread_count'=>$visible->whereNull('read_at')->count()];
    }

    public function item(DatabaseNotification $notification): array
    {
        $data = $notification->data;
        return [
            'id'=>$notification->id,
            'category'=>$data['category'] ?? 'other',
            'title'=>$data['title'] ?? '通知',
            'message'=>$data['message'] ?? '',
            'action_url'=>$data['action_url'] ?? null,
            'entity_type'=>$data['entity_type'] ?? null,
            'entity_id'=>$data['entity_id'] ?? null,
            'actor_name'=>$data['actor_name'] ?? null,
            'related_links'=>is_array($data['related_links'] ?? null) ? $data['related_links'] : [],
            'read'=>$notification->read_at !== null,
            'created_at'=>$notification->created_at?->toIso8601String(),
        ];
    }

    public function allowed(User $user, DatabaseNotification $notification): bool
    {
        $permission = $notification->data['required_permission'] ?? null;
        return ! $permission || $user->hasPermission((string) $permission);
    }
}
