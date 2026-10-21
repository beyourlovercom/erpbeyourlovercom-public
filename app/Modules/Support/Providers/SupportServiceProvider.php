<?php

namespace App\Modules\Support\Providers;

use Illuminate\Support\ServiceProvider;

final class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Modules\Support\Application\SupportTicketService::class);
        $this->app->singleton(\App\Modules\Support\Application\CustomerSupportProjection::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
