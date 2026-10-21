<?php

namespace App\Modules\Audit\Providers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Audit\Application\Contracts\AuditWriter;
use App\Modules\Audit\Infrastructure\EloquentAuditWriter;
use Illuminate\Support\ServiceProvider;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        class_exists('App\\Support\\Audit\\AuditWriteException');
        $this->app->bind('App\\Support\\Audit\\AuditWriter', EloquentAuditWriter::class);
        $this->app->bind(AuditWriter::class, fn ($container) => $container->make('App\\Support\\Audit\\AuditWriter'));
        $this->app->bind(AuditRecorder::class, 'App\\Support\\Audit\\AuditService');
        $this->app->alias(AuditRecorder::class, 'App\\Modules\\Identity\\Application\\Contracts\\AuditRecorder');
        $this->app->bind('App\\Support\\Audit\\AuditService', AuditService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
