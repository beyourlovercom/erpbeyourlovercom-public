<?php

namespace App\Modules\Products\Infrastructure;

use App\Modules\Products\Application\Contracts\SkuReconciliationRepository;
use App\Modules\Products\Infrastructure\Models\ProductSkuReconciliation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class EloquentSkuReconciliationRepository implements SkuReconciliationRepository
{
    public function findByMatchKey(string $matchKey): ?Model
    {
        return ProductSkuReconciliation::where('match_key', $matchKey)->first();
    }

    /**
     * Insert or increment a reconciliation observation.
     * Idempotent: same match_key → update occurrence_count and source_counts.
     */
    public function observe(string $matchKey, string $rawSku, string $sourceType, ?string $sourceReference, ?string $productNameHint = null): Model
    {
        return DB::transaction(function () use ($matchKey, $rawSku, $sourceType, $sourceReference, $productNameHint): Model {
            $existing = ProductSkuReconciliation::where('match_key', $matchKey)->lockForUpdate()->first();
            $now = now();

            if ($existing === null) {
                return ProductSkuReconciliation::create([
                    'match_key' => $matchKey,
                    'raw_sku' => $rawSku,
                    'product_name_hint' => $productNameHint,
                    'occurrence_count' => 1,
                    'source_counts' => [$sourceType => 1],
                    'last_source_type' => $sourceType,
                    'last_source_reference' => $sourceReference,
                    'status' => 'open',
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
            }

            $sourceCounts = (array) ($existing->source_counts ?? []);
            $sourceCounts[$sourceType] = ($sourceCounts[$sourceType] ?? 0) + 1;

            $existing->fill([
                'raw_sku' => $rawSku,
                'product_name_hint' => $productNameHint ?? $existing->product_name_hint,
                'occurrence_count' => $existing->occurrence_count + 1,
                'source_counts' => $sourceCounts,
                'last_source_type' => $sourceType,
                'last_source_reference' => $sourceReference,
                'last_seen_at' => $now,
                'status' => $existing->status === 'ignored' ? $existing->status : 'open',
            ])->save();

            return $existing->refresh();
        });
    }

    public function resolve(Model $item, int $productId, int $aliasId, int $resolvedByUserId, ?string $note): void
    {
        $item->fill([
            'status' => 'resolved',
            'resolved_product_id' => $productId,
            'resolved_alias_id' => $aliasId,
            'resolved_by_user_id' => $resolvedByUserId,
            'resolution_note' => $note,
            'resolved_at' => now(),
        ])->save();
    }

    public function ignore(Model $item, int $resolvedByUserId, ?string $note): void
    {
        $item->fill([
            'status' => 'ignored',
            'resolved_by_user_id' => $resolvedByUserId,
            'resolution_note' => $note,
            'resolved_at' => now(),
        ])->save();
    }

    public function reopen(Model $item): void
    {
        $item->fill([
            'status' => 'open',
            'resolved_product_id' => null,
            'resolved_alias_id' => null,
            'resolved_by_user_id' => null,
            'resolution_note' => null,
            'resolved_at' => null,
        ])->save();
    }

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return ProductSkuReconciliation::query()
            ->with(['resolvedProduct:id,sku,name', 'resolvedAlias:id,alias_sku'])
            ->when(
                isset($filters['status']) && in_array($filters['status'], ['open', 'resolved', 'ignored'], true),
                fn ($q) => $q->where('status', $filters['status'])
            )
            ->when(
                isset($filters['search']) && $filters['search'] !== '',
                fn ($q) => $q->where('raw_sku', 'like', '%'.$filters['search'].'%')
            )
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'ignored' THEN 1 ELSE 2 END")
            ->orderBy('last_seen_at', 'desc')
            ->paginate(min($perPage, 200))
            ->withQueryString();
    }
}
