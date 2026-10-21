<?php

namespace App\Modules\Products\Application\Contracts;

interface WooCommerceProductInboundStore
{
    public function latestProviderModifiedAt(string $provider, string $connectionKey): ?string;

    public function snapshotHash(string $provider, string $connectionKey, string $remoteProductId): ?string;

    /** @param array<string,mixed> $attributes */
    public function upsertSnapshot(
        int $productId,
        string $provider,
        string $connectionKey,
        string $remoteProductId,
        array $attributes,
    ): void;

    /** @param list<array<string,mixed>> $items */
    public function reconcileMedia(
        int $productId,
        string $provider,
        string $connectionKey,
        array $items,
    ): void;
}
