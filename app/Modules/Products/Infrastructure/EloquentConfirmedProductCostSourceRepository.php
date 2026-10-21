<?php

namespace App\Modules\Products\Infrastructure;

use App\Modules\Products\Application\Contracts\ConfirmedProductCostSourceRepository;
use App\Modules\Products\Infrastructure\Models\ProductCostSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class EloquentConfirmedProductCostSourceRepository implements ConfirmedProductCostSourceRepository
{
    public function schemaReady(): bool
    {
        return Schema::hasTable('product_cost_sources');
    }

    public function findByFingerprint(string $fingerprint): ?Model
    {
        if (! $this->schemaReady()) {
            return null;
        }

        return ProductCostSource::query()->where('source_fingerprint', $fingerprint)->first();
    }

    public function findConfirmedForProductPeriod(int $productId, string $effectiveFrom): array
    {
        if (! $this->schemaReady()) {
            return [];
        }

        return ProductCostSource::query()
            ->where('product_id', $productId)
            ->where('status', 'confirmed')
            ->whereDate('effective_from', $effectiveFrom)
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function create(array $attributes): Model
    {
        if (! $this->schemaReady()) {
            throw new RuntimeException('product_cost_sources schema is not installed.');
        }

        return ProductCostSource::query()->create($attributes);
    }
}
