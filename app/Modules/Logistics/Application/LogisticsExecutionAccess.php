<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

final class LogisticsExecutionAccess
{
    /** @var list<string> */
    private const READ_ONLY_ELEVATED_ROLES = [
        'super_admin',
    ];

    public function allows(?Authenticatable $actor): bool
    {
        if (! $actor instanceof User || ! $actor->hasPermission('logistics.operate')) {
            return false;
        }

        return ! $actor->roles()
            ->whereIn('key', self::READ_ONLY_ELEVATED_ROLES)
            ->exists();
    }
}
