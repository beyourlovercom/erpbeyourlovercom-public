<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Application\KolOperationalDashboardService;
use App\Modules\Kol\Application\KolScope;
use App\Modules\Kol\Application\KolService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class KolOverviewController
{
    public function __construct(private readonly KolService $kols, private readonly KolOperationalDashboardService $dashboard) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $ownerScopeId = KolScope::effectiveOwnerId($user);
        $canManageViews = KolScope::canManageViews($user);
        $filters = $request->only(['owner_id', 'platform', 'country_code', 'level', 'relationship_stage', 'date_from', 'date_to', 'period']);
        $filters['period'] = in_array(($filters['period'] ?? null), ['week', 'month'], true) ? $filters['period'] : 'week';
        $overview = $this->kols->overview($ownerScopeId, $filters);
        $operations = $this->dashboard->metrics($user, $filters);

        $ownerOptions = [];
        if ($canManageViews) {
            $ownerOptions = User::whereHas('roles', function ($q): void {
                $q->whereHas('permissions', function ($q): void {
                    $q->where('key', 'kol.view');
                });
            })->get(['id', 'name'])->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->values()->all();
        }

        return Inertia::render('Kol/Overview', [
            'overview' => $overview,
            'operations' => $operations,
            'filters' => $filters,
            'ownerOptions' => $ownerOptions,
            'canOperate' => KolScope::canOperate($user),
            'canManageViews' => $canManageViews,
            'canManageApprovals' => $user->can('kol.approvals.manage'),
            'canAccessPayouts' => $user->can('kol.payouts.request') || $user->can('kol.payouts.approve') || $user->can('kol.payouts.manage'),
        ]);
    }
}
