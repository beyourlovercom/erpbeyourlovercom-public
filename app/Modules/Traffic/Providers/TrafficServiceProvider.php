<?php

namespace App\Modules\Traffic\Providers;

use App\Modules\Traffic\Application\Contracts\GoogleAdsReadClient;
use App\Modules\Traffic\Application\Contracts\InquiryMetricSignalReader;
use App\Modules\Traffic\Application\Contracts\TrafficRepository;
use App\Modules\Traffic\Experience\Console\GoogleAdsTrafficSyncCommand;
use App\Modules\Traffic\Experience\Console\InquiryMetricsSyncCommand;
use App\Modules\Traffic\Infrastructure\GoogleAds\HttpGoogleAdsReadClient;
use App\Modules\Traffic\Infrastructure\Repositories\EloquentTrafficRepository;
use Illuminate\Support\ServiceProvider;

final class TrafficServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TrafficRepository::class, EloquentTrafficRepository::class);
        $this->app->bind(GoogleAdsReadClient::class, HttpGoogleAdsReadClient::class);
        $this->app->bind(InquiryMetricSignalReader::class, HttpGoogleAdsReadClient::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([GoogleAdsTrafficSyncCommand::class, InquiryMetricsSyncCommand::class]);
        }
    }
}
