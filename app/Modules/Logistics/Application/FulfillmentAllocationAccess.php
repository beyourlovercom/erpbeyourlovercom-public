<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

final class FulfillmentAllocationAccess
{
    /** @var list<string> */
    private const ALLOWED_ROLES = [
        'super_admin',
        'role_preset_administrator',
        'role_preset_operations',
        'uat_owner_admin',
    ];

    public function allows(?Authenticatable $actor): bool
    {
        if (! $actor instanceof User) {
            return false;
        }

        if ($actor->hasPermission('logistics.operate')) {
            return true;
        }

        return $actor->hasPermission('orders.edit')
            && $actor->roles()->whereIn('key', self::ALLOWED_ROLES)->exists();
    }
}
