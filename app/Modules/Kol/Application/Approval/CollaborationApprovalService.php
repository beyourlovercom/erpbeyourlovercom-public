<?php

namespace App\Modules\Kol\Application\Approval;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CollaborationApprovalService
{
    private const COLLABORATION = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolCollaboration';
    private const DECISION = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolCollaborationApprovalDecision';
    private const PAID_TYPE = '付费发帖';

    public function initialize(Model $collaboration, Authenticatable $actor): void
    {
        if (! $this->isPaid($collaboration)) {
            $this->setProjection($collaboration, 'not_required', null);
            return;
        }

        $outcome = $actor->can('kol.approvals.manage') ? 'approved' : 'pending';
        $source = $outcome === 'approved' ? 'privileged_creator' : 'specialist_created';
        $this->record($collaboration, $actor, $outcome, $source, null);
    }

    /** @param array<string,mixed> $before */
    public function reconcileAfterUpdate(Model $collaboration, array $before, Authenticatable $actor): void
    {
        $wasPaid = ($before['cooperation_type'] ?? null) === self::PAID_TYPE;
        $isPaid = $this->isPaid($collaboration);
        $termsChanged = $wasPaid !== $isPaid
            || $this->decimal($before['agreement_amount'] ?? null) !== $this->decimal($collaboration->agreement_amount)
            || strtoupper((string) ($before['currency'] ?? '')) !== strtoupper((string) $collaboration->currency);

        if (! $isPaid) {
            if ($collaboration->approval_status !== 'not_required') {
                $this->record($collaboration, $actor, 'not_required', 'terms_not_payable', null);
            }
            return;
        }

        if (! $termsChanged) {
            return;
        }

        $outcome = $actor->can('kol.approvals.manage') ? 'approved' : 'pending';
        $source = $outcome === 'approved' ? 'manager_terms_changed' : 'specialist_terms_changed';
        $this->record($collaboration, $actor, $outcome, $source, null);
    }

    public function approve(Model $collaboration, Authenticatable $actor, ?string $reason = null): Model
    {
        $this->authorizeManager($actor);
        return $this->manual($collaboration, $actor, 'approved', 'manager_approved', $reason);
    }

    public function reject(Model $collaboration, Authenticatable $actor, string $reason): Model
    {
        $this->authorizeManager($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new LogicException('Collaboration rejection reason is required.');
        }
        return $this->manual($collaboration, $actor, 'rejected', 'manager_rejected', $reason);
    }

    private function manual(Model $collaboration, Authenticatable $actor, string $outcome, string $source, ?string $reason): Model
    {
        return DB::transaction(function () use ($collaboration, $actor, $outcome, $source, $reason): Model {
            $collaborationClass = self::COLLABORATION;
            $locked = $collaborationClass::query()->whereKey($collaboration->id)->lockForUpdate()->firstOrFail();
            if (! $this->isPaid($locked) || $locked->approval_status !== 'pending') {
                throw new LogicException('Only pending paid Collaborations may be approved or rejected.');
            }
            return $this->recordLocked($locked, $actor, $outcome, $source, $reason);
        });
    }

    private function record(Model $collaboration, Authenticatable $actor, string $outcome, string $source, ?string $reason): Model
    {
        return DB::transaction(function () use ($collaboration, $actor, $outcome, $source, $reason): Model {
            $collaborationClass = self::COLLABORATION;
            $locked = $collaborationClass::query()->whereKey($collaboration->id)->lockForUpdate()->firstOrFail();
            return $this->recordLocked($locked, $actor, $outcome, $source, $reason);
        });
    }

    private function recordLocked(Model $locked, Authenticatable $actor, string $outcome, string $source, ?string $reason): Model
    {
        $decisionClass = self::DECISION;
        $decision = $decisionClass::create([
            'collaboration_id' => $locked->id,
            'outcome' => $outcome,
            'source' => $source,
            'actor_user_id' => (int) $actor->getAuthIdentifier(),
            'cooperation_type' => $locked->cooperation_type,
            'agreement_amount' => $locked->agreement_amount,
            'currency' => strtoupper((string) $locked->currency),
            'reason_text' => $reason,
            'decided_at' => now(),
        ]);
        $this->setProjection($locked, $outcome, $actor);
        return $decision;
    }

    private function setProjection(Model $collaboration, string $outcome, ?Authenticatable $actor): void
    {
        $approved = $outcome === 'approved';
        $collaboration->update([
            'approval_status' => $outcome,
            'approval_updated_at' => now(),
            'approved_by_user_id' => $approved && $actor ? (int) $actor->getAuthIdentifier() : null,
            'approved_at' => $approved ? now() : null,
        ]);
    }

    private function isPaid(Model $collaboration): bool
    {
        return (string) $collaboration->cooperation_type === self::PAID_TYPE;
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return number_format((float) $value, 2, '.', '');
    }

    private function authorizeManager(Authenticatable $actor): void
    {
        if (! $actor->can('kol.approvals.manage')) {
            throw new AuthorizationException('KOL approval management permission is required.');
        }
    }
}
