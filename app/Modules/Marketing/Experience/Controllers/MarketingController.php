<?php

namespace App\Modules\Marketing\Experience\Controllers;

use App\Modules\Marketing\Infrastructure\Models\MarketingSubscriber;
use App\Modules\Marketing\Application\Contracts\WordPressSubscriberGateway;
use App\Modules\Marketing\Application\WordPressSubscriberSyncService;
use App\Modules\Sync\Application\SyncOrchestrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class MarketingController
{
    public function wordpressSync(WordPressSubscriberGateway $gateway, SyncOrchestrationService $orchestration): Response
    {
        $latest = $orchestration->recentRuns(50)->first(fn ($run): bool => $run->run_type === 'wordpress_subscribers_snapshot');
        return Inertia::render('Sync/WpApi', [
            'connection' => $gateway->descriptor(),
            'lastRun' => $latest ? [
                'id' => $latest->id, 'status' => $latest->status->value, 'started_at' => $latest->started_at?->toIso8601String(),
                'completed_at' => $latest->completed_at?->toIso8601String(), 'succeeded_items' => (int) $latest->succeeded_items,
                'failed_items' => (int) $latest->failed_items, 'conflict_items' => (int) $latest->conflict_items,
            ] : null,
        ]);
    }

    public function syncWordPress(WordPressSubscriberSyncService $service, Request $request): \Illuminate\Http\RedirectResponse
    {
        try {
            $run = $service->sync($request->user());
            $summary = sprintf('同步结果：成功 %d，失败 %d，冲突 %d。', $run->succeeded_items, $run->failed_items, $run->conflict_items);
            return $run->status->value === 'completed'
                ? back()->with('success', $summary)
                : back()->with('error', $summary);
        } catch (\Throwable $exception) {
            return back()->with('error', '同步失败：'.$exception->getMessage());
        }
    }
    public function overview(): Response
    {
        return Inertia::render('Marketing/Overview', [
            'metrics' => [
                'subscribers' => MarketingSubscriber::query()->count(),
                'subscriptions' => DB::table('marketing_subscriptions')->count(),
                'subscribed' => DB::table('marketing_subscriptions')->where('status', 'subscribed')->count(),
            ],
        ]);
    }

    public function subscribers(Request $request): Response
    {
        $query = MarketingSubscriber::query()
            ->select('marketing_subscribers.*')
            ->selectSub(function ($subquery): void {
                $subquery->from('marketing_subscriptions')->selectRaw('GROUP_CONCAT(DISTINCT provider)')
                    ->whereColumn('subscriber_id', 'marketing_subscribers.id');
            }, 'providers')
            ->selectSub(function ($subquery): void {
                $subquery->from('marketing_subscriptions')->selectRaw('GROUP_CONCAT(DISTINCT source_site)')
                    ->whereColumn('subscriber_id', 'marketing_subscribers.id');
            }, 'source_sites')
            ->selectSub(function ($subquery): void {
                $subquery->from('marketing_subscriptions')->selectRaw('GROUP_CONCAT(DISTINCT status)')
                    ->whereColumn('subscriber_id', 'marketing_subscribers.id');
            }, 'statuses')
            ->selectSub(function ($subquery): void {
                $subquery->from('marketing_subscriptions')->selectRaw('GROUP_CONCAT(DISTINCT external_list_name)')
                    ->whereColumn('subscriber_id', 'marketing_subscribers.id');
            }, 'lists')
            ->selectSub(fn ($subquery) => $subquery->from('marketing_subscriptions')->selectRaw('MAX(subscribed_at)')->whereColumn('subscriber_id', 'marketing_subscribers.id'), 'latest_subscribed_at')
            ->selectSub(fn ($subquery) => $subquery->from('marketing_subscriptions')->selectRaw('MAX(unsubscribed_at)')->whereColumn('subscriber_id', 'marketing_subscribers.id'), 'latest_unsubscribed_at')
            ->selectSub(fn ($subquery) => $subquery->from('marketing_subscriptions')->selectRaw('MAX(last_synced_at)')->whereColumn('subscriber_id', 'marketing_subscribers.id'), 'latest_synced_at');

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('email', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
        }
        // Multiple filters must refer to ONE subscription row. Otherwise a
        // subscribed fact from Site A could falsely satisfy Site B's opt-out.
        $subscriptionFilters = [];
        foreach (['provider', 'source_site', 'status'] as $filter) {
            $value = $request->input($filter);
            if (is_string($value) && trim($value) !== '') {
                $subscriptionFilters[$filter] = trim($value);
            }
        }
        if ($subscriptionFilters !== []) {
            $query->whereHas('subscriptions', function ($q) use ($subscriptionFilters): void {
                foreach ($subscriptionFilters as $key => $value) $q->where($key, $value);
            });
        }

        $subscribers = $query->orderBy('email')->paginate(50)->withQueryString();
        return Inertia::render('Marketing/Subscribers/Index', [
            'subscribers' => $subscribers,
            'filters' => $request->only(['search', 'status', 'provider', 'source_site']),
            'filterOptions' => [
                'statuses' => ['subscribed', 'unsubscribed', 'suppressed', 'pending', 'unknown'],
                'providers' => DB::table('marketing_subscriptions')->whereNotNull('provider')->distinct()->orderBy('provider')->pluck('provider')->values(),
                'source_sites' => DB::table('marketing_subscriptions')->whereNotNull('source_site')->distinct()->orderBy('source_site')->pluck('source_site')->values(),
            ],
            'gridPreference' => app('App\\Modules\\Shell\\Application\\UserUiPreferenceService')->get($request->user(), 'grid.marketing.subscribers.v1'),
        ]);
    }

    public function planned(string $workspace): Response
    {
        abort_unless(in_array($workspace, ['newsletters', 'campaigns', 'automations', 'promotions'], true), 404);
        return Inertia::render('Marketing/Planned', ['workspace' => $workspace]);
    }
}
