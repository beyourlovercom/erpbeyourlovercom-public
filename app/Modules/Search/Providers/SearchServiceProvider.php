<?php

namespace App\Modules\Search\Providers;

use App\Modules\Search\Application\GlobalSearchService;
use App\Modules\Search\Infrastructure\InventorySearchProvider;
use App\Modules\Search\Infrastructure\OrderSearchProvider;
use App\Modules\Search\Infrastructure\ProductSearchProvider;
use Illuminate\Support\ServiceProvider;

final class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GlobalSearchService::class, fn ($app) => new GlobalSearchService([
            $app->make(ProductSearchProvider::class),
            $app->make(OrderSearchProvider::class),
            $app->make(InventorySearchProvider::class),
        ]));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
