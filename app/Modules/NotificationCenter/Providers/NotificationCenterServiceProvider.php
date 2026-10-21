<?php

namespace App\Modules\NotificationCenter\Providers;

use Illuminate\Support\ServiceProvider;

final class NotificationCenterServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
