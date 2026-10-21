<?php

namespace App\Modules\Kol\Application\Approval;

use App\Modules\Kol\Application\AffiliateWp\PayoutWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

final class ManagerApprovalCenterService
{
    private const KOL = 'App\\Modules\\Kol\\Infrastructure\\Models\\Kol';
    private const KOL_DECISION = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolApprovalDecision';
    private const COLLABORATION = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolCollaboration';
    private const COLLABORATION_DECISION = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolCollaborationApprovalDecision';
    private const PAYOUT_REQUEST = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolPayoutRequest';

    public function __construct(
        private readonly KolApprovalService $kols,
        private readonly CollaborationApprovalService $collaborations,
        private readonly PayoutWorkflowService $payouts,
    ) {}

    /** @return array<string,mixed> */
    public function workspace(Authenticatable $actor, string $module): array
    {
        $this->authorize($actor);

        return match ($module) {
            'kols' => $this->kolWorkspace($actor),
            'collaborations' => $this->collaborationWorkspace($actor),
            'payouts' => $this->payoutWorkspace($actor),
            default => throw new InvalidArgumentException('Unsupported approval module.'),
        };
    }

    /** @return array<string,mixed> */
    private function kolWorkspace(Authenticatable $actor): array
    {
        $kolClass = self::KOL;
        $decisionClass = self::KOL_DECISION;
        $ownerId = (int) $actor->getAuthIdentifier();
        $pending = $kolClass::query()
            ->where('approval_manager_id', $ownerId)->whereNull('archived_at')->where('approval_status', 'pending')
            ->with(['owner:id,name', 'socialAccounts' => fn ($q) => $q->where('is_active', true)->orderBy('id')])
            ->orderBy('approval_updated_at')->orderBy('id')->limit(100)->get();
        $latestReasons = $decisionClass::query()->whereIn('kol_id', $pending->pluck('id'))
            ->orderByDesc('id')->get()->unique('kol_id')->keyBy('kol_id');
        $history = $decisionClass::query()->whereIn('outcome', ['approved', 'rejected'])
            ->whereHas('kol', fn ($q) => $q->where('approval_manager_id', $ownerId))
            ->with(['kol:id,display_name,country_code,cooperation_type,owner_id', 'actor:id,name'])
            ->orderByDesc('decided_at')->orderByDesc('id')->limit(300)->get()
            ->map(fn ($row): array => [
                'record_key' => 'kol:'.$row->id,
                'id' => (int) $row->kol_id,
                'title' => $row->kol?->display_name ?? 'KOL #'.$row->kol_id,
                'meta' => array_values(array_filter([$row->kol?->country_code, $row->kol?->cooperation_type])),
                'outcome' => (string) $row->outcome,
                'actor_name' => $row->actor?->name ?? ($row->actor_user_id ? 'User #'.$row->actor_user_id : '系统'),
                'decided_at' => $row->decided_at?->toIso8601String(),
                'source' => (string) $row->source,
                'reason' => $row->reason_text,
                'detail_href' => '/kol/library/'.$row->kol_id,
            ])->values();

        return [
            'module' => 'kols', 'pending_count' => $pending->count(), 'history_count' => $history->count(),
            'pending' => $pending->map(function ($row) use ($latestReasons): array {
                $social = $row->socialAccounts->first();
                return [
                    'id' => (int) $row->id, 'title' => $row->display_name,
                    'owner_name' => $row->owner?->name, 'country_code' => $row->country_code,
                    'cooperation_type' => $row->cooperation_type, 'platform' => $social?->platform,
                    'handle' => $social?->handle, 'reason_code' => $latestReasons->get($row->id)?->reason_code,
                    'created_at' => $row->created_at?->toIso8601String(),
                    'submitted_at' => ($row->approval_updated_at ?? $row->created_at)?->toIso8601String(),
                    'detail_href' => '/kol/library/'.$row->id,
                ];
            })->values()->all(),
            'history' => $history->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function collaborationWorkspace(Authenticatable $actor): array
    {
        $collaborationClass = self::COLLABORATION;
        $decisionClass = self::COLLABORATION_DECISION;
        $ownerId = (int) $actor->getAuthIdentifier();
        $pending = $collaborationClass::query()->where('approval_status', 'pending')
            ->whereHas('kol', fn ($q) => $q->where('approval_manager_id', $ownerId))
            ->with(['kol:id,display_name,owner_id', 'owner:id,name'])
            ->orderBy('approval_updated_at')->orderBy('id')->limit(100)->get();
        $history = $decisionClass::query()->whereIn('outcome', ['approved', 'rejected'])
            ->whereHas('collaboration.kol', fn ($q) => $q->where('approval_manager_id', $ownerId))
            ->with(['collaboration:id,kol_id,title,owner_id', 'collaboration.kol:id,display_name,owner_id', 'actor:id,name'])
            ->orderByDesc('decided_at')->orderByDesc('id')->limit(300)->get()
            ->map(fn ($row): array => [
                'record_key' => 'collaboration:'.$row->id,
                'id' => (int) $row->collaboration_id,
                'kol_id' => (int) ($row->collaboration?->kol_id ?? 0),
                'title' => $row->collaboration?->title ?: '合作 #'.$row->collaboration_id,
                'kol_name' => $row->collaboration?->kol?->display_name ?? 'KOL',
                'cooperation_type' => $row->cooperation_type,
                'amount' => $row->agreement_amount === null ? null : (string) $row->agreement_amount,
                'currency' => $row->currency === null ? null : strtoupper((string) $row->currency),
                'outcome' => (string) $row->outcome,
                'actor_name' => $row->actor?->name ?? ($row->actor_user_id ? 'User #'.$row->actor_user_id : '系统'),
                'decided_at' => $row->decided_at?->toIso8601String(),
                'source' => (string) $row->source, 'reason' => $row->reason_text,
                'detail_href' => $row->collaboration?->kol_id ? '/kol/library/'.$row->collaboration->kol_id : '/kol/collaborations',
            ])->values();

        return [
            'module' => 'collaborations', 'pending_count' => $pending->count(), 'history_count' => $history->count(),
            'pending' => $pending->map(fn ($row): array => [
                'id' => (int) $row->id, 'kol_id' => (int) $row->kol_id,
                'title' => $row->title ?: '合作 #'.$row->id,
                'kol_name' => $row->kol?->display_name ?? 'KOL #'.$row->kol_id,
                'owner_name' => $row->owner?->name, 'cooperation_type' => $row->cooperation_type,
                'amount' => (string) $row->agreement_amount, 'currency' => strtoupper((string) $row->currency),
                'deliverables' => $row->deliverables, 'planned_publish_at' => $row->planned_publish_at?->format('Y-m-d'),
                'created_at' => $row->created_at?->toIso8601String(),
                'submitted_at' => ($row->approval_updated_at ?? $row->created_at)?->toIso8601String(),
                'detail_href' => '/kol/library/'.$row->kol_id,
            ])->values()->all(),
            'history' => $history->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function payoutWorkspace(Authenticatable $actor): array
    {
        $payoutClass = self::PAYOUT_REQUEST;
        $ownerId = (int) $actor->getAuthIdentifier();
        $pending = $payoutClass::query()->where('status', 'pending_approval')
            ->whereHas('kol', fn ($q) => $q->where('approval_manager_id', $ownerId))
            ->with(['items', 'kol:id,display_name,owner_id', 'requestedBy:id,name'])
            ->orderBy('created_at')->orderBy('id')->limit(100)->get();
        $history = $payoutClass::query()->where(fn ($q) => $q->whereNotNull('approved_at')->orWhereNotNull('rejected_at'))
            ->whereHas('kol', fn ($q) => $q->where('approval_manager_id', $ownerId))
            ->with(['kol:id,display_name,owner_id', 'requestedBy:id,name', 'approvedBy:id,name', 'rejectedBy:id,name'])
            ->orderByDesc('id')->limit(300)->get()
            ->map(function ($row): array {
                $rejected = $row->rejected_at !== null;
                return [
                    'record_key' => 'payout:'.$row->id, 'id' => (int) $row->id,
                    'title' => 'Payout #'.$row->id, 'kol_name' => $row->kol?->display_name ?? 'KOL #'.$row->kol_id,
                    'requester_name' => $row->requestedBy?->name ?? 'User #'.$row->requested_by_user_id,
                    'affiliate_id' => (int) $row->affiliate_id, 'amount' => (string) $row->amount,
                    'currency' => strtoupper((string) $row->currency),
                    'outcome' => $rejected ? 'rejected' : 'approved',
                    'actor_name' => $rejected ? ($row->rejectedBy?->name ?? 'User #'.$row->rejected_by_user_id) : ($row->approvedBy?->name ?? 'User #'.$row->approved_by_user_id),
                    'decided_at' => ($rejected ? $row->rejected_at : $row->approved_at)?->toIso8601String(),
                    'source' => $rejected ? 'manager_payout_rejected' : 'manager_payout_approved',
                    'reason' => $row->rejection_reason, 'detail_href' => '/kol/payouts',
                ];
            })->sortByDesc('decided_at')->values();

        return [
            'module' => 'payouts', 'pending_count' => $pending->count(), 'history_count' => $history->count(),
            'pending' => $pending->map(fn ($row): array => [
                'id' => (int) $row->id, 'title' => 'Payout #'.$row->id,
                'kol_name' => $row->kol?->display_name ?? 'KOL #'.$row->kol_id,
                'requester_name' => $row->requestedBy?->name ?? 'User #'.$row->requested_by_user_id,
                'affiliate_id' => (int) $row->affiliate_id, 'amount' => (string) $row->amount,
                'currency' => strtoupper((string) $row->currency),
                'referral_ids' => $row->items->pluck('referral_id')->map(fn ($id) => (int) $id)->values()->all(),
                'created_at' => $row->created_at?->toIso8601String(), 'submitted_at' => $row->created_at?->toIso8601String(),
                'detail_href' => '/kol/payouts',
            ])->values()->all(),
            'history' => $history->all(),
        ];
    }

    public function approveKol(int $id, Authenticatable $actor): void
    {
        $this->authorize($actor);
        $kolClass = self::KOL;
        $kol = $kolClass::query()->where('approval_manager_id', (int) $actor->getAuthIdentifier())->findOrFail($id);
        $this->kols->approve($kol, $actor);
    }

    public function rejectKol(int $id, string $reason, Authenticatable $actor): void
    {
        $this->authorize($actor);
        $kolClass = self::KOL;
        $kol = $kolClass::query()->where('approval_manager_id', (int) $actor->getAuthIdentifier())->findOrFail($id);
        $this->kols->reject($kol, $actor, $reason);
    }

    public function approveCollaboration(int $id, Authenticatable $actor): void
    {
        $this->authorize($actor);
        $collaborationClass = self::COLLABORATION;
        $row = $collaborationClass::query()->whereHas('kol', fn ($q) => $q->where('approval_manager_id', (int) $actor->getAuthIdentifier()))->findOrFail($id);
        $this->collaborations->approve($row, $actor);
    }

    public function rejectCollaboration(int $id, string $reason, Authenticatable $actor): void
    {
        $this->authorize($actor);
        $collaborationClass = self::COLLABORATION;
        $row = $collaborationClass::query()->whereHas('kol', fn ($q) => $q->where('approval_manager_id', (int) $actor->getAuthIdentifier()))->findOrFail($id);
        $this->collaborations->reject($row, $actor, $reason);
    }

    public function approvePayout(int $id, Authenticatable $actor): void
    {
        $this->authorize($actor);
        $this->assertOwnedPayout($id, $actor);
        $this->payouts->approve($id, $actor);
    }

    public function rejectPayout(int $id, string $reason, Authenticatable $actor): void
    {
        $this->authorize($actor);
        $this->assertOwnedPayout($id, $actor);
        $this->payouts->reject($id, $reason, $actor);
    }

    private function assertOwnedPayout(int $id, Authenticatable $actor): void
    {
        $payoutClass = self::PAYOUT_REQUEST;
        $payoutClass::query()->whereHas('kol', fn ($q) => $q->where('approval_manager_id', (int) $actor->getAuthIdentifier()))->findOrFail($id);
    }

    private function authorize(Authenticatable $actor): void
    {
        if (! $actor->can('kol.approvals.manage')) throw new AuthorizationException('Manager Approval Center permission is required.');
    }
}
