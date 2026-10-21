<?php

namespace App\Modules\Products\Infrastructure;

use App\Modules\Products\Application\Contracts\WooCommerceProductInboundStore;
use App\Modules\Products\Infrastructure\Models\ProductMedia;
use App\Modules\Products\Infrastructure\Models\ProductProviderSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class EloquentWooCommerceProductInboundStore implements WooCommerceProductInboundStore
{
    public function latestProviderModifiedAt(string $provider, string $connectionKey): ?string
    {
        $value = ProductProviderSnapshot::query()
            ->where('provider', $provider)
            ->where('connection_key', $connectionKey)
            ->whereNotNull('provider_modified_at')
            ->latest('provider_modified_at')
            ->value('provider_modified_at');

        return $value === null ? null : (string) $value;
    }

    public function snapshotHash(string $provider, string $connectionKey, string $remoteProductId): ?string
    {
        return ProductProviderSnapshot::query()
            ->where('provider', $provider)
            ->where('connection_key', $connectionKey)
            ->where('remote_product_id', $remoteProductId)
            ->value('payload_hash');
    }

    public function upsertSnapshot(
        int $productId,
        string $provider,
        string $connectionKey,
        string $remoteProductId,
        array $attributes,
    ): void {
        ProductProviderSnapshot::query()->updateOrCreate(
            [
                'provider' => $provider,
                'connection_key' => $connectionKey,
                'remote_product_id' => $remoteProductId,
            ],
            [
                ...$attributes,
                'product_id' => $productId,
            ],
        );
    }

    public function reconcileMedia(
        int $productId,
        string $provider,
        string $connectionKey,
        array $items,
    ): void {
        $stalePaths = DB::transaction(function () use ($productId, $provider, $connectionKey, $items): array {
            $old = ProductMedia::query()->where([
                'product_id' => $productId,
                'provider' => $provider,
                'connection_key' => $connectionKey,
            ])->get();
            $keep = [];

            foreach ($items as $entry) {
                $body = (string) $entry['body'];
                unset($entry['body']);
                $path = 'products/'.$productId.'/woo/'.$entry['sha256'].'.'.$this->extension((string) $entry['mime_type']);
                if (! Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->put($path, $body);
                }
                $row = ProductMedia::query()->updateOrCreate(
                    [
                        'product_id' => $productId,
                        'provider' => $provider,
                        'connection_key' => $connectionKey,
                        'provider_media_key' => $entry['provider_media_key'],
                    ],
                    [...$entry, 'storage_path' => $path, 'last_synced_at' => now()],
                );
                $keep[] = (int) $row->id;
            }

            $stale = $old->reject(fn (ProductMedia $row): bool => in_array((int) $row->id, $keep, true));
            $paths = $stale->pluck('storage_path')->filter()->values()->all();
            foreach ($stale as $row) $row->delete();
            return $paths;
        });

        foreach (array_unique($stalePaths) as $path) {
            if (! ProductMedia::query()->where('storage_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => throw new \RuntimeException('Unsupported media MIME type.'),
        };
    }
}
