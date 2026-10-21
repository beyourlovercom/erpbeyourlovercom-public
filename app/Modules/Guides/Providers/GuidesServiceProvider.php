<?php

namespace App\Modules\Guides\Providers;

use Illuminate\Support\ServiceProvider;

final class GuidesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
