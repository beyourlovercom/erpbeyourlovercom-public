<?php

namespace App\Modules\Sync\Providers;

use App\Modules\Sync\Application\SyncOutboxService;
use App\Modules\Sync\Application\Contracts\SyncOrchestrationRepository;
use App\Modules\Sync\Application\Contracts\SyncOutboxRepository;
use App\Modules\Sync\Application\Contracts\SyncRunRepository;
use App\Modules\Sync\Application\Contracts\SyncRunTracker;
use App\Modules\Sync\Application\SyncRunService;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use App\Modules\Sync\Infrastructure\EloquentSyncOrchestrationRepository;
use App\Modules\Sync\Infrastructure\EloquentSyncOutboxRepository;
use App\Modules\Sync\Infrastructure\EloquentSyncRunRepository;
use App\Modules\Sync\Infrastructure\WooCommerce\HttpWooCommerceTrackingGateway;
use Illuminate\Support\ServiceProvider;

final class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        class_exists('App\\Support\\Sync\\SyncOutboxWriteException');
        $this->app->bind(SyncOrchestrationRepository::class, EloquentSyncOrchestrationRepository::class);
        $this->app->bind(SyncOutboxRepository::class, EloquentSyncOutboxRepository::class);
        $this->app->bind(SyncRunRepository::class, EloquentSyncRunRepository::class);
        $this->app->bind(SyncRunTracker::class, SyncRunService::class);
        $this->app->bind(WooCommerceTrackingGateway::class, HttpWooCommerceTrackingGateway::class);
        $this->app->alias('App\\Support\\Sync\\SyncOutboxService', SyncOutboxService::class);
    }

    public function boot(): void
    {
    }
}
