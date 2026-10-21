<?php

namespace App\Modules\Products\Application\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ProductExternalReferenceRepository
{
    /**
     * Find the canonical Product for a given provider/connection/reference triple.
     * Returns null if no mapping exists.
     */
    public function findProduct(string $provider, string $connectionKey, string $reference): ?Model;

    /**
     * Upsert a single external reference mapping.
     * Conflict rule: if the (provider, connection_key, reference) triple already points to a
     * *different* product_id, throw a RuntimeException.
     */
    public function upsert(int $productId, string $provider, string $connectionKey, string $reference, string $referenceType): void;

    /**
     * Return all external references for the given product.
     * @return list<array{provider:string,connection_key:string,reference:string,reference_type:string}>
     */
    public function forProduct(int $productId): array;
}
