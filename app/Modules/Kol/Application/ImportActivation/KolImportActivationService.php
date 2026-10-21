<?php

namespace App\Modules\Kol\Application\ImportActivation;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Application\KolService;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolImportActivationBatch;
use App\Modules\Kol\Infrastructure\Models\KolImportActivationItem;
use App\Modules\Kol\Infrastructure\Models\KolImportPreviewBatch;
use App\Modules\Kol\Infrastructure\Models\KolImportPreviewCandidate;
use App\Modules\Kol\Infrastructure\Models\KolOwnerCandidateDecision;
use App\Modules\Kol\Infrastructure\Models\KolOwnerSourceMapping;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class KolImportActivationService
{
    private const PLAN_VERSION = 1;
    private const READY = 'ready';
    private const BLOCKED = 'blocked';
    private const EXISTING = 'existing';

    public function __construct(private readonly AuditRecorder $audit, private readonly KolService $kols) {}

    public function workspace(Authenticatable $actor, ?int $previewBatchId = null): array
    {
        $this->authorize($actor);
        $previews = KolImportPreviewBatch::query()->with('reconciliationBatch')->latest('id')->limit(20)->get();
        $preview = $previewBatchId ? $previews->firstWhere('id', $previewBatchId) : $previews->first();
        if (! $preview) {
            return [
                'previews' => [], 'preview' => null, 'plan' => null,
                'live_summary' => $this->emptySummary(), 'blocker_summary' => [],
                'import_enabled' => $this->importEnabled(), 'environment' => app()->environment(),
            ];
        }

        $preview->loadMissing(['reconciliationBatch', 'candidates.existingKol']);
        $projection = $this->project($preview);
        $plan = KolImportActivationBatch::query()
            ->with(['items.effectiveOwner', 'items.createdKol'])
            ->where('import_preview_batch_id', $preview->id)
            ->latest('id')->first();

        return [
            'previews' => $previews->map(fn (KolImportPreviewBatch $row): array => $this->previewOption($row))->values()->all(),
            'preview' => $this->previewOption($preview),
            'plan' => $plan ? $this->planView($plan) : null,
            'live_summary' => $projection['summary'],
            'blocker_summary' => $projection['blocker_summary'],
            'import_enabled' => $this->importEnabled(),
            'environment' => app()->environment(),
        ];
    }

    public function prepare(int $previewBatchId, Authenticatable $actor): KolImportActivationBatch
    {
        $this->authorize($actor);
        $preview = KolImportPreviewBatch::query()
            ->with(['reconciliationBatch', 'candidates.existingKol'])
            ->findOrFail($previewBatchId);
        $projection = $this->project($preview);
        $checksum = $this->planChecksum($projection['items']);

        return DB::transaction(function () use ($preview, $projection, $checksum, $actor): KolImportActivationBatch {
            KolImportActivationBatch::query()
                ->where('import_preview_batch_id', $preview->id)
                ->where('status', 'prepared')
                ->update(['status' => 'superseded']);

            $summary = $projection['summary'];
            $batch = KolImportActivationBatch::create([
                'import_preview_batch_id' => $preview->id,
                'reconciliation_batch_id' => $preview->reconciliation_batch_id,
                'source_checksum' => (string) $preview->reconciliationBatch?->checksum,
                'plan_version' => self::PLAN_VERSION,
                'status' => 'prepared',
                'candidate_count' => $summary['candidate_count'],
                'ready_count' => $summary['ready_count'],
                'blocked_count' => $summary['blocked_count'],
                'existing_count' => $summary['existing_count'],
                'skipped_count' => $summary['skipped_count'],
                'plan_checksum' => $checksum,
                'generated_by' => $actor->getAuthIdentifier(),
                'generated_at' => now(),
            ]);

            foreach ($projection['items'] as $item) {
                $batch->items()->create($item);
            }

            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'kol.import_activation.prepared',
                'kol_import_activation_batch',
                $batch->id,
                null,
                [
                    'preview_batch_id' => (int) $preview->id,
                    'candidate_count' => $summary['candidate_count'],
                    'ready_count' => $summary['ready_count'],
                    'blocked_count' => $summary['blocked_count'],
                    'existing_count' => $summary['existing_count'],
                    'plan_checksum' => $checksum,
                ],
            );

            return $batch->fresh(['items.effectiveOwner', 'items.createdKol']);
        });
    }

    public function apply(KolImportActivationBatch $batch, string $expectedChecksum, int $expectedReadyCount, Authenticatable $actor): KolImportActivationBatch
    {
        $this->authorize($actor);
        if (! $this->importEnabled()) {
            abort(403, 'KOL canonical import is disabled for this environment.');
        }

        return DB::transaction(function () use ($batch, $expectedChecksum, $expectedReadyCount, $actor): KolImportActivationBatch {
            $locked = KolImportActivationBatch::query()
                ->lockForUpdate()
                ->with(['items.effectiveOwner', 'previewBatch.candidates'])
                ->findOrFail($batch->id);

            if ($locked->status === 'applied') {
                return $locked->fresh(['items.effectiveOwner', 'items.createdKol']);
            }
            if (! hash_equals((string) $locked->plan_checksum, $expectedChecksum) || (int) $locked->ready_count !== $expectedReadyCount) {
                abort(422, '导入确认信息已过期，请刷新页面并重新确认。');
            }
            if ($locked->status !== 'prepared') {
                abort(422, '该导入计划不是可执行的 prepared 状态。');
            }
            if ((int) $locked->ready_count < 1) {
                abort(422, '当前计划没有可导入 KOL。');
            }
            if (! $this->sourceStateIsFresh($locked)) {
                abort(422, 'P2/P3 数据在计划生成后发生变化，请重新生成导入计划。');
            }

            $currentIndex = $this->kolIndex();
            $eligibleOwnerIds = $this->eligibleUsers()->pluck('id')->map(fn ($id): int => (int) $id)->all();
            foreach ($locked->items->where('readiness_status', self::READY) as $item) {
                $guard = $this->existingGuard($item->exact_identity ?? [], $currentIndex);
                if ($guard['status'] !== 'none') {
                    abort(422, '导入前身份重检发现现有或冲突 KOL，请刷新计划后再确认。');
                }
                if (! $item->effective_owner_id || ! in_array((int) $item->effective_owner_id, $eligibleOwnerIds, true)) {
                    abort(422, '导入前 Owner 资格已变化，请刷新 Owner 映射与导入计划。');
                }
            }

            $created = 0;
            foreach ($locked->items->where('readiness_status', self::READY) as $item) {
                if ($item->created_kol_id) {
                    continue;
                }
                $data = $item->canonical_proposal ?? [];
                $data['owner_id'] = (int) $item->effective_owner_id;
                $kol = $this->kols->create($data, $item->social_proposal ?? [], $actor);
                $this->addIdentityToIndex($currentIndex, $item->exact_identity ?? [], (int) $kol->id);
                $item->update([
                    'readiness_status' => 'applied',
                    'created_kol_id' => $kol->id,
                    'applied_by' => $actor->getAuthIdentifier(),
                    'applied_at' => now(),
                ]);
                $created++;
            }

            $locked->update([
                'status' => 'applied',
                'applied_by' => $actor->getAuthIdentifier(),
                'applied_at' => now(),
                'failure_message' => null,
            ]);
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'kol.import_activation.applied',
                'kol_import_activation_batch',
                $locked->id,
                ['status' => 'prepared'],
                ['status' => 'applied', 'created_kol_count' => $created, 'plan_checksum' => $locked->plan_checksum],
            );
            return $locked->fresh(['items.effectiveOwner', 'items.createdKol']);
        });
    }

    private function project(KolImportPreviewBatch $preview): array
    {
        $preview->loadMissing(['reconciliationBatch', 'candidates.existingKol']);
        $eligibleUsers = $this->eligibleUsers();
        $eligibleById = $eligibleUsers->keyBy(fn (User $user): int => (int) $user->id);
        $mappings = KolOwnerSourceMapping::query()->get()->keyBy('source_owner_key');
        $decisions = KolOwnerCandidateDecision::query()
            ->where('reconciliation_batch_id', $preview->reconciliation_batch_id)
            ->get()->keyBy('cluster_key');
        $kolIndex = $this->kolIndex();
        $items = [];
        $blockers = [];

        foreach ($preview->candidates as $candidate) {
            $owner = $this->effectiveOwner($candidate, $mappings, $decisions, $eligibleById);
            $guard = $this->existingGuard($candidate->exact_identity ?? [], $kolIndex);
            $reasons = $this->blockReasons($candidate, $owner, $guard);
            $status = $this->readinessStatus($candidate, $guard, $reasons);
            foreach ($reasons as $reason) $blockers[$reason] = ($blockers[$reason] ?? 0) + 1;

            $items[] = [
                'preview_candidate_id' => (int) $candidate->id,
                'cluster_key' => $candidate->cluster_key,
                'readiness_status' => $status,
                'preview_status' => $candidate->preview_status,
                'block_reasons' => $reasons,
                'effective_owner_id' => $owner['user_id'],
                'canonical_proposal' => $this->canonicalProposal($candidate, $owner['user_id']),
                'social_proposal' => $this->socialProposal($candidate),
                'exact_identity' => $candidate->exact_identity ?? [],
                'existing_guard' => $guard,
                'source_owner_evidence' => $owner['evidence'],
                'affiliate_evidence' => ['account_ids' => $candidate->affiliate_account_ids ?? []],
                'history_summary' => $candidate->history_summary ?? [],
            ];
        }

        $summary = [
            'candidate_count' => count($items),
            'ready_count' => count(array_filter($items, fn (array $item): bool => $item['readiness_status'] === self::READY)),
            'blocked_count' => count(array_filter($items, fn (array $item): bool => $item['readiness_status'] === self::BLOCKED)),
            'existing_count' => count(array_filter($items, fn (array $item): bool => $item['readiness_status'] === self::EXISTING)),
            'skipped_count' => (int) $preview->unresolved_source_rows,
        ];

        return ['items' => $items, 'summary' => $summary, 'blocker_summary' => $blockers];
    }

    private function readinessStatus(KolImportPreviewCandidate $candidate, array $guard, array $reasons): string
    {
        if ($candidate->preview_status === 'conflict' || $candidate->existing_kol_status === 'conflict' || $guard['status'] === 'conflict') {
            return self::BLOCKED;
        }
        if ($candidate->preview_status === 'existing_kol' || $candidate->existing_kol_status === 'matched' || $guard['status'] === 'matched') {
            return self::EXISTING;
        }
        return $reasons === [] ? self::READY : self::BLOCKED;
    }

    private function blockReasons(KolImportPreviewCandidate $candidate, array $owner, array $guard): array
    {
        $reasons = [];
        if ($candidate->preview_status === 'conflict' || $candidate->existing_kol_status === 'conflict' || $guard['status'] === 'conflict') {
            $reasons[] = 'identity_conflict';
        }
        if (! $owner['user_id']) {
            $reasons[] = count($this->sourceValues($candidate)) > 1 ? 'owner_decision_required' : 'owner_unresolved';
        }
        if (trim((string) (($candidate->proposed_kol ?? [])['display_name'] ?? '')) === '') {
            $reasons[] = 'missing_display_name';
        }
        if (count($candidate->proposed_social_accounts ?? []) > 10) {
            $reasons[] = 'social_identity_limit';
        }
        return array_values(array_unique($reasons));
    }

    private function effectiveOwner(KolImportPreviewCandidate $candidate, $mappings, $decisions, $eligibleById): array
    {
        $values = $this->sourceValues($candidate);
        $mappedIds = [];
        $mappingEvidence = [];
        foreach ($values as $value) {
            $mapping = $mappings->get($this->ownerKey($value));
            $user = $mapping?->mapped_user_id ? $eligibleById->get((int) $mapping->mapped_user_id) : null;
            $mappedIds[] = $user ? (int) $user->id : null;
            $mappingEvidence[] = ['source_owner' => $value, 'mapped_user_id' => $user ? (int) $user->id : null];
        }

        $decision = $decisions->get($candidate->cluster_key);
        $decisionUser = $decision?->mapped_user_id ? $eligibleById->get((int) $decision->mapped_user_id) : null;
        $decisionMatches = $decision
            ? $this->normalizedOwnerValues($decision->source_owner_values ?? []) === $this->normalizedOwnerValues($values)
            : false;
        $userId = null;
        $resolution = 'unmapped';
        if (count($values) === 1 && count($mappedIds) === 1 && $mappedIds[0]) {
            $userId = $mappedIds[0]; $resolution = 'mapped';
        } elseif (count($values) > 1 && $decisionUser && $decisionMatches) {
            $userId = (int) $decisionUser->id; $resolution = 'decided';
        } elseif (count($values) > 1 && ! in_array(null, $mappedIds, true) && count(array_unique($mappedIds)) === 1) {
            $userId = (int) $mappedIds[0]; $resolution = 'mapped_converged';
        }

        return ['user_id' => $userId, 'evidence' => [
            'source_values' => $values,
            'resolution_status' => $resolution,
            'source_mappings' => $mappingEvidence,
            'decision_id' => $decisionMatches ? $decision?->id : null,
        ]];
    }

    private function canonicalProposal(KolImportPreviewCandidate $candidate, ?int $ownerId): array
    {
        $proposal = $candidate->proposed_kol ?? [];
        $allowed = ['display_name', 'contact_email', 'contact_phone', 'tax_id', 'shipping_recipient_name', 'shipping_country', 'country_code', 'shipping_province_state', 'shipping_city', 'shipping_postal_code', 'shipping_address_line_1'];
        $data = array_intersect_key($proposal, array_flip($allowed));
        $variations = ($candidate->exact_identity ?? [])['variations'] ?? [];
        $variationFields = [
            'country' => ['country_code', 'shipping_country'],
            'phone' => ['contact_phone'],
            'tax_id' => ['tax_id'],
            'shipping_name' => ['shipping_recipient_name'],
            'shipping_state' => ['shipping_province_state'],
            'shipping_city' => ['shipping_city'],
            'shipping_postal' => ['shipping_postal_code'],
            'shipping_address' => ['shipping_address_line_1'],
        ];
        foreach (array_keys($variations) as $variation) {
            foreach ($variationFields[$variation] ?? [] as $field) unset($data[$field]);
        }
        if (count((array) (($candidate->exact_identity ?? [])['emails'] ?? [])) > 1) unset($data['contact_email']);
        $data = array_filter($data, fn ($value): bool => $value !== null && $value !== '');
        $data['display_name'] = trim((string) ($data['display_name'] ?? ''));
        $data['owner_id'] = $ownerId;
        $data['status'] = 'active';
        $data['relationship_stage'] = 'prospect';
        $data['approval_manager_id'] = null;
        $data['finance_owner_id'] = null;
        return $data;
    }

    private function socialProposal(KolImportPreviewCandidate $candidate): array
    {
        $allowedPlatforms = ['instagram','tiktok','youtube','xiaohongshu','weibo','wechat','twitter','facebook','other'];
        $result = [];
        foreach ($candidate->proposed_social_accounts ?? [] as $social) {
            $platform = strtolower(trim((string) ($social['platform'] ?? 'other')));
            if (! in_array($platform, $allowedPlatforms, true)) $platform = 'other';
            $url = $this->normalizeUrl($social['profile_url'] ?? null);
            if ($url === null) continue;
            $result[$url] = [
                'platform' => $platform,
                'handle' => isset($social['handle']) && trim((string) $social['handle']) !== '' ? mb_substr(trim((string) $social['handle']), 0, 255) : null,
                'profile_url' => $url,
                'is_active' => true,
                'followers' => isset($social['followers']) && is_numeric($social['followers']) ? max(0, (int) $social['followers']) : null,
                'raw_followers_value' => isset($social['raw_followers_value']) ? mb_substr((string) $social['raw_followers_value'], 0, 32) : null,
            ];
        }
        ksort($result);
        return array_values($result);
    }

    private function kolIndex(): array
    {
        $index = ['email' => [], 'url' => []];
        foreach (Kol::query()->active()->with(['socialAccounts' => fn ($q) => $q->active()])->get(['id', 'contact_email']) as $kol) {
            $id = (int) $kol->id;
            $email = $this->normalizeEmail($kol->contact_email);
            if ($email) $index['email'][$email][] = $id;
            foreach ($kol->socialAccounts as $social) {
                $url = $this->normalizeUrl($social->profile_url);
                if ($url) $index['url'][$url][] = $id;
            }
        }
        foreach ($index as $type => $values) foreach ($values as $key => $ids) $index[$type][$key] = array_values(array_unique($ids));
        return $index;
    }

    private function existingGuard(array $identity, array $index): array
    {
        $tokens = $identity['tokens'] ?? [];
        if ($tokens === []) {
            foreach ((array) ($identity['emails'] ?? []) as $email) {
                $normalized = $this->normalizeEmail($email);
                if ($normalized) $tokens[] = 'email:'.$normalized;
            }
            foreach ((array) ($identity['profile_urls'] ?? []) as $url) {
                $normalized = $this->normalizeUrl($url);
                if ($normalized) $tokens[] = 'url:'.$normalized;
            }
        }
        $ids = [];
        foreach ($tokens as $token) {
            if (str_starts_with($token, 'email:')) $ids = array_merge($ids, $index['email'][substr($token, 6)] ?? []);
            if (str_starts_with($token, 'url:')) $ids = array_merge($ids, $index['url'][substr($token, 4)] ?? []);
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return ['status' => $ids === [] ? 'none' : (count($ids) === 1 ? 'matched' : 'conflict'), 'kol_ids' => $ids];
    }

    private function addIdentityToIndex(array &$index, array $identity, int $kolId): void
    {
        foreach ((array) ($identity['emails'] ?? []) as $email) {
            $normalized = $this->normalizeEmail($email);
            if ($normalized) $index['email'][$normalized][] = $kolId;
        }
        foreach ((array) ($identity['profile_urls'] ?? []) as $url) {
            $normalized = $this->normalizeUrl($url);
            if ($normalized) $index['url'][$normalized][] = $kolId;
        }
    }

    private function eligibleUsers(): EloquentCollection
    {
        return User::query()->where('is_active', true)->where(function ($query): void {
            $query->whereHas('roles.permissions', fn ($permission): mixed => $permission->where('key', 'kol.view'))
                ->orWhereHas('customPermissions', fn ($permission): mixed => $permission->where('key', 'kol.view'));
        })->orderBy('name')->orderBy('id')->get(['id', 'name', 'email']);
    }

    private function planChecksum(array $items): string
    {
        $payload = array_map(fn (array $item): array => [
            'cluster_key' => $item['cluster_key'],
            'preview_status' => $item['preview_status'],
            'block_reasons' => $item['block_reasons'],
            'effective_owner_id' => $item['effective_owner_id'],
            'canonical_proposal' => $item['canonical_proposal'],
            'social_proposal' => $item['social_proposal'],
            'exact_identity' => $item['exact_identity'],
            'source_owner_evidence' => $item['source_owner_evidence'],
            'affiliate_evidence' => $item['affiliate_evidence'],
            'history_summary' => $item['history_summary'],
        ], $items);
        usort($payload, fn (array $a, array $b): int => strcmp($a['cluster_key'], $b['cluster_key']));
        return hash('sha256', json_encode($this->normalizeForChecksum($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function normalizeForChecksum(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn ($item) => $this->normalizeForChecksum($item), $value);
        ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->normalizeForChecksum($item);
        return $value;
    }

    private function sourceStateIsFresh(KolImportActivationBatch $batch): bool
    {
        $generatedAt = $batch->generated_at;
        $preview = KolImportPreviewBatch::query()->find($batch->import_preview_batch_id);
        if (! $generatedAt || ! $preview || ($preview->updated_at && $preview->updated_at->gt($generatedAt))) return false;

        $candidateUpdated = KolImportPreviewCandidate::query()
            ->where('import_preview_batch_id', $batch->import_preview_batch_id)
            ->max('updated_at');
        if ($candidateUpdated && Carbon::parse($candidateUpdated)->gt($generatedAt)) return false;

        $ownerKeys = [];
        $clusterKeys = [];
        foreach ($batch->items as $item) {
            $clusterKeys[] = $item->cluster_key;
            foreach ((array) (($item->source_owner_evidence ?? [])['source_values'] ?? []) as $value) {
                $ownerKeys[] = $this->ownerKey((string) $value);
            }
        }
        $ownerKeys = array_values(array_unique(array_filter($ownerKeys)));
        $clusterKeys = array_values(array_unique($clusterKeys));
        $mappingUpdated = $ownerKeys === [] ? null : KolOwnerSourceMapping::query()->whereIn('source_owner_key', $ownerKeys)->max('updated_at');
        if ($mappingUpdated && Carbon::parse($mappingUpdated)->gt($generatedAt)) return false;
        $decisionUpdated = $clusterKeys === [] ? null : KolOwnerCandidateDecision::query()
            ->where('reconciliation_batch_id', $batch->reconciliation_batch_id)
            ->whereIn('cluster_key', $clusterKeys)->max('updated_at');
        if ($decisionUpdated && Carbon::parse($decisionUpdated)->gt($generatedAt)) return false;
        return true;
    }

    private function previewOption(KolImportPreviewBatch $preview): array
    {
        $preview->loadMissing('reconciliationBatch');
        return [
            'id' => (int) $preview->id,
            'reconciliation_batch_id' => (int) $preview->reconciliation_batch_id,
            'source_filename' => $preview->reconciliationBatch?->original_filename,
            'source_checksum' => $preview->reconciliationBatch?->checksum,
            'source_rows' => (int) $preview->total_source_rows,
            'candidate_count' => (int) $preview->candidate_count,
            'unresolved_source_rows' => (int) $preview->unresolved_source_rows,
            'created_at' => $preview->created_at?->toIso8601String(),
        ];
    }

    private function planView(KolImportActivationBatch $batch): array
    {
        $batch->loadMissing(['items.effectiveOwner', 'items.createdKol']);
        $blockers = [];
        foreach ($batch->items as $item) {
            foreach ($item->block_reasons ?? [] as $reason) $blockers[$reason] = ($blockers[$reason] ?? 0) + 1;
        }
        return [
            'id' => (int) $batch->id,
            'status' => $batch->status,
            'plan_version' => (int) $batch->plan_version,
            'plan_checksum' => $batch->plan_checksum,
            'candidate_count' => (int) $batch->candidate_count,
            'ready_count' => (int) $batch->ready_count,
            'blocked_count' => (int) $batch->blocked_count,
            'existing_count' => (int) $batch->existing_count,
            'skipped_count' => (int) $batch->skipped_count,
            'generated_at' => $batch->generated_at?->toIso8601String(),
            'applied_at' => $batch->applied_at?->toIso8601String(),
            'blocker_summary' => $blockers,
            'items' => $batch->items->map(fn (KolImportActivationItem $item): array => $this->itemView($item))->values()->all(),
        ];
    }

    private function itemView(KolImportActivationItem $item): array
    {
        $proposal = $item->canonical_proposal ?? [];
        $identity = $item->exact_identity ?? [];
        return [
            'id' => (int) $item->id,
            'cluster_key' => $item->cluster_key,
            'display_name' => (string) ($proposal['display_name'] ?? '未命名候选'),
            'primary_email' => $identity['emails'][0] ?? null,
            'profile_urls' => $identity['profile_urls'] ?? [],
            'preview_status' => $item->preview_status,
            'readiness_status' => $item->readiness_status,
            'block_reasons' => $item->block_reasons ?? [],
            'effective_owner' => $item->effectiveOwner ? ['id' => (int) $item->effectiveOwner->id, 'name' => $item->effectiveOwner->name, 'email' => $item->effectiveOwner->email] : null,
            'canonical_proposal' => $proposal,
            'social_proposal' => $item->social_proposal ?? [],
            'exact_identity' => $identity,
            'existing_guard' => $item->existing_guard ?? [],
            'source_owner_evidence' => $item->source_owner_evidence ?? [],
            'affiliate_evidence' => $item->affiliate_evidence ?? [],
            'history_summary' => $item->history_summary ?? [],
            'created_kol' => $item->createdKol ? ['id' => (int) $item->createdKol->id, 'display_name' => $item->createdKol->display_name] : null,
            'applied_at' => $item->applied_at?->toIso8601String(),
        ];
    }

    private function sourceValues(KolImportPreviewCandidate $candidate): array
    {
        $unique = [];
        foreach ((array) (($candidate->owner_evidence ?? [])['source_values'] ?? []) as $value) {
            $value = trim((string) $value);
            if ($value !== '') $unique[$this->ownerKey($value)] ??= $value;
        }
        $values = array_values($unique); usort($values, 'strcasecmp'); return $values;
    }

    private function normalizedOwnerValues(array $values): array
    {
        $values = array_values(array_unique(array_filter(array_map(fn ($value): string => $this->ownerKey((string) $value), $values))));
        sort($values); return $values;
    }

    private function ownerKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private function normalizeUrl(mixed $value): ?string
    {
        $value = trim((string) $value); if ($value === '') return null;
        $parts = parse_url($value);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return null;
        $path = rtrim($parts['path'] ?? '', '/');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $query = array_key_exists('query', $parts) ? '?'.$parts['query'] : '';
        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).$port.$path.$query;
    }

    private function importEnabled(): bool
    {
        return (bool) config('kol.canonical_import_enabled', false);
    }

    private function emptySummary(): array
    {
        return ['candidate_count' => 0, 'ready_count' => 0, 'blocked_count' => 0, 'existing_count' => 0, 'skipped_count' => 0];
    }

    private function authorize(Authenticatable $actor): void
    {
        if (! $actor->can('system.permissions.manage') || ! $actor->can('kol.operate') || ! $actor->can('kol.affiliates.manage')) {
            throw new AuthorizationException('KOL canonical import requires Administrator KOL authority.');
        }
    }
}
