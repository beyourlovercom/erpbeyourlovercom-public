<?php

namespace App\Http\Middleware;

use App\Modules\NotificationCenter\Application\NotificationCenterProjector;
use App\Modules\Shell\Application\ErpShellNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $flash = [
            'success' => $request->session()->get('success'),
            'error' => $request->session()->get('error'),
            'warning' => $request->session()->get('warning'),
            'info' => $request->session()->get('info'),
            'confirm_required' => $request->session()->get('confirm_required'),
            'evidence_id' => $request->session()->get('evidence_id'),
        ];
        $hasFeedback = collect($flash)->contains(fn ($value): bool => is_string($value) && trim($value) !== '');

        return [
            ...parent::share($request),
            'auth' => ['user' => $user?->only(['id', 'name', 'email'])],
            'shellNavigation' => $user ? app(ErpShellNavigation::class)->for($user) : [],
            'notificationCenter' => $user ? app(NotificationCenterProjector::class)->summary($user) : ['unread_count'=>0, 'items'=>[]],
            'flash' => [
                'feedback_id' => $hasFeedback ? (string) Str::uuid() : null,
                ...$flash,
            ],
        ];
    }
}
