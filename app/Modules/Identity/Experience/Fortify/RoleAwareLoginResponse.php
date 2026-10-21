<?php

namespace App\Modules\Identity\Experience\Fortify;

use App\Modules\Identity\Application\AuthorizationKeys;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

final class RoleAwareLoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $user = $request->user();
        $hasDashboard = $user?->can(AuthorizationKeys::DashboardView->value) ?? false;

        if (! $hasDashboard && $user?->can(AuthorizationKeys::InventoryPackagingSubmit->value)) {
            return redirect()->intended(route('inventory.packaging.index'));
        }

        if (! $hasDashboard && $user?->can(AuthorizationKeys::ProcurementView->value)) {
            return redirect()->intended(route('procurement.index'));
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}
