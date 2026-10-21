<?php

namespace App\Modules\Products\Infrastructure;

use App\Modules\Products\Application\Contracts\WeightEvidenceRepository;
use App\Modules\Products\Infrastructure\Models\ProductWeightEvidence;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

final class EloquentWeightEvidenceRepository implements WeightEvidenceRepository
{
    public function create(array $attributes): Model
    {
        return ProductWeightEvidence::create($attributes);
    }

    public function findById(int $id): ?Model
    {
        return ProductWeightEvidence::with('product')->find($id);
    }

    public function update(Model $evidence, array $attributes): void
    {
        $evidence->fill($attributes)->save();
    }

    public function paginateFiltered(array $filters, int $perPage): LengthAwarePaginator
    {
        return ProductWeightEvidence::query()
            ->with(['product:id,sku,name,weight_kg,shipping_weight_kg,weight_evidence_id,shipping_weight_evidence_id'])
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['source_type']) && $filters['source_type'] !== '', fn ($q) => $q->where('source_type', $filters['source_type']))
            ->when(isset($filters['weight_kind']) && $filters['weight_kind'] !== '', fn ($q) => $q->where('weight_kind', $filters['weight_kind']))
            ->when(isset($filters['search']) && $filters['search'] !== '', function ($q) use ($filters): void {
                $search = '%'.$filters['search'].'%';
                $q->where(function ($inner) use ($search): void {
                    $inner->where('raw_sku', 'like', $search)
                        ->orWhereHas('product', fn ($p) => $p->where('sku', 'like', $search)->orWhere('name', 'like', $search));
                });
            })
            ->orderByDesc('created_at')
            ->paginate(min($perPage, 200))
            ->withQueryString();
    }

    public function pendingCount(): int
    {
        return ProductWeightEvidence::where('status', 'pending')->count();
    }
}
