<?php

namespace App\Modules\Kol\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Kol\Application\Approval\KolApprovalService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class KolService
{
    private const KOL = 'App\\Modules\\Kol\\Infrastructure\\Models\\Kol';

    private const KOL_SOCIAL_ACCOUNT = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolSocialAccount';

    private const KOL_SALES_PERFORMANCE = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolSalesPerformance';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly KolApprovalService $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, ?int $ownerScopeId = null, ?string $affiliateConnectionKey = null): LengthAwarePaginator
    {
        $kolClass = self::KOL;
        $affiliateConnectionKey = trim((string) ($affiliateConnectionKey ?? config('services.andy_core.connection_key', 'beyourlover.com'))) ?: 'beyourlover.com';
        $query = $kolClass::with([
            'owner',
            'socialAccounts.latestMetric',
            'affiliateAccounts' => fn ($q) => $q
                ->where('connection_key', $affiliateConnectionKey)
                ->select(['id', 'kol_id', 'connection_key', 'affiliate_id', 'wordpress_user_id', 'wordpress_user_login']),
        ])
            ->withSum('salesPerformances as historical_order_count', 'order_count')
            ->withExists(['contents as has_published_content' => fn (Builder $q) => $q->where('publication_status', 'published')])
            ->active();

        if ($ownerScopeId !== null) {
            $query->where('owner_id', $ownerScopeId);
        } elseif (isset($filters['owner_id']) && $filters['owner_id'] !== '') {
            $query->where('owner_id', (int) $filters['owner_id']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%'.mb_strtolower($filters['search']).'%';
            $query->where(function (Builder $q) use ($search): void {
                $q->whereRaw('LOWER(display_name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(legal_name) LIKE ?', [$search]);
            });
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['relationship_stage']) && $filters['relationship_stage'] !== '') {
            $query->where('relationship_stage', $filters['relationship_stage']);
        }

        if (isset($filters['country_code']) && $filters['country_code'] !== '') {
            $query->where('country_code', $filters['country_code']);
        }

        if (isset($filters['platform']) && $filters['platform'] !== '') {
            $query->whereHas('socialAccounts', function (Builder $q) use ($filters): void {
                $q->where('platform', $filters['platform'])->where('is_active', true);
            });
        }

        if (isset($filters['followers_min']) && $filters['followers_min'] !== '') {
            $query->whereHas('socialAccounts', function (Builder $q) use ($filters): void {
                $q->where('is_active', true)->whereHas('latestMetric', function (Builder $mq) use ($filters): void {
                    $mq->where('followers', '>=', (int) $filters['followers_min']);
                });
            });
        }

        if (isset($filters['followers_max']) && $filters['followers_max'] !== '') {
            $query->whereHas('socialAccounts', function (Builder $q) use ($filters): void {
                $q->where('is_active', true)->whereHas('latestMetric', function (Builder $mq) use ($filters): void {
                    $mq->where('followers', '<=', (int) $filters['followers_max']);
                });
            });
        }

        if (isset($filters['level']) && $filters['level'] !== '') {
            $query->where('level', $filters['level']);
        }

        if (isset($filters['content_format']) && $filters['content_format'] !== '') {
            $query->where('content_format', $filters['content_format']);
        }

        if (isset($filters['mcn']) && $filters['mcn'] !== '') {
            if ($filters['mcn'] === 'yes') {
                $query->where(function (Builder $q): void {
                    $q->where('is_mcn_managed', true)->orWhereNotNull('mcn_agency');
                });
            } else {
                $query->where('is_mcn_managed', false)->whereNull('mcn_agency');
            }
        }

        $hasPublished = $filters['has_published_content'] ?? '';
        $evidence = $filters['publication_evidence'] ?? '';
        if ($hasPublished === 'yes') {
            $query->whereHas('contents', function (Builder $q) use ($evidence): void {
                $q->where('publication_status', 'published');
                if ($evidence !== '') {
                    $this->applyContentEvidenceFilter($q, $evidence);
                }
            });
        } elseif ($hasPublished === 'no') {
            $query->whereDoesntHave('contents', fn (Builder $q) => $q->where('publication_status', 'published'));
            if ($evidence !== '') {
                $query->whereHas('contents', fn (Builder $q) => $this->applyContentEvidenceFilter($q, $evidence));
            }
        } elseif ($evidence !== '') {
            $query->whereHas('contents', fn (Builder $q) => $this->applyContentEvidenceFilter($q, $evidence));
        }

        if (! empty($filters['view'])) {
            $query = $this->applySystemView($query, $filters['view'], $ownerScopeId);
        }

        $sort = $filters['sort'] ?? 'display_name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $allowedSorts = ['display_name', 'joined_at', 'created_at', 'relationship_stage'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'display_name';
        }
        $query->orderBy($sort, $direction);

