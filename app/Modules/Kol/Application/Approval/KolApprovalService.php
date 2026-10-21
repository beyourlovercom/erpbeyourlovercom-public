<?php

namespace App\Modules\Kol\Application\Approval;

use App\Modules\Kol\Application\KolScope;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class KolApprovalService
{
    private const POLICY = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolApprovalPolicy';

    private const DECISION = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolApprovalDecision';

    private const MODES = ['ALL', 'THRESHOLD', 'NONE'];

    private const PLATFORMS = ['instagram', 'tiktok', 'youtube', 'xiaohongshu', 'weibo', 'wechat', 'twitter', 'facebook', 'other'];

    public function __construct(
        private readonly KolApprovalPolicyEvaluator $evaluator,
    ) {}

    public function currentPolicy(): ?Model
    {
        $policyClass = self::POLICY;

        return $policyClass::query()
            ->where('effective_at', '<=', now())
            ->orderByDesc('version')
            ->first();
    }

    /** @param array<string,int> $thresholds */
    public function createPolicy(string $mode, array $thresholds, Authenticatable $actor, ?DateTimeInterface $effectiveAt = null): Model
    {
        $this->authorizeManager($actor);
        $mode = strtoupper(trim($mode));
        $thresholds = $this->normalizePolicyThresholds($mode, $thresholds);
        $policyClass = self::POLICY;

        return DB::transaction(function () use ($policyClass, $mode, $thresholds, $actor, $effectiveAt): Model {
            $latestVersion = (int) ($policyClass::query()->lockForUpdate()->max('version') ?? 0);

            return $policyClass::create([
                'version' => $latestVersion + 1,
                'mode' => $mode,
                'thresholds' => $thresholds,
                'effective_at' => $effectiveAt ?? now(),
                'created_by' => $actor->getAuthIdentifier(),
            ]);
        });
    }

    public function evaluateAndRecord(Model $kol, Authenticatable $actor, bool $privilegedCreator): void
    {
        $policy = $this->currentPolicy();
        $mode = $policy?->mode ?? 'ALL';
        $thresholds = $policy?->thresholds ?? [];

        $kol->load('socialAccounts.latestMetric');

        $socialAccounts = $kol->socialAccounts->map(fn ($sa): array => [
            'platform' => $sa->platform,
            'is_active' => $sa->is_active,
            'followers' => $sa->latestMetric?->followers,
        ])->all();

        $decision = $this->evaluator->evaluate($mode, $thresholds, $socialAccounts, $privilegedCreator);
        $source = $privilegedCreator ? 'privileged_creator' : 'specialist_policy';

        DB::transaction(function () use ($kol, $actor, $policy, $mode, $thresholds, $decision, $source): void {
            $decisionClass = self::DECISION;
            $decisionClass::create([
                'kol_id' => $kol->id,
                'policy_id' => $policy?->id,
                'outcome' => $decision->outcome,
                'source' => $source,
                'actor_user_id' => $actor->getAuthIdentifier(),
                'policy_mode' => $mode,
                'threshold_snapshot' => $thresholds,
                'reason_code' => $decision->reasonCode,
                'reason_text' => null,
                'decided_at' => now(),
            ]);

            $kol->update([
                'approval_status' => $decision->outcome,
                'approval_updated_at' => now(),
            ]);
        });
    }

    public function approveVerifiedCreate(Model $kol, Authenticatable $actor): Model
    {
        return $this->recordAutomaticCreateDecision($kol, $actor, 'approved', 'create_credentials_verified');
    }

    public function markCreateNeedsAttention(Model $kol, Authenticatable $actor, string $reasonCode): Model
    {
        if (! preg_match('/^[a-z][a-z0-9_.-]*$/', $reasonCode)) {
            throw new InvalidArgumentException('Automatic approval reason must be a stable machine-readable key.');
        }

        return $this->recordAutomaticCreateDecision($kol, $actor, 'pending', $reasonCode);
    }

    private function recordAutomaticCreateDecision(Model $kol, Authenticatable $actor, string $outcome, string $reasonCode): Model
    {
        if (! KolScope::canOperate($actor)) {
            throw new AuthorizationException('KOL operation permission is required.');
        }

        $decisionClass = self::DECISION;
        $decision = DB::transaction(function () use ($kol, $actor, $outcome, $reasonCode, $decisionClass): Model {
            $lockedKol = $kol->newQuery()->whereKey($kol->getKey())->lockForUpdate()->firstOrFail();
            $prior = $decisionClass::query()->where('kol_id', $lockedKol->getKey())->orderByDesc('id')->first();
            $created = $decisionClass::create([
                'kol_id' => $lockedKol->getKey(),
                'policy_id' => $prior?->policy_id,
                'outcome' => $outcome,
                'source' => 'system_auto_provision',
                'actor_user_id' => $actor->getAuthIdentifier(),
                'policy_mode' => $prior?->policy_mode,
                'threshold_snapshot' => $prior?->threshold_snapshot ?? [],
                'reason_code' => $reasonCode,
                'reason_text' => null,
                'decided_at' => now(),
            ]);
            $lockedKol->update(['approval_status' => $outcome, 'approval_updated_at' => now()]);
            return $created;
        });

        $kol->refresh();
        return $decision;
    }

    public function approve(Model $kol, Authenticatable $actor, ?string $reasonText = null): Model
    {
        return $this->recordManualDecision($kol, $actor, 'approved', 'manager_approved', $reasonText);
    }

    public function reject(Model $kol, Authenticatable $actor, ?string $reasonText = null): Model
    {
        return $this->recordManualDecision($kol, $actor, 'rejected', 'manager_rejected', $reasonText);
    }

    private function recordManualDecision(Model $kol, Authenticatable $actor, string $outcome, string $reasonCode, ?string $reasonText): Model
    {
        $this->authorizeManager($actor);
        $decisionClass = self::DECISION;

        $decision = DB::transaction(function () use ($kol, $actor, $outcome, $reasonCode, $reasonText, $decisionClass): Model {
            $lockedKol = $kol->newQuery()->whereKey($kol->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedKol->approval_status !== 'pending') {
                throw new LogicException('Only pending KOLs may be manually approved or rejected.');
            }

            $prior = $decisionClass::query()
                ->where('kol_id', $lockedKol->getKey())
                ->orderByDesc('id')
                ->first();

            $created = $decisionClass::create([
                'kol_id' => $lockedKol->getKey(),
                'policy_id' => $prior?->policy_id,
                'outcome' => $outcome,
                'source' => 'manager_manual',
                'actor_user_id' => $actor->getAuthIdentifier(),
                'policy_mode' => $prior?->policy_mode,
                'threshold_snapshot' => $prior?->threshold_snapshot ?? [],
                'reason_code' => $reasonCode,
                'reason_text' => $reasonText,
                'decided_at' => now(),
            ]);

            $lockedKol->update([
                'approval_status' => $outcome,
                'approval_updated_at' => now(),
            ]);

            return $created;
        });

        $kol->refresh();

        return $decision;
    }

    private function authorizeManager(Authenticatable $actor): void
    {
        if (! KolScope::canManageApprovals($actor)) {
            throw new AuthorizationException('KOL approval management permission is required.');
        }
    }

    /** @param array<string,int> $thresholds
     * @return array<string,int>
     */
    private function normalizePolicyThresholds(string $mode, array $thresholds): array
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Unsupported KOL approval policy mode.');
        }

        if ($mode !== 'THRESHOLD') {
            return [];
        }

        $normalized = [];
        foreach ($thresholds as $platform => $threshold) {
            $key = strtolower(trim((string) $platform));
            if (! in_array($key, self::PLATFORMS, true) || ! is_int($threshold) || $threshold < 0) {
                throw new InvalidArgumentException('Invalid KOL approval threshold configuration.');
            }
            $normalized[$key] = $threshold;
        }
        ksort($normalized);

        return $normalized;
    }
}
