<?php

namespace App\Modules\Shell\Providers;

use App\Modules\Shell\Application\ErpShellNavigation;
use App\Modules\Shell\Application\LegacyErpNavigationPaths;
use Illuminate\Support\ServiceProvider;

final class ShellServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('App\\Support\\ErpShellNavigation', ErpShellNavigation::class);
        $this->app->bind('App\\Support\\LegacyErpNavigationPaths', LegacyErpNavigationPaths::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
