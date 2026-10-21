<?php

namespace App\Modules\Kol\Application;

use Illuminate\Contracts\Auth\Authenticatable;

final class KolScope
{
    public static function isAllScope(Authenticatable $user): bool
    {
        return $user->can('kol.views.manage');
    }

    public static function effectiveOwnerId(Authenticatable $user): ?int
    {
        if (self::isAllScope($user)) {
            return null;
        }

        return (int) $user->getAuthIdentifier();
    }

    public static function canManageViews(Authenticatable $user): bool
    {
        return $user->can('kol.views.manage');
    }

    public static function canOperate(Authenticatable $user): bool
    {
        return $user->can('kol.operate');
    }

    public static function canManageApprovals(Authenticatable $user): bool
    {
        return $user->can('kol.approvals.manage');
    }
}
