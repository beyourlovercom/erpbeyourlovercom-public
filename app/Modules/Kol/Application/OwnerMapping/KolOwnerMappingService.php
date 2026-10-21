<?php

namespace App\Modules\Kol\Application\OwnerMapping;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Infrastructure\Models\KolImportPreviewBatch;
use App\Modules\Kol\Infrastructure\Models\KolImportPreviewCandidate;
use App\Modules\Kol\Infrastructure\Models\KolOwnerCandidateDecision;
use App\Modules\Kol\Infrastructure\Models\KolOwnerSourceMapping;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class KolOwnerMappingService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function workspace(Authenticatable $actor, ?int $previewBatchId = null): array
    {
        $this->authorize($actor);
        $eligibleUsers = $this->eligibleUsers();
        $eligibleById = $eligibleUsers->keyBy(fn (User $user): int => (int) $user->id);
        $previews = KolImportPreviewBatch::query()
            ->with('reconciliationBatch')
            ->latest('id')
            ->limit(20)
            ->get();

        $selected = $previewBatchId
            ? $previews->firstWhere('id', $previewBatchId)
            : $previews->first();

        if (! $selected) {
            return [
                'previews' => [],
                'preview' => null,
                'summary' => $this->emptySummary(),
                'source_owners' => [],
                'multi_candidates' => [],
                'eligible_users' => $this->userOptions($eligibleUsers),
            ];
        }

        $selected->loadMissing(['reconciliationBatch', 'candidates']);
        $mappings = KolOwnerSourceMapping::query()->with('mappedUser')->get()->keyBy('source_owner_key');
        $decisions = KolOwnerCandidateDecision::query()
            ->with('mappedUser')
            ->where('reconciliation_batch_id', $selected->reconciliation_batch_id)
            ->get()
            ->keyBy('cluster_key');

        $sourceOwners = [];
        $projected = [];
        foreach ($selected->candidates as $candidate) {
            $values = $this->sourceValues($candidate);
            foreach ($values as $value) {
                $key = $this->ownerKey($value);
                $sourceOwners[$key] ??= [
                    'key' => $key,
                    'value' => $value,
                    'single_candidate_count' => 0,
                    'mention_candidate_count' => 0,
                ];
                $sourceOwners[$key]['mention_candidate_count']++;
                if (count($values) === 1) {
                    $sourceOwners[$key]['single_candidate_count']++;
                }
            }
            $projected[] = $this->projectCandidate($candidate, $values, $mappings, $decisions, $eligibleById);
        }

        foreach ($sourceOwners as $key => &$owner) {
            $mapping = $mappings->get($key);
            $user = $mapping?->mapped_user_id ? $eligibleById->get((int) $mapping->mapped_user_id) : null;
            $owner['mapping'] = $mapping ? [
                'id' => (int) $mapping->id,
                'mapped_user_id' => $mapping->mapped_user_id ? (int) $mapping->mapped_user_id : null,
                'user' => $user ? $this->userOption($user) : null,
                'status' => $user ? 'mapped' : 'invalid_user',
            ] : null;
        }
        unset($owner);

        usort($sourceOwners, fn (array $a, array $b): int => $b['mention_candidate_count'] <=> $a['mention_candidate_count'] ?: strcasecmp($a['value'], $b['value']));
        $resolved = count(array_filter($projected, fn (array $candidate): bool => $candidate['resolved']));
        $multi = array_values(array_filter($projected, fn (array $candidate): bool => count($candidate['source_owner_values']) > 1));
        $decisionRequired = count(array_filter($multi, fn (array $candidate): bool => ! $candidate['resolved']));

        return [
            'previews' => $previews->map(fn (KolImportPreviewBatch $preview): array => [
                'id' => (int) $preview->id,
                'reconciliation_batch_id' => (int) $preview->reconciliation_batch_id,
                'source_filename' => $preview->reconciliationBatch?->original_filename,
                'candidate_count' => (int) $preview->candidate_count,
                'created_at' => $preview->created_at?->toIso8601String(),
            ])->values()->all(),
            'preview' => [
                'id' => (int) $selected->id,
                'reconciliation_batch_id' => (int) $selected->reconciliation_batch_id,
                'source_filename' => $selected->reconciliationBatch?->original_filename,
                'candidate_count' => (int) $selected->candidate_count,
                'unresolved_source_rows' => (int) $selected->unresolved_source_rows,
                'created_at' => $selected->created_at?->toIso8601String(),
            ],
            'summary' => [
                'total_candidates' => count($projected),
                'resolved_candidates' => $resolved,
                'unresolved_candidates' => count($projected) - $resolved,
                'multi_owner_candidates' => count($multi),
                'decision_required' => $decisionRequired,
                'source_owner_count' => count($sourceOwners),
            ],
            'source_owners' => array_values($sourceOwners),
            'multi_candidates' => $multi,
            'eligible_users' => $this->userOptions($eligibleUsers),
        ];
    }

    public function saveSourceMapping(int $previewBatchId, string $sourceOwnerValue, int $mappedUserId, Authenticatable $actor): array
    {
        $this->authorize($actor);
        $preview = KolImportPreviewBatch::query()->with('candidates')->findOrFail($previewBatchId);
        $sourceOwnerValue = trim($sourceOwnerValue);
        if ($sourceOwnerValue === '' || ! $this->previewHasSourceOwner($preview, $sourceOwnerValue)) {
            throw ValidationException::withMessages(['source_owner_value' => '该来源负责人不属于当前 P2 预览。']);
        }
        $user = $this->eligibleUser($mappedUserId);
        $key = $this->ownerKey($sourceOwnerValue);

        DB::transaction(function () use ($key, $sourceOwnerValue, $user, $actor): void {
            $mapping = KolOwnerSourceMapping::query()->where('source_owner_key', $key)->lockForUpdate()->first();
            $before = $mapping ? $this->mappingAudit($mapping) : null;
            if (! $mapping) {
                $mapping = new KolOwnerSourceMapping([
                    'source_owner_key' => $key,
                    'created_by' => $actor->getAuthIdentifier(),
                ]);
            }
            $mapping->fill([
                'source_owner_value' => $sourceOwnerValue,
                'mapped_user_id' => $user->id,
                'updated_by' => $actor->getAuthIdentifier(),
            ])->save();
            $after = $this->mappingAudit($mapping);
            if ($before !== $after) {
                $this->audit->record(
                    AuditActor::user($actor, AuditSource::Web),
                    $before ? 'kol.owner_mapping.updated' : 'kol.owner_mapping.created',
                    'kol_owner_source_mapping',
                    $mapping->id,
                    $before,
                    $after,
                );
            }
        });

        return $this->workspace($actor, $previewBatchId);
    }

    public function deleteSourceMapping(KolOwnerSourceMapping $mapping, int $previewBatchId, Authenticatable $actor): array
    {
        $this->authorize($actor);
        $before = $this->mappingAudit($mapping);
        $id = (int) $mapping->id;
        $mapping->delete();
        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            'kol.owner_mapping.deleted',
            'kol_owner_source_mapping',
            $id,
            $before,
            null,
        );
        return $this->workspace($actor, $previewBatchId);
    }

    public function saveCandidateDecision(int $previewBatchId, string $clusterKey, int $mappedUserId, ?string $reason, Authenticatable $actor): array
    {
        $this->authorize($actor);
        $preview = KolImportPreviewBatch::query()->findOrFail($previewBatchId);
        $candidate = KolImportPreviewCandidate::query()
            ->where('import_preview_batch_id', $preview->id)
            ->where('cluster_key', $clusterKey)
            ->firstOrFail();
        $sourceValues = $this->sourceValues($candidate);
        if (count($sourceValues) < 2) {
            throw ValidationException::withMessages(['cluster_key' => '候选级 Owner 裁决只允许用于多来源负责人候选。']);
        }
        $user = $this->eligibleUser($mappedUserId);
        $reason = trim((string) $reason);
        $reason = $reason === '' ? null : mb_substr($reason, 0, 500);

        DB::transaction(function () use ($preview, $candidate, $sourceValues, $user, $reason, $actor): void {
            $decision = KolOwnerCandidateDecision::query()
                ->where('reconciliation_batch_id', $preview->reconciliation_batch_id)
                ->where('cluster_key', $candidate->cluster_key)
                ->lockForUpdate()
                ->first();
            $before = $decision ? $this->decisionAudit($decision) : null;
            if (! $decision) {
                $decision = new KolOwnerCandidateDecision([
                    'reconciliation_batch_id' => $preview->reconciliation_batch_id,
                    'cluster_key' => $candidate->cluster_key,
                ]);
            }
            $decision->fill([
                'mapped_user_id' => $user->id,
                'source_owner_values' => $sourceValues,
                'reason' => $reason,
                'decided_by' => $actor->getAuthIdentifier(),
            ])->save();
            $after = $this->decisionAudit($decision);
            if ($before !== $after) {
                $this->audit->record(
                    AuditActor::user($actor, AuditSource::Web),
                    $before ? 'kol.owner_decision.updated' : 'kol.owner_decision.created',
                    'kol_owner_candidate_decision',
                    $decision->id,
                    $before,
                    $after,
                );
            }
        });

        return $this->workspace($actor, $previewBatchId);
    }

    public function deleteCandidateDecision(KolOwnerCandidateDecision $decision, int $previewBatchId, Authenticatable $actor): array
    {
        $this->authorize($actor);
        $before = $this->decisionAudit($decision);
        $id = (int) $decision->id;
        $decision->delete();
        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            'kol.owner_decision.deleted',
            'kol_owner_candidate_decision',
            $id,
            $before,
            null,
        );
        return $this->workspace($actor, $previewBatchId);
    }

    private function projectCandidate(KolImportPreviewCandidate $candidate, array $sourceValues, $mappings, $decisions, $eligibleById): array
    {
        $mappingRows = [];
        $mappedUserIds = [];
        $allMapped = $sourceValues !== [];
        foreach ($sourceValues as $value) {
            $key = $this->ownerKey($value);
            $mapping = $mappings->get($key);
            $user = $mapping?->mapped_user_id ? $eligibleById->get((int) $mapping->mapped_user_id) : null;
            if (! $user) {
                $allMapped = false;
            } else {
                $mappedUserIds[] = (int) $user->id;
            }
            $mappingRows[] = [
                'source_owner' => $value,
                'mapping_id' => $mapping ? (int) $mapping->id : null,
                'user' => $user ? $this->userOption($user) : null,
            ];
        }

        $decision = $decisions->get($candidate->cluster_key);
        $decisionUser = $decision?->mapped_user_id ? $eligibleById->get((int) $decision->mapped_user_id) : null;
        $decisionSnapshotMatches = $decision
            ? $this->normalizedOwnerValues($decision->source_owner_values ?? []) === $this->normalizedOwnerValues($sourceValues)
            : false;

        $status = 'unmapped';
        $effectiveUser = null;
        if ($sourceValues === []) {
            $status = 'no_source_owner';
        } elseif (count($sourceValues) === 1) {
            if ($allMapped && count($mappedUserIds) === 1) {
                $status = 'mapped';
                $effectiveUser = $eligibleById->get($mappedUserIds[0]);
            }
        } elseif ($decision && $decisionUser && $decisionSnapshotMatches) {
            $status = 'decided';
            $effectiveUser = $decisionUser;
        } elseif ($allMapped && count(array_unique($mappedUserIds)) === 1) {
            $status = 'mapped_converged';
            $effectiveUser = $eligibleById->get($mappedUserIds[0]);
        } else {
            $status = 'needs_decision';
        }

        $identity = $candidate->exact_identity ?? [];
        $proposed = $candidate->proposed_kol ?? [];
        $socials = $candidate->proposed_social_accounts ?? [];
        return [
            'id' => (int) $candidate->id,
            'cluster_key' => $candidate->cluster_key,
            'display_name' => $proposed['display_name'] ?? (($identity['emails'][0] ?? null) ? explode('@', $identity['emails'][0])[0] : '未命名候选'),
            'primary_email' => $identity['emails'][0] ?? null,
            'social_accounts' => $socials,
            'source_row_count' => (int) $candidate->source_row_count,
            'source_owner_values' => $sourceValues,
            'source_mappings' => $mappingRows,
            'resolution_status' => $status,
            'resolved' => in_array($status, ['mapped', 'mapped_converged', 'decided'], true),
            'effective_owner' => $effectiveUser ? $this->userOption($effectiveUser) : null,
            'decision' => $decision ? [
                'id' => (int) $decision->id,
                'mapped_user_id' => $decision->mapped_user_id ? (int) $decision->mapped_user_id : null,
                'user' => $decisionUser ? $this->userOption($decisionUser) : null,
                'reason' => $decision->reason,
                'snapshot_matches' => $decisionSnapshotMatches,
            ] : null,
            'affiliate_account_ids' => $candidate->affiliate_account_ids ?? [],
        ];
    }

    private function previewHasSourceOwner(KolImportPreviewBatch $preview, string $needle): bool
    {
        $key = $this->ownerKey($needle);
        foreach ($preview->candidates as $candidate) {
            foreach ($this->sourceValues($candidate) as $value) {
                if ($this->ownerKey($value) === $key) return true;
            }
        }
        return false;
    }

    private function sourceValues(KolImportPreviewCandidate $candidate): array
    {
        $values = $candidate->owner_evidence['source_values'] ?? [];
        $unique = [];
        foreach ((array) $values as $value) {
            $value = trim((string) $value);
            if ($value === '') continue;
            $unique[$this->ownerKey($value)] ??= $value;
        }
        $values = array_values($unique);
        usort($values, 'strcasecmp');
        return $values;
    }

    private function normalizedOwnerValues(array $values): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(fn ($value): string => $this->ownerKey((string) $value), $values))));
        sort($normalized);
        return $normalized;
    }

    private function ownerKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function eligibleUsers(): EloquentCollection
    {
        return User::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereHas('roles.permissions', fn ($permission): mixed => $permission->where('key', 'kol.view'))
                    ->orWhereHas('customPermissions', fn ($permission): mixed => $permission->where('key', 'kol.view'));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'email']);
    }

    private function eligibleUser(int $userId): User
    {
        $user = $this->eligibleUsers()->firstWhere('id', $userId);
        if (! $user) {
            throw ValidationException::withMessages(['mapped_user_id' => '所选 ERP 用户当前不可作为 KOL Owner。']);
        }
        return $user;
    }

    private function userOptions(EloquentCollection $users): array
    {
        return $users->map(fn (User $user): array => $this->userOption($user))->values()->all();
    }

    private function userOption(User $user): array
    {
        return ['id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email];
    }

    private function mappingAudit(KolOwnerSourceMapping $mapping): array
    {
        return [
            'source_owner_key' => $mapping->source_owner_key,
            'source_owner_value' => $mapping->source_owner_value,
            'mapped_user_id' => $mapping->mapped_user_id ? (int) $mapping->mapped_user_id : null,
        ];
    }

    private function decisionAudit(KolOwnerCandidateDecision $decision): array
    {
        return [
            'reconciliation_batch_id' => (int) $decision->reconciliation_batch_id,
            'cluster_key' => $decision->cluster_key,
            'mapped_user_id' => $decision->mapped_user_id ? (int) $decision->mapped_user_id : null,
            'source_owner_values' => $decision->source_owner_values ?? [],
            'reason' => $decision->reason,
        ];
    }

    private function emptySummary(): array
    {
        return ['total_candidates' => 0, 'resolved_candidates' => 0, 'unresolved_candidates' => 0, 'multi_owner_candidates' => 0, 'decision_required' => 0, 'source_owner_count' => 0];
    }

    private function authorize(Authenticatable $actor): void
    {
        if (! $actor->can('kol.approvals.manage') || ! $actor->can('kol.affiliates.manage')) {
            throw new AuthorizationException('KOL Owner mapping requires Manager/Admin authority.');
        }
    }
}
