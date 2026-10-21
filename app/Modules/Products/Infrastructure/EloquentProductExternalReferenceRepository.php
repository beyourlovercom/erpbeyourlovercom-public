<?php

namespace App\Modules\Products\Infrastructure;

use App\Modules\Products\Application\Contracts\ProductExternalReferenceRepository;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductExternalReference;
use Illuminate\Database\Eloquent\Model;

final class EloquentProductExternalReferenceRepository implements ProductExternalReferenceRepository
{
    public function findProduct(string $provider, string $connectionKey, string $reference): ?Model
    {
        $ref = ProductExternalReference::where('provider', $provider)
            ->where('connection_key', $connectionKey)
            ->where('reference', $reference)
            ->first();

        if ($ref === null) {
            return null;
        }

        return Product::find($ref->getAttribute('product_id'));
    }

    public function upsert(int $productId, string $provider, string $connectionKey, string $reference, string $referenceType): void
    {
        $existing = ProductExternalReference::where('provider', $provider)
            ->where('connection_key', $connectionKey)
            ->where('reference', $reference)
            ->first();

        if ($existing !== null) {
            if ((int) $existing->getAttribute('product_id') !== $productId) {
                throw new \RuntimeException(
                    "External reference conflict: {$provider}/{$connectionKey}/{$reference} already mapped to product #{$existing->getAttribute('product_id')}"
                );
            }
            // Already correct — update reference_type if it changed.
            if ($existing->getAttribute('reference_type') !== $referenceType) {
                $existing->forceFill(['reference_type' => $referenceType])->save();
            }
            return;
        }

        ProductExternalReference::create([
            'product_id' => $productId,
            'provider' => $provider,
            'connection_key' => $connectionKey,
            'reference' => $reference,
            'reference_type' => $referenceType,
        ]);
    }

    public function forProduct(int $productId): array
    {
        return ProductExternalReference::where('product_id', $productId)
            ->orderBy('reference_type')
            ->orderBy('reference')
            ->get(['provider', 'connection_key', 'reference', 'reference_type'])
            ->map(fn ($r): array => [
                'provider' => $r->provider,
                'connection_key' => $r->connection_key,
                'reference' => $r->reference,
                'reference_type' => $r->reference_type,
            ])
            ->all();
    }
}
