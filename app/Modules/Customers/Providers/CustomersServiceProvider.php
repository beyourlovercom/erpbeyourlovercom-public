<?php

namespace App\Modules\Customers\Providers;

use Illuminate\Support\ServiceProvider;

final class CustomersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
