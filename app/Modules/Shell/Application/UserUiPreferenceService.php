<?php
namespace App\Modules\Shell\Application;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shell\Infrastructure\Models\UserUiPreference;
final class UserUiPreferenceService
{
    public function get(User $user, string $namespace): ?array
    {
        return UserUiPreference::query()->where('user_id', $user->id)->where('namespace', $namespace)->value('value');
    }
    public function put(User $user, string $namespace, array $value): array
    {
        $preference = UserUiPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'namespace' => $namespace],
            ['value' => $value],
        );
        return $preference->value ?? [];
    }
}
