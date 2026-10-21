<?php

namespace App\Modules\BusinessProjects\Providers;

use App\Modules\BusinessProjects\Application\Contracts\BusinessProjectRepository;
use App\Modules\BusinessProjects\Infrastructure\Repositories\EloquentBusinessProjectRepository;
use Illuminate\Support\ServiceProvider;

final class BusinessProjectsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BusinessProjectRepository::class, EloquentBusinessProjectRepository::class);
    }

    public function boot(): void {}
}
