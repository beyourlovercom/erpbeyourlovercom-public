<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

final class LogisticsReviewAccess
{
    public function allows(?Authenticatable $actor): bool
    {
        return $actor instanceof User && $actor->hasPermission('logistics.operate');
    }
}
