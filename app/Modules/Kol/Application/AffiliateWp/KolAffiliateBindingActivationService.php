<?php

namespace App\Modules\Kol\Application\AffiliateWp;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateBindingActivationBatch;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateBindingActivationItem;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateReferral;
use App\Modules\Kol\Infrastructure\Models\KolImportActivationBatch;
use App\Modules\Kol\Infrastructure\Models\KolImportActivationItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class KolAffiliateBindingActivationService
{
    public const PRODUCTION_CONFIRMATION = 'APPLY_EXACT_KOL_AFFILIATE_BINDINGS';

    private const PLAN_VERSION = 1;
    private const READY = 'ready_for_binding';
    private const ALREADY_BOUND = 'already_bound';
    private const NO_AFFILIATE = 'no_affiliate';
    private const BLOCKED = 'blocked';

    public function __construct(
        private readonly AffiliateBindingService $binding,
        private readonly AuditRecorder $audit,
    ) {}

    public function workspace(Authenticatable $actor, ?int $activationBatchId = null): array
    {
        $this->authorize($actor);
        $activations = KolImportActivationBatch::query()
            ->with('reconciliationBatch')
            ->where('status', 'applied')
            ->latest('id')->limit(20)->get();
        $activation = $activationBatchId ? $activations->firstWhere('id', $activationBatchId) : $activations->first();
        if (! $activation) {
            return [
                'activations' => [], 'activation' => null, 'plan' => null,
                'live_summary' => $this->emptySummary(), 'blocker_summary' => [],
                'binding_enabled' => $this->bindingEnabled(), 'environment' => app()->environment(),
            ];
        }

        $projection = $this->project($activation);
        $plan = KolAffiliateBindingActivationBatch::query()
            ->with(['items.kol', 'items.affiliateAccount'])
            ->where('import_activation_batch_id', $activation->id)
            ->latest('id')->first();

        return [
            'activations' => $activations->map(fn (KolImportActivationBatch $row): array => $this->activationOption($row))->values()->all(),
            'activation' => $this->activationOption($activation),
            'plan' => $plan ? $this->planView($plan) : null,
            'live_summary' => $projection['summary'],
            'blocker_summary' => $projection['blocker_summary'],
            'binding_enabled' => $this->bindingEnabled(),
            'environment' => app()->environment(),
        ];
    }

    public function prepare(int $activationBatchId, Authenticatable $actor): KolAffiliateBindingActivationBatch
    {
        $this->authorize($actor);
        $activation = KolImportActivationBatch::query()
            ->with(['reconciliationBatch', 'items.createdKol'])
            ->findOrFail($activationBatchId);
        if ($activation->status !== 'applied') {
            abort(422, '只有已完成 P4 Canonical Import 的批次才能生成 Affiliate 绑定计划。');
        }

        $projection = $this->project($activation);
        $checksum = $this->planChecksum($projection['items']);

        return DB::transaction(function () use ($activation, $projection, $checksum, $actor): KolAffiliateBindingActivationBatch {
            KolAffiliateBindingActivationBatch::query()
                ->where('import_activation_batch_id', $activation->id)
                ->where('status', 'prepared')
                ->update(['status' => 'superseded']);

            $summary = $projection['summary'];
            $batch = KolAffiliateBindingActivationBatch::create([
                'import_activation_batch_id' => $activation->id,
                'reconciliation_batch_id' => $activation->reconciliation_batch_id,
                'connection_key' => $projection['connection_key'],
                'source_checksum' => $activation->source_checksum,
                'plan_version' => self::PLAN_VERSION,
                'status' => 'prepared',
                'eligible_kol_count' => $summary['eligible_kol_count'],
                'ready_count' => $summary['ready_count'],
                'already_bound_count' => $summary['already_bound_count'],
                'no_affiliate_count' => $summary['no_affiliate_count'],
                'blocked_count' => $summary['blocked_count'],
                'plan_checksum' => $checksum,
                'generated_by' => $actor->getAuthIdentifier(),
                'generated_at' => now(),
            ]);
            foreach ($projection['items'] as $item) $batch->items()->create($item);

            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'kol.affiliate_binding_activation.prepared',
                'kol_affiliate_binding_activation_batch',
                $batch->id,
                null,
                [
                    'import_activation_batch_id' => (int) $activation->id,
                    'connection_key' => $projection['connection_key'],
                    'ready_count' => $summary['ready_count'],
                    'blocked_count' => $summary['blocked_count'],
                    'plan_checksum' => $checksum,
                ],
            );

            return $batch->fresh(['items.kol', 'items.affiliateAccount']);
        });
    }

    public function apply(
        KolAffiliateBindingActivationBatch $batch,
        string $expectedChecksum,
        int $expectedReadyCount,
        Authenticatable $actor,
    ): KolAffiliateBindingActivationBatch {
        $this->authorize($actor);
        if (! $this->bindingEnabled()) abort(403, 'KOL Affiliate binding activation is disabled for this environment.');

        return $this->applyAuthorized($batch, $expectedChecksum, $expectedReadyCount, $actor, 'web');
    }

    public function applyProductionAuthorized(
        KolAffiliateBindingActivationBatch $batch,
        string $expectedChecksum,
        int $expectedReadyCount,
        Authenticatable $actor,
        string $confirmation,
    ): KolAffiliateBindingActivationBatch {
        $this->authorize($actor);
        if (! app()->environment('production') || ! app()->runningInConsole()) {
            abort(403, 'Production Affiliate binding authorization is available only from the Production console.');
        }
        if (! hash_equals(self::PRODUCTION_CONFIRMATION, $confirmation)) {
            abort(403, 'Exact Production Affiliate binding confirmation is required.');
        }

        return $this->applyAuthorized($batch, $expectedChecksum, $expectedReadyCount, $actor, 'production_console');
    }

    private function applyAuthorized(
        KolAffiliateBindingActivationBatch $batch,
        string $expectedChecksum,
        int $expectedReadyCount,
        Authenticatable $actor,
        string $executionSurface,
    ): KolAffiliateBindingActivationBatch {
        return DB::transaction(function () use ($batch, $expectedChecksum, $expectedReadyCount, $actor, $executionSurface): KolAffiliateBindingActivationBatch {
            $locked = KolAffiliateBindingActivationBatch::query()
                ->lockForUpdate()
                ->with(['items', 'importActivationBatch.reconciliationBatch', 'importActivationBatch.items.createdKol'])
                ->findOrFail($batch->id);

            if ($locked->status === 'applied') return $locked->fresh(['items.kol', 'items.affiliateAccount']);
            if (! hash_equals((string) $locked->plan_checksum, $expectedChecksum) || (int) $locked->ready_count !== $expectedReadyCount) {
                abort(422, '绑定确认信息已过期，请刷新页面并重新确认。');
            }
            if ($locked->status !== 'prepared') abort(422, '该绑定计划不是可执行的 prepared 状态。');
            if ((int) $locked->ready_count < 1) abort(422, '当前计划没有可执行的 Affiliate 绑定。');
            if (! $this->sourceStateIsFresh($locked)) abort(422, 'P4 数据在绑定计划生成后发生变化，请重新生成计划。');

            $current = $this->project($locked->importActivationBatch);
            $currentByCluster = collect($current['items'])->keyBy('cluster_key');
            foreach ($locked->items->where('readiness_status', self::READY) as $item) {
                $now = $currentByCluster->get($item->cluster_key);
                if (! $now || $now['readiness_status'] !== self::READY
                    || (int) $now['canonical_kol_id'] !== (int) $item->canonical_kol_id
                    || (int) $now['affiliate_account_id'] !== (int) $item->affiliate_account_id
                    || ! hash_equals((string) $now['referral_provider_checksum'], (string) $item->referral_provider_checksum)) {
                    abort(422, '绑定前精确状态重检发现数据漂移，请重新生成绑定计划。');
                }
            }

            $applied = 0;
            foreach ($locked->items->where('readiness_status', self::READY) as $item) {
                if ($item->applied_at) continue;
                $account = KolAffiliateAccount::query()->whereKey($item->affiliate_account_id)->first();
                if (! $account || $account->affiliate_id === null) abort(422, '绑定前 Affiliate mirror 已变化。');
                $beforeChecksum = $this->referralProviderState($locked->connection_key, (int) $account->affiliate_id)['checksum'];
                $this->binding->bind($locked->connection_key, (int) $account->id, (int) $item->canonical_kol_id, $actor);
                $account->refresh();
                if ((int) $account->kol_id !== (int) $item->canonical_kol_id) abort(422, 'Affiliate 绑定结果校验失败。');

                $after = $this->referralProviderState($locked->connection_key, (int) $account->affiliate_id);
                if (! hash_equals((string) $beforeChecksum, (string) $after['checksum'])) abort(422, 'Referral Provider 事实发生非预期变化，整批已回滚。');
                $unresolved = KolAffiliateReferral::query()
                    ->where('connection_key', $locked->connection_key)
                    ->where('affiliate_id', $account->affiliate_id)
                    ->where(function ($q) use ($item): void {
                        $q->where('kol_id', '!=', $item->canonical_kol_id)->orWhereNull('kol_id')
                            ->orWhere('affiliate_resolution_status', '!=', 'bound');
                    })->exists();
                if ($unresolved) abort(422, 'Referral 本地重解析校验失败，整批已回滚。');

                $item->update([
                    'readiness_status' => 'applied',
                    'applied_by' => $actor->getAuthIdentifier(),
                    'applied_at' => now(),
                ]);
                $applied++;
            }

            $locked->update([
                'status' => 'applied',
                'applied_by' => $actor->getAuthIdentifier(),
                'applied_at' => now(),
                'failure_message' => null,
            ]);
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'kol.affiliate_binding_activation.applied',
                'kol_affiliate_binding_activation_batch',
                $locked->id,
                ['status' => 'prepared'],
                ['status' => 'applied', 'binding_count' => $applied, 'plan_checksum' => $locked->plan_checksum, 'execution_surface' => $executionSurface],
            );
            return $locked->fresh(['items.kol', 'items.affiliateAccount']);
        });
    }

    private function project(KolImportActivationBatch $activation): array
    {
        $activation->loadMissing(['reconciliationBatch', 'items.createdKol']);
        $connectionKey = (string) ($activation->reconciliationBatch?->connection_key ?? '');
        $accounts = KolAffiliateAccount::query()->where('connection_key', $connectionKey)->get();
        $affiliateEmailIndex = [];
        foreach ($accounts as $account) {
            foreach ([$account->email, $account->wordpress_user_email] as $value) {
                $email = $this->normalizeEmail($value);
                if ($email) $affiliateEmailIndex[$email][] = (int) $account->id;
            }
        }
        foreach ($affiliateEmailIndex as $email => $ids) $affiliateEmailIndex[$email] = array_values(array_unique($ids));
        $accountsById = $accounts->keyBy('id');
        $kolIndex = $this->kolIndex();
        $items = [];
        $blockers = [];

        foreach ($activation->items as $sourceItem) {
            $canonicalKolId = $this->canonicalKolId($sourceItem);
            $kol = $canonicalKolId ? Kol::query()->whereKey($canonicalKolId)->whereNull('archived_at')->first() : null;
            $reasons = [];
            if (! $kol) $reasons[] = 'missing_canonical_kol';

            $guard = $this->kolIdentityGuard($sourceItem->exact_identity ?? [], $kolIndex);
            if ($kol && ($guard['status'] !== 'matched' || $guard['kol_ids'] !== [(int) $kol->id])) $reasons[] = 'stale_kol_identity';

            $candidateIds = $this->affiliateCandidateIds($sourceItem->exact_identity ?? [], $affiliateEmailIndex);
            $storedIds = array_values(array_unique(array_map('intval', (array) (($sourceItem->affiliate_evidence ?? [])['account_ids'] ?? []))));
            sort($storedIds); sort($candidateIds);
            if ($storedIds !== $candidateIds) $reasons[] = 'stale_affiliate_evidence';

            $account = count($candidateIds) === 1 ? $accountsById->get($candidateIds[0]) : null;
            if (count($candidateIds) > 1) $reasons[] = 'multiple_affiliates';
            if ($account && $kol) {
                if ($account->kol_id !== null && (int) $account->kol_id !== (int) $kol->id) $reasons[] = 'affiliate_bound_elsewhere';
                $kolOtherAccount = $accounts->first(fn (KolAffiliateAccount $row): bool => (int) ($row->kol_id ?? 0) === (int) $kol->id && (int) $row->id !== (int) $account->id);
                if ($account->kol_id === null && $kolOtherAccount) $reasons[] = 'kol_bound_elsewhere';
            }
            $reasons = array_values(array_unique($reasons));

            $status = self::BLOCKED;
            if ($reasons === []) {
                if ($candidateIds === []) $status = self::NO_AFFILIATE;
                elseif ($account && $kol && (int) $account->kol_id === (int) $kol->id) $status = self::ALREADY_BOUND;
                elseif ($account && $kol && $account->kol_id === null) $status = self::READY;
            }
            foreach ($reasons as $reason) $blockers[$reason] = ($blockers[$reason] ?? 0) + 1;

            $referralState = $account && $account->affiliate_id !== null
                ? $this->referralProviderState($connectionKey, (int) $account->affiliate_id)
                : ['count' => 0, 'checksum' => null];
            $items[] = [
                'import_activation_item_id' => (int) $sourceItem->id,
                'cluster_key' => $sourceItem->cluster_key,
                'canonical_kol_id' => $kol?->id,
                'affiliate_account_id' => $account?->id,
                'readiness_status' => $status,
                'block_reasons' => $reasons,
                'exact_identity' => $sourceItem->exact_identity ?? [],
                'affiliate_snapshot' => $account ? $this->accountSnapshot($account) : [
                    'candidate_account_ids' => $candidateIds,
                    'p4_evidence_account_ids' => $storedIds,
                ],
                'referral_impact_count' => $referralState['count'],
                'referral_provider_checksum' => $referralState['checksum'],
            ];
        }

        $eligible = count(array_filter($items, fn (array $item): bool => $item['canonical_kol_id'] !== null));
        return [
            'connection_key' => $connectionKey,
            'items' => $items,
            'summary' => [
                'eligible_kol_count' => $eligible,
                'ready_count' => count(array_filter($items, fn (array $item): bool => $item['readiness_status'] === self::READY)),
                'already_bound_count' => count(array_filter($items, fn (array $item): bool => $item['readiness_status'] === self::ALREADY_BOUND)),
                'no_affiliate_count' => count(array_filter($items, fn (array $item): bool => $item['readiness_status'] === self::NO_AFFILIATE)),
                'blocked_count' => count(array_filter($items, fn (array $item): bool => $item['readiness_status'] === self::BLOCKED)),
            ],
            'blocker_summary' => $blockers,
        ];
    }

    private function canonicalKolId(KolImportActivationItem $item): ?int
    {
        if ($item->created_kol_id) return (int) $item->created_kol_id;
        $guard = $item->existing_guard ?? [];
        $ids = array_values(array_unique(array_map('intval', (array) ($guard['kol_ids'] ?? []))));
        return ($guard['status'] ?? null) === 'matched' && count($ids) === 1 ? $ids[0] : null;
    }

    private function affiliateCandidateIds(array $identity, array $index): array
    {
        $ids = [];
        foreach ((array) ($identity['emails'] ?? []) as $value) {
            $email = $this->normalizeEmail($value);
            if ($email) $ids = array_merge($ids, $index[$email] ?? []);
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return $ids;
    }

    private function kolIndex(): array
    {
        $index = ['email' => [], 'url' => []];
        foreach (Kol::query()->whereNull('archived_at')->with(['socialAccounts' => fn ($q) => $q->active()])->get(['id', 'contact_email']) as $kol) {
            $email = $this->normalizeEmail($kol->contact_email);
            if ($email) $index['email'][$email][] = (int) $kol->id;
            foreach ($kol->socialAccounts as $social) {
                $url = $this->normalizeUrl($social->profile_url);
                if ($url) $index['url'][$url][] = (int) $kol->id;
            }
        }
        foreach ($index as $type => $values) foreach ($values as $key => $ids) {
            $index[$type][$key] = array_values(array_unique(array_map('intval', $ids)));
            sort($index[$type][$key]);
        }
        return $index;
    }

    private function kolIdentityGuard(array $identity, array $index): array
    {
        $ids = [];
        foreach ((array) ($identity['emails'] ?? []) as $value) {
            $email = $this->normalizeEmail($value);
            if ($email) $ids = array_merge($ids, $index['email'][$email] ?? []);
        }
        foreach ((array) ($identity['profile_urls'] ?? []) as $value) {
            $url = $this->normalizeUrl($value);
            if ($url) $ids = array_merge($ids, $index['url'][$url] ?? []);
        }
        $ids = array_values(array_unique(array_map('intval', $ids))); sort($ids);
        return ['status' => $ids === [] ? 'none' : (count($ids) === 1 ? 'matched' : 'conflict'), 'kol_ids' => $ids];
    }

    private function accountSnapshot(KolAffiliateAccount $account): array
    {
        return [
            'id' => (int) $account->id,
            'affiliate_id' => $account->affiliate_id !== null ? (int) $account->affiliate_id : null,
            'display_name' => $account->display_name,
            'email' => $account->email,
            'wordpress_user_email' => $account->wordpress_user_email,
            'provider_status' => $account->provider_status,
            'origin' => $account->origin,
            'kol_id' => $account->kol_id !== null ? (int) $account->kol_id : null,
            'reconciliation_status' => $account->reconciliation_status,
        ];
    }

    private function referralProviderState(string $connectionKey, int $affiliateId): array
    {
        $rows = KolAffiliateReferral::query()
            ->where('connection_key', $connectionKey)
            ->where('affiliate_id', $affiliateId)
            ->orderBy('referral_id')->orderBy('id')->get();
        $facts = $rows->map(fn (KolAffiliateReferral $row): array => [
            'referral_id' => $row->referral_id,
            'affiliate_id' => $row->affiliate_id,
            'order_id' => $row->order_id,
            'order_reference' => $row->order_reference,
            'description' => $row->description,
            'commission_amount' => (string) $row->commission_amount,
            'currency' => $row->currency,
            'provider_status' => $row->provider_status,
            'context' => $row->context,
            'referral_date' => $row->referral_date?->toIso8601String(),
            'payout_id' => $row->payout_id,
            'order_resolution_status' => $row->order_resolution_status,
            'reconciliation_status' => $row->reconciliation_status,
            'sync_status' => $row->sync_status,
            'last_synced_at' => $row->last_synced_at?->toIso8601String(),
            'last_successful_sync_at' => $row->last_successful_sync_at?->toIso8601String(),
        ])->values()->all();
        return [
            'count' => count($facts),
            'checksum' => hash('sha256', json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }

    private function planChecksum(array $items): string
    {
        $payload = array_map(fn (array $item): array => [
            'cluster_key' => $item['cluster_key'],
            'canonical_kol_id' => $item['canonical_kol_id'],
            'affiliate_account_id' => $item['affiliate_account_id'],
            'readiness_status' => $item['readiness_status'],
            'block_reasons' => $item['block_reasons'],
            'exact_identity' => $item['exact_identity'],
            'affiliate_snapshot' => $item['affiliate_snapshot'],
            'referral_impact_count' => $item['referral_impact_count'],
            'referral_provider_checksum' => $item['referral_provider_checksum'],
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

    private function sourceStateIsFresh(KolAffiliateBindingActivationBatch $batch): bool
    {
        $generatedAt = $batch->generated_at;
        $activation = KolImportActivationBatch::query()->find($batch->import_activation_batch_id);
        if (! $generatedAt || ! $activation || $activation->status !== 'applied' || ($activation->updated_at && $activation->updated_at->gt($generatedAt))) return false;
        $itemUpdated = KolImportActivationItem::query()->where('activation_batch_id', $activation->id)->max('updated_at');
        return ! $itemUpdated || ! Carbon::parse($itemUpdated)->gt($generatedAt);
    }

    private function activationOption(KolImportActivationBatch $activation): array
    {
        $activation->loadMissing('reconciliationBatch');
        return [
            'id' => (int) $activation->id,
            'reconciliation_batch_id' => (int) $activation->reconciliation_batch_id,
            'import_preview_batch_id' => (int) $activation->import_preview_batch_id,
            'source_filename' => $activation->reconciliationBatch?->original_filename,
            'source_checksum' => $activation->source_checksum,
            'connection_key' => $activation->reconciliationBatch?->connection_key,
            'candidate_count' => (int) $activation->candidate_count,
            'created_count' => (int) $activation->items()->whereNotNull('created_kol_id')->count(),
            'status' => $activation->status,
            'applied_at' => $activation->applied_at?->toIso8601String(),
        ];
    }

    private function planView(KolAffiliateBindingActivationBatch $batch): array
    {
        $batch->loadMissing(['items.kol', 'items.affiliateAccount']);
        return [
            'id' => (int) $batch->id,
            'status' => $batch->status,
            'plan_version' => (int) $batch->plan_version,
            'plan_checksum' => $batch->plan_checksum,
            'eligible_kol_count' => (int) $batch->eligible_kol_count,
            'ready_count' => (int) $batch->ready_count,
            'already_bound_count' => (int) $batch->already_bound_count,
            'no_affiliate_count' => (int) $batch->no_affiliate_count,
            'blocked_count' => (int) $batch->blocked_count,
            'generated_at' => $batch->generated_at?->toIso8601String(),
            'applied_at' => $batch->applied_at?->toIso8601String(),
            'blocker_summary' => $this->blockerSummaryFromItems($batch->items),
            'items' => $batch->items->map(fn (KolAffiliateBindingActivationItem $item): array => $this->itemView($item))->values()->all(),
        ];
    }

    private function itemView(KolAffiliateBindingActivationItem $item): array
    {
        $identity = $item->exact_identity ?? [];
        return [
            'id' => (int) $item->id,
            'cluster_key' => $item->cluster_key,
            'canonical_kol_id' => $item->canonical_kol_id ? (int) $item->canonical_kol_id : null,
            'kol' => $item->kol ? ['id' => (int) $item->kol->id, 'display_name' => $item->kol->display_name, 'contact_email' => $item->kol->contact_email] : null,
            'affiliate_account_id' => $item->affiliate_account_id ? (int) $item->affiliate_account_id : null,
            'affiliate' => $item->affiliateAccount ? $this->accountSnapshot($item->affiliateAccount) : null,
            'readiness_status' => $item->readiness_status,
            'block_reasons' => $item->block_reasons ?? [],
            'exact_identity' => $identity,
            'primary_email' => $this->normalizeEmail(($identity['emails'] ?? [])[0] ?? null),
            'profile_urls' => array_values((array) ($identity['profile_urls'] ?? [])),
            'affiliate_snapshot' => $item->affiliate_snapshot ?? [],
            'referral_impact_count' => (int) $item->referral_impact_count,
            'referral_provider_checksum' => $item->referral_provider_checksum,
            'applied_at' => $item->applied_at?->toIso8601String(),
        ];
    }

    private function blockerSummaryFromItems($items): array
    {
        $summary = [];
        foreach ($items as $item) foreach ($item->block_reasons ?? [] as $reason) $summary[$reason] = ($summary[$reason] ?? 0) + 1;
        return $summary;
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private function normalizeUrl(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $parts = parse_url($value);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return null;
        $path = rtrim($parts['path'] ?? '', '/');
        $port = $parts['port'] ?? null;
        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).($port !== null ? ':'.$port : '').$path.(array_key_exists('query', $parts) ? '?'.$parts['query'] : '');
    }

    private function bindingEnabled(): bool
    {
        return ! app()->environment('production') && (bool) config('kol.affiliate_binding_activation_enabled', false);
    }

    private function emptySummary(): array
    {
        return ['eligible_kol_count' => 0, 'ready_count' => 0, 'already_bound_count' => 0, 'no_affiliate_count' => 0, 'blocked_count' => 0];
    }

    private function authorize(Authenticatable $actor): void
    {
        foreach (['system.permissions.manage', 'kol.operate', 'kol.affiliates.manage'] as $permission) {
            if (! $actor->can($permission)) throw new AuthorizationException;
        }
    }
}
