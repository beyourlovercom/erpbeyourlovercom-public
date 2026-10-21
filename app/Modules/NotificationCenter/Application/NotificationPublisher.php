<?php

namespace App\Modules\NotificationCenter\Application;

use App\Modules\Identity\Application\PermissionCatalog;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class NotificationPublisher
{
    public function publishToUser(User $user, array $payload): bool
    {
        $payload = $this->normalize($payload);
        $permission = $payload['required_permission'];
        if ($permission && ! $user->hasPermission($permission)) return false;
        $user->notify(new BusinessNotification($payload));
        return true;
    }

    public function publishToPermission(string $permission, array $payload, ?int $excludeUserId = null): int
    {
        if (! PermissionCatalog::isValid($permission)) throw new InvalidArgumentException('Unknown notification permission.');
        $count = 0;
        User::query()->where('is_active', true)->get()->each(function (User $user) use ($permission, $payload, $excludeUserId, &$count): void {
            if ($excludeUserId !== null && (int) $user->getKey() === $excludeUserId) return;
            if ($user->hasPermission($permission) && $this->publishToUser($user, [...$payload, 'required_permission'=>$permission])) $count++;
        });
        return $count;
    }

    private function normalize(array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $message = trim((string) ($payload['message'] ?? ''));
        if ($title === '') throw new InvalidArgumentException('Notification title is required.');
        $permission = $payload['required_permission'] ?? null;
        if ($permission !== null && ! PermissionCatalog::isValid((string) $permission)) throw new InvalidArgumentException('Unknown notification permission.');
        $url = $payload['action_url'] ?? null;
        if ($url !== null && (! Str::startsWith($url, '/') || Str::startsWith($url, '//'))) throw new InvalidArgumentException('Notification action_url must be an internal path.');
        $relatedLinks = collect($payload['related_links'] ?? [])->map(function ($link): array {
            if (! is_array($link)) throw new InvalidArgumentException('Notification related link must be an array.');
            $linkUrl = (string) ($link['url'] ?? '');
            if ($linkUrl === '' || ! Str::startsWith($linkUrl, '/') || Str::startsWith($linkUrl, '//')) throw new InvalidArgumentException('Notification related link url must be an internal path.');
            return [
                'label'=>trim((string) ($link['label'] ?? '相关业务')),
                'value'=>trim((string) ($link['value'] ?? '查看')),
                'url'=>$linkUrl,
            ];
        })->values()->all();
        return [
            'category'=>(string) ($payload['category'] ?? 'other'),
            'title'=>$title,
            'message'=>$message,
            'action_url'=>$url,
            'required_permission'=>$permission,
            'entity_type'=>$payload['entity_type'] ?? null,
            'entity_id'=>$payload['entity_id'] ?? null,
            'actor_name'=>$payload['actor_name'] ?? null,
            'related_links'=>$relatedLinks,
        ];
    }
}
