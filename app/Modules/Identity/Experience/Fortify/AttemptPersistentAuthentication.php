<?php

namespace App\Modules\Identity\Experience\Fortify;

use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

final class AttemptPersistentAuthentication
{
    public function __construct(
        private readonly StatefulGuard $guard,
        private readonly LoginRateLimiter $limiter,
    ) {}

    public function handle($request, $next): mixed
    {
        $user = call_user_func(Fortify::$authenticateUsingCallback, $request);

        if (! $user) {
            event(new Failed($this->guard->name ?? config('fortify.guard'), null, [
                Fortify::username() => $request->{Fortify::username()},
                'password' => $request->password,
            ]));

            $this->limiter->increment($request);

            throw ValidationException::withMessages([
                Fortify::username() => [trans('auth.failed')],
            ]);
        }

        $this->guard->login($user, true);

        return $next($request);
    }
}