        return $query->paginate(30)->withQueryString();
    }

    private function applyContentEvidenceFilter(Builder $query, string $evidence): void
    {
        match ($evidence) {
            'none' => $query->where(fn (Builder $q) => $q->whereNull('post_url')->orWhere('post_url', ''))->whereDoesntHave('attachments'),
            'url' => $query->whereNotNull('post_url')->where('post_url', '!=', '')->whereDoesntHave('attachments'),
            'screenshot' => $query->where(fn (Builder $q) => $q->whereNull('post_url')->orWhere('post_url', ''))->whereHas('attachments'),
            'url_and_screenshot' => $query->whereNotNull('post_url')->where('post_url', '!=', '')->whereHas('attachments'),
            default => null,
        };
    }

    /**
     * @return array<string, int>
     */
    private function platformDistribution(?int $ownerScopeId): array
    {
        $saClass = self::KOL_SOCIAL_ACCOUNT;

        return $saClass::query()
            ->where('is_active', true)
            ->whereHas('kol', function (Builder $q) use ($ownerScopeId): void {
                $q->active();
                if ($ownerScopeId !== null) {
                    $q->where('owner_id', $ownerScopeId);
                }
            })
            ->selectRaw('platform, count(*) as count')
            ->groupBy('platform')
            ->pluck('count', 'platform')
            ->all();
    }

    private function applySystemView(Builder $query, string $view, ?int $ownerScopeId): Builder
    {
        return match ($view) {
            'my_kols' => $ownerScopeId !== null ? $query : $query,
            'all_kols' => $query,
            '100k_plus' => $query->hundredKPlus(),
            default => $query,
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function overview(?int $ownerScopeId = null, array $filters = []): array
    {
        $kolClass = self::KOL;
        $base = $kolClass::active();

        if (! empty($filters['platform'])) {
            $base->whereHas('socialAccounts', function (Builder $q) use ($filters): void {
                $q->where('platform', $filters['platform'])->where('is_active', true);
            });
        }
        if (! empty($filters['country_code'])) {
            $base->where('country_code', $filters['country_code']);
        }
        if (! empty($filters['level'])) {
            $base->where('level', $filters['level']);
        }
        if (! empty($filters['relationship_stage'])) {
            $base->where('relationship_stage', $filters['relationship_stage']);
        }
        if (! empty($filters['date_from'])) {
            $base->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $base->where('created_at', '<=', $filters['date_to'].' 23:59:59');
        }
        if ($ownerScopeId === null && ! empty($filters['owner_id'])) {
            $base->where('owner_id', (int) $filters['owner_id']);
        }

        $scoped = $ownerScopeId !== null ? (clone $base)->where('owner_id', $ownerScopeId) : clone $base;
        $collaboratedStages = ['cooperating', 'long_term'];

        $ranking = [];
        if ($ownerScopeId === null) {
            $ranking = (clone $base)
                ->whereNotNull('owner_id')
                ->selectRaw('owner_id, count(*) as count')
                ->groupBy('owner_id')
                ->orderByDesc('count')
                ->with('owner:id,name')
                ->get()
                ->map(fn ($row): array => [
                    'owner_id' => $row->owner_id,
                    'owner_name' => $row->owner?->name ?? '未知',
                    'count' => (int) $row->count,
                ])
                ->values()
                ->all();
        }

        return [
            'total' => $base->count(),
            'mine' => $ownerScopeId !== null ? $scoped->count() : null,
            'monthly_new' => (clone $base)->where('created_at', '>=', now()->startOfMonth())->count(),
            'collaborated' => (clone $base)->whereIn('relationship_stage', $collaboratedStages)->count(),
            'long_term' => (clone $base)->where('relationship_stage', 'long_term')->count(),
            'hundred_k_plus' => (clone $base)->hundredKPlus()->count(),
            'by_stage' => (clone $base)
                ->selectRaw('relationship_stage, count(*) as count')
                ->groupBy('relationship_stage')
                ->pluck('count', 'relationship_stage')
                ->all(),
            'by_level' => (clone $base)
                ->whereNotNull('level')
                ->selectRaw('level, count(*) as count')
                ->groupBy('level')
                ->pluck('count', 'level')
                ->all(),
            'by_content_format' => (clone $base)
                ->whereNotNull('content_format')
                ->selectRaw('content_format, count(*) as count')
                ->groupBy('content_format')
                ->pluck('count', 'content_format')
                ->all(),
            'by_platform' => $this->platformDistribution($ownerScopeId),
            'owner_ranking' => $ranking,
            'pending_publication_count' => null,
            'pending_commission_amount' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $socialAccounts
     * @param  array<int, array<string, mixed>>  $salesPerformanceEntries
     */
    public function create(array $data, array $socialAccounts, Authenticatable $actor, array $salesPerformanceEntries = [], bool $evaluateApproval = true): Model
    {
        $kolClass = self::KOL;
        $kol = $kolClass::create($data);

        $this->addSalesPerformanceEntries($kol, $salesPerformanceEntries, $actor);

        foreach ($socialAccounts as $account) {
            $sa = $kol->socialAccounts()->create([
                'platform' => $account['platform'],
                'handle' => $account['handle'] ?? null,
                'profile_url' => $account['profile_url'] ?? null,
                'is_active' => $account['is_active'] ?? true,
            ]);

            if (! empty($account['followers']) || ! empty($account['raw_followers_value'])) {
                $sa->metrics()->create([
                    'followers' => $account['followers'] ?? null,
                    'raw_followers_value' => $account['raw_followers_value'] ?? null,
                    'measured_at' => now(),
                ]);
            }
        }

        if ($evaluateApproval) {
            $this->approvals->evaluateAndRecord($kol, $actor, KolScope::canManageApprovals($actor));
        }

        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            'kol.created',
            'kol',
            $kol->id,
            null,
            ['display_name' => $kol->display_name, 'owner_id' => $kol->owner_id],
        );

        return $kol;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $socialAccounts
     * @param  array<int, array<string, mixed>>  $salesPerformanceEntries
     */
    public function update(Model $kol, array $data, array $socialAccounts, Authenticatable $actor, array $salesPerformanceEntries = []): Model
    {
        $before = ['display_name' => $kol->display_name, 'owner_id' => $kol->owner_id, 'status' => $kol->status];

        $previousOwnerId = $kol->owner_id;
        $kol->update($data);

        $this->syncSocialAccounts($kol, $socialAccounts);
        $this->addSalesPerformanceEntries($kol, $salesPerformanceEntries, $actor);

        $after = ['display_name' => $kol->display_name, 'owner_id' => $kol->owner_id, 'status' => $kol->status];

        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            'kol.updated',
            'kol',
            $kol->id,
            $before,
            $after,
        );

        if ($previousOwnerId !== $kol->owner_id) {
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'kol.owner_reassigned',
                'kol',
                $kol->id,
                ['owner_id' => $previousOwnerId],
                ['owner_id' => $kol->owner_id],
            );
        }

        return $kol;
    }

    public function archive(Model $kol, Authenticatable $actor): void
    {
        $kol->update(['archived_at' => now()]);

        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            'kol.archived',
            'kol',
            $kol->id,
        );
    }

    public function reactivate(Model $kol, Authenticatable $actor): void
    {
        $kol->update(['archived_at' => null]);

        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            'kol.reactivated',
            'kol',
            $kol->id,
        );
    }

    /**
     * Sales-performance facts are additive/history-preserving: each event is recorded once and
     * never edited or deleted through this entry point, so the derived lifetime total always
     * equals the sum of the individually preserved facts.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function addSalesPerformanceEntries(Model $kol, array $entries, Authenticatable $actor): void
    {
        if ($entries === []) {
            return;
        }

        $performanceClass = self::KOL_SALES_PERFORMANCE;
        $created = 0;

        foreach ($entries as $entry) {
            if (empty($entry['order_count'])) {
                continue;
            }

            $performanceClass::create([
                'kol_id' => $kol->id,
                'order_count' => (int) $entry['order_count'],
                'recorded_at' => $entry['recorded_at'] ?? now(),
                'source' => $entry['source'] ?? null,
                'note' => $entry['note'] ?? null,
                'created_by' => (int) $actor->getAuthIdentifier(),
            ]);
            $created++;
        }

        if ($created === 0) {
            return;
        }

        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            'kol.sales_performance.recorded',
            'kol',
            $kol->id,
            null,
            ['entries_added' => $created],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     */
    private function syncSocialAccounts(Model $kol, array $accounts): void
    {
        $saClass = self::KOL_SOCIAL_ACCOUNT;
        $keepIds = [];

        foreach ($accounts as $account) {
            $id = $account['id'] ?? null;
            if ($id) {
                $sa = $saClass::where('kol_id', $kol->id)->find($id);
                if ($sa) {
                    $sa->update([
                        'platform' => $account['platform'],
                        'handle' => $account['handle'] ?? null,
                        'profile_url' => $account['profile_url'] ?? null,
                        'is_active' => $account['is_active'] ?? true,
                    ]);
                    $keepIds[] = $sa->id;

                    if (array_key_exists('followers', $account)) {
                        $sa->metrics()->create([
                            'followers' => $account['followers'] ?? null,
                            'raw_followers_value' => $account['raw_followers_value'] ?? null,
                            'measured_at' => now(),
                        ]);
                    }

                    continue;
                }
            }

            $sa = $kol->socialAccounts()->create([
                'platform' => $account['platform'],
                'handle' => $account['handle'] ?? null,
                'profile_url' => $account['profile_url'] ?? null,
                'is_active' => $account['is_active'] ?? true,
            ]);
            $keepIds[] = $sa->id;

            if (! empty($account['followers']) || ! empty($account['raw_followers_value'])) {
                $sa->metrics()->create([
                    'followers' => $account['followers'] ?? null,
                    'raw_followers_value' => $account['raw_followers_value'] ?? null,
                    'measured_at' => now(),
                ]);
            }
        }

        $kol->socialAccounts()->whereNotIn('id', $keepIds)->delete();
    }
}
