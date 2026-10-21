<?php

namespace App\Modules\Identity\Experience\Fortify;

use App\Modules\Identity\Application\AuthorizationKeys;
use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\AuditWriteException;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Identity\Experience\Middleware\ApplyLocalAdminBypass;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;

final class IdentityFortify
{
    private const DEFAULT_LOGIN_DOMAIN = 'beyourlover.com';

    public static function boot(): void
    {
        Fortify::loginView(fn () => inertia('Auth/Login'));
        Fortify::authenticateThrough(fn () => [
            CanonicalizeUsername::class,
            AttemptPersistentAuthentication::class,
            PrepareAuthenticatedSession::class,
        ]);
        Fortify::authenticateUsing(function ($request) {
            $identifier = Str::lower(trim((string) $request->email));
            $user = self::resolveLoginUser($identifier);

            return $user && $user->is_active && Hash::check($request->password, $user->password) ? $user : null;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        self::registerAuthorization();
        self::registerAuditListeners();
    }

    private static function resolveLoginUser(string $identifier): ?User
    {
        if ($identifier === '') {
            return null;
        }

        if (app()->environment(['local', 'testing']) && $identifier === 'admin') {
            return User::where('email', 'admin@local.test')->first();
        }

        if (str_contains($identifier, '@')) {
            return User::where('email', $identifier)->first();
        }

        $companyEmail = $identifier.'@'.self::DEFAULT_LOGIN_DOMAIN;
        $companyUser = User::where('email', $companyEmail)->first();
        if ($companyUser) {
            return $companyUser;
        }

        return User::whereRaw('LOWER(name) = ?', [$identifier])->first();
    }

    private static function registerAuthorization(): void
    {
        Gate::before(function (User $user): ?bool {
            if ($user->hasPermission(AuthorizationKeys::SuperAdmin->value)) {
                return true;
            }

            if (! app()->bound('request')) {
                return null;
            }

            $request = app('request');
            if (! $request instanceof Request) {
                return null;
            }

            return app()->environment('local')
                && config('erp_local.admin_bypass.enabled') === true
                && $request->attributes->get(ApplyLocalAdminBypass::REQUEST_MARKER) === true
                && $request->user()?->is($user)
                    ? true
                    : null;
        });
        foreach (AuthorizationKeys::cases() as $key) {
            if (! str_contains($key->value, '.')) {
                continue;
            }
            Gate::define($key->value, fn (User $user) => $user->hasPermission($key->value));
        }
    }

    private static function registerAuditListeners(): void
    {
        \Event::listen(Login::class, function (Login $event): void {
            /** @var User $user */
            $user = $event->user;
            try {
                app(AuditRecorder::class)->record(
                    AuditActor::user($user, AuditSource::Fortify),
                    'auth.login',
                    \App\Models\User::class,
                    $user->getAuthIdentifier(),
                    after: ['state' => 'authenticated'],
                    reason: 'credentials_accepted',
                );
            } catch (AuditWriteException $exception) {
                request()->session()->invalidate();
                auth()->guard()->forgetUser();

                throw $exception;
            }
        });

        \Event::listen(Logout::class, function (Logout $event): void {
            /** @var User|null $user */
            $user = $event->user;
            $actor = $user
                ? AuditActor::user($user, AuditSource::Fortify)
                : AuditActor::system(AuditSource::Fortify);

            try {
                app(AuditRecorder::class)->record(
                    $actor,
                    'auth.logout',
                    $user ? \App\Models\User::class : 'session',
                    $user?->getAuthIdentifier(),
                    before: ['state' => 'authenticated'],
                    after: ['state' => 'guest'],
                    reason: 'logout_requested',
                );
            } catch (AuditWriteException $exception) {
                request()->session()->invalidate();
                auth()->guard()->forgetUser();

                throw $exception;
            }
        });

        \Event::listen(Failed::class, function (Failed $event): void {
            /** @var User|null $user */
            $user = $event->user;
            $actor = $user
                ? AuditActor::user($user, AuditSource::Fortify)
                : AuditActor::system(AuditSource::Fortify);

            app(AuditRecorder::class)->record(
                $actor,
                'auth.login_failed',
                $user ? \App\Models\User::class : 'credential',
                reason: 'credentials_rejected',
            );
        });
    }
}
