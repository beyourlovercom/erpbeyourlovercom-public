<?php

namespace App\Modules\Kol\Application;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

final class KolOperationalDashboardService
{
    private const KOL = 'App\\Modules\\Kol\\Infrastructure\\Models\\Kol';
    private const APPROVAL_DECISION = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolApprovalDecision';
    private const COLLABORATION = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolCollaboration';
    private const CONTENT = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolContent';
    private const REFERRAL = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliateReferral';
    private const PAYOUT_REQUEST = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolPayoutRequest';
    /** @param array<string,mixed> $filters
     *  @return array<string,mixed>
     */
    public function metrics(Authenticatable $actor, array $filters): array
    {
        $period = in_array(($filters['period'] ?? null), ['week', 'month'], true) ? (string) $filters['period'] : 'week';
        $now = CarbonImmutable::now();
        $start = $period === 'month' ? $now->startOfMonth() : $now->startOfWeek();
        $end = $now->endOfDay();

        $kolClass=self::KOL; $decisionClass=self::APPROVAL_DECISION; $collaborationClass=self::COLLABORATION; $contentClass=self::CONTENT; $referralClass=self::REFERRAL; $payoutClass=self::PAYOUT_REQUEST;
        $base = $kolClass::query()->whereNull('archived_at');
        $this->applyKolFilters($base, $actor, $filters);
        $kolIds = $base->pluck('id');

        $newKols = $kolClass::query()->whereIn('id', $kolIds)->whereBetween('created_at', [$start, $end])->count();
        $approvedKols = $decisionClass::query()->whereIn('kol_id', $kolIds)->where('outcome', 'approved')->whereBetween('decided_at', [$start, $end])->distinct('kol_id')->count('kol_id');
        $rejectedKols = $decisionClass::query()->whereIn('kol_id', $kolIds)->where('outcome', 'rejected')->whereBetween('decided_at', [$start, $end])->distinct('kol_id')->count('kol_id');
        $collaborationCount = $collaborationClass::query()->whereIn('kol_id', $kolIds)->whereBetween('created_at', [$start, $end])->count();
        $publicationCount = $contentClass::query()->whereIn('kol_id', $kolIds)->where('publication_status', 'published')->whereDate('published_at', '>=', $start->toDateString())->whereDate('published_at', '<=', $end->toDateString())->count();

        $affiliateRows = $referralClass::query()->whereIn('kol_id', $kolIds)
            ->where('reconciliation_status', 'present')->whereIn('provider_status', ['unpaid', 'paid'])
            ->whereBetween('referral_date', [$start, $end])
            ->selectRaw('currency, count(distinct order_reference) as sales_count, sum(commission_amount) as commission_total')
            ->groupBy('currency')->orderBy('currency')->get();

        $eligibleRows = $referralClass::query()->whereIn('kol_id', $kolIds)
            ->where('reconciliation_status', 'present')->where('provider_status', 'unpaid')->whereNull('payout_id')
            ->where('affiliate_resolution_status', 'bound')
            ->selectRaw('currency, count(*) as referral_count, sum(commission_amount) as commission_total')
            ->groupBy('currency')->orderBy('currency')->get();

        $periodPayoutQuery = $payoutClass::query()->whereIn('kol_id', $kolIds)->whereBetween('created_at', [$start, $end]);
        $currentPayoutQuery = $payoutClass::query()->whereIn('kol_id', $kolIds);
        if (! KolScope::isAllScope($actor)) {
            $periodPayoutQuery->where('requested_by_user_id', (int) $actor->getAuthIdentifier());
            $currentPayoutQuery->where('requested_by_user_id', (int) $actor->getAuthIdentifier());
        }
        $periodPayoutStatuses = (clone $periodPayoutQuery)->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status')->map(fn ($v) => (int) $v)->all();
        $currentPayoutStatuses = (clone $currentPayoutQuery)->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status')->map(fn ($v) => (int) $v)->all();

        $managerTasks = null;
        if ($actor->can('kol.approvals.manage')) {
            $managerTasks = [
                'kols' => $kolClass::query()->whereIn('id', $kolIds)->where('approval_status', 'pending')->count(),
                'collaborations' => $collaborationClass::query()->whereIn('kol_id', $kolIds)->where('approval_status', 'pending')->count(),
                'payouts' => $payoutClass::query()->whereIn('kol_id', $kolIds)->where('status', 'pending_approval')->count(),
            ];
        }

        return [
            'period' => $period,
            'period_label' => $period === 'month' ? '本月' : '本周',
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'scope' => KolScope::isAllScope($actor) ? 'all' : 'mine',
            'new_kols' => $newKols,
            'approved_kols' => $approvedKols,
            'rejected_kols' => $rejectedKols,
            'collaboration_records' => $collaborationCount,
            'publication_records' => $publicationCount,
            'affiliate' => $affiliateRows->map(fn ($row): array => [
                'currency' => strtoupper((string) $row->currency),
                'sales_count' => (int) $row->sales_count,
                'commission_total' => $this->decimalSix($row->commission_total),
            ])->values()->all(),
            'eligible_commission' => $eligibleRows->map(fn ($row): array => [
                'currency' => strtoupper((string) $row->currency),
                'referral_count' => (int) $row->referral_count,
                'commission_total' => $this->decimalSix($row->commission_total),
            ])->values()->all(),
            'payout_period_statuses' => $periodPayoutStatuses,
            'payout_current_statuses' => $currentPayoutStatuses,
            'manager_tasks' => $managerTasks,
        ];
    }

    private function decimalSix(mixed $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    /** @param array<string,mixed> $filters */
    private function applyKolFilters(Builder $query, Authenticatable $actor, array $filters): void
    {
        if (! KolScope::isAllScope($actor)) {
            $query->where('owner_id', (int) $actor->getAuthIdentifier());
        } elseif (! empty($filters['owner_id'])) {
            $query->where('owner_id', (int) $filters['owner_id']);
        }
        if (! empty($filters['platform'])) {
            $query->whereHas('socialAccounts', fn (Builder $q) => $q->where('platform', $filters['platform'])->where('is_active', true));
        }
        if (! empty($filters['country_code'])) $query->where('country_code', $filters['country_code']);
        if (! empty($filters['level'])) $query->where('level', $filters['level']);
        if (! empty($filters['relationship_stage'])) $query->where('relationship_stage', $filters['relationship_stage']);
    }
}
