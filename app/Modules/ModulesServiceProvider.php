<?php

namespace App\Modules;

use Illuminate\Support\ServiceProvider;

final class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (ModuleManifest::validate(ModuleManifest::providers()) as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
    }
}
