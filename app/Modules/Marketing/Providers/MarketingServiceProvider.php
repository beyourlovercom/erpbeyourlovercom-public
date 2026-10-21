<?php

namespace App\Modules\Marketing\Providers;

use App\Modules\Marketing\Application\Contracts\WordPressSubscriberGateway;
use App\Modules\Marketing\Application\Contracts\MarketingSubscriberSyncWriter;
use App\Modules\Marketing\Infrastructure\AndyCore\HttpWordPressSubscriberGateway;
use App\Modules\Marketing\Infrastructure\EloquentMarketingSubscriberSyncWriter;
use Illuminate\Support\ServiceProvider;

final class MarketingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WordPressSubscriberGateway::class, HttpWordPressSubscriberGateway::class);
        $this->app->bind(MarketingSubscriberSyncWriter::class, EloquentMarketingSubscriberSyncWriter::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
