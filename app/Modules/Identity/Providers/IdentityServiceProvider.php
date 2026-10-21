<?php

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Experience\Fortify\IdentityFortify;
use App\Modules\Identity\Experience\Fortify\RoleAwareLoginResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, RoleAwareLoginResponse::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/user-management.php');
        IdentityFortify::boot();
    }
}
