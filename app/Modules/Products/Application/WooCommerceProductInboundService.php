<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Application\Contracts\ProductExternalReferenceRepository;
use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Products\Application\Contracts\SkuAliasRepository;
use App\Modules\Products\Application\Contracts\WooCommerceProductGateway;
use App\Modules\Products\Application\Contracts\WooCommerceProductInboundStore;
use App\Modules\Sync\Application\SyncRunItemStatus;
use App\Modules\Sync\Application\SyncRunService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class WooCommerceProductInboundService
{
    private const int MaxPages = 100;
    private const array AllowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly WooCommerceProductGateway $gateway,
        private readonly ProductRepository $products,
        private readonly ProductExternalReferenceRepository $externalRefs,
        private readonly SkuAliasRepository $aliases,
        private readonly WooCommerceProductInboundStore $store,
        private readonly SyncRunService $runs,
    ) {}

    /** @return array{run:Model,processed:int} */
    public function sync(?Authenticatable $actor = null, bool $full = false, ?int $singleRemoteId = null): array
    {
        $descriptor = $this->gateway->descriptor();
        $connection = (string) $descriptor['connection_key'];
        $payloads = $singleRemoteId !== null ? [$this->gateway->fetchProduct($singleRemoteId)] : $this->fetchPages($full, $connection);
        $run = $this->runs->start('woocommerce_product_inbound', count($payloads), $actor, 'woocommerce', $connection, ['mode' => $singleRemoteId !== null ? 'single' : ($full ? 'full' : 'incremental')]);
        foreach ($payloads as $payload) $this->processOne($run, $payload, $connection);
        return ['run' => $this->runs->complete($run, $actor), 'processed' => count($payloads)];
    }

    /** @return list<array<string,mixed>> */
    private function fetchPages(bool $full, string $connection): array
    {
        $cursor = null;
        if (! $full) {
            $cursor = $this->store->latestProviderModifiedAt('woocommerce', $connection);
            if ($cursor !== null) $cursor = now()->parse((string) $cursor)->subMinutes(5)->toIso8601String();
        }
        $all = [];
        for ($page = 1; $page <= self::MaxPages; $page++) {
            $items = $this->gateway->products($page, 100, $cursor);
            $all = [...$all, ...$items];
            if (count($items) < 100) return $all;
            if ($page === self::MaxPages) throw new RuntimeException('WooCommerce Product sync exceeds the 100-page bound.');
        }
        return $all;
    }

    private function processOne(Model $run, array $remote, string $connection): void
    {
        $remoteId = (int) ($remote['id'] ?? 0);
        $item = $this->runs->addItem($run, ['operation' => 'woocommerce.product_inbound', 'provider_reference' => $remoteId > 0 ? (string) $remoteId : null]);
        $item = $this->runs->markProcessing($item);
        try {
            if ($remoteId <= 0) throw new RuntimeException('WooCommerce Product has no valid ID.');
            ['product' => $product, 'created' => $productCreated] = $this->resolveProduct($remote, $connection);
            $hashPayload = $remote;
            unset($hashPayload['related_ids']);
            $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $media = $this->prepareMedia($product, $remote, $connection);
            $changed = $this->upsertSnapshotAndCore($product, $remote, $connection, $hash);
            $this->runs->annotateItem($item, ['product_id' => $product->id]);
            if (! $media['failed']) $this->replaceMedia($product, $media, $connection);
            $code = $media['failed'] ? 'media_failed' : ($productCreated ? 'created' : ($changed['changed'] ? 'updated' : 'unchanged'));
            $status = $media['failed'] ? SyncRunItemStatus::Failed : SyncRunItemStatus::Succeeded;
            $this->runs->finishItem($item, $status, $code, $media['failed'] ? 'media_validation_failed' : null, $media['error'] ?? null);
        } catch (IdentityConflictException $e) {
            $this->runs->finishItem($item, SyncRunItemStatus::Conflict, 'conflict', 'identity_conflict', $e->getMessage());
        } catch (\Throwable $e) {
            $this->runs->finishItem($item, SyncRunItemStatus::Failed, 'failed', 'product_inbound_failed', $e->getMessage());
        }
    }

    /** @return array{product:Model,created:bool} */
    private function resolveProduct(array $remote, string $connection): array
    {
        $id = (string) ((int) ($remote['id'] ?? 0));
        $name = $this->text($remote['name'] ?? '');
        if ($name === '') throw new IdentityConflictException('Blank-name Woo Product cannot update or create canonical Product.');
        $existing = $this->externalRefs->findProduct('woocommerce', $connection, $id);
        $sku = trim((string) ($remote['sku'] ?? ''));
        if ($existing !== null) {
            if ($sku !== '') {
                $resolved = $this->resolveSku($sku);
                if ($resolved !== null && (int) $resolved->id !== (int) $existing->id) throw new IdentityConflictException('Woo Product SKU resolves to a different canonical Product.');
                if ($resolved === null && strcasecmp((string) $existing->sku, $sku) !== 0) throw new IdentityConflictException('Bound Woo Product SKU changed without exact canonical or alias evidence.');
            }
            return ['product' => $existing, 'created' => false];
        }
        if ($sku === '') throw new IdentityConflictException('Blank-SKU Woo Product has no existing external reference.');
        $product = $this->resolveSku($sku);
        return DB::transaction(function () use ($product, $remote, $sku, $connection, $id): array {
            $created = $product === null;
            $resolved = $product ?? $this->products->create(['source_type' => 'woocommerce', 'product_type' => (string) ($remote['type'] ?? 'simple'), 'sku' => $sku, 'name' => $this->text($remote['name'] ?? ''), 'is_active' => ($remote['status'] ?? '') === 'publish']);
            $this->externalRefs->upsert((int) $resolved->id, 'woocommerce', $connection, $id, 'product');
            return ['product' => $resolved, 'created' => $created];
        });
    }

    private function resolveSku(string $sku): ?Model
    {
        $matchKey = SkuMatchKey::compute($sku);
        if ($matchKey === null) return null;
        $product = $this->products->findBySkuMatchKey($matchKey);
        if ($product !== null) return $product;
        $alias = $this->aliases->findByMatchKey($matchKey);
        return $alias ? $this->products->findById((int) $alias->product_id) : null;
    }

    /** @return array{created:bool,changed:bool} */
    private function upsertSnapshotAndCore(Model $product, array $remote, string $connection, string $hash): array
    {
        $id = (string) ((int) $remote['id']);
        $previousHash = $this->store->snapshotHash('woocommerce', $connection, $id);
        $changed = $previousHash === null || $previousHash !== $hash;
        $attributes = ['source_type' => 'woocommerce', 'product_type' => (string) ($remote['type'] ?? 'simple'), 'name' => $this->text($remote['name'] ?? ''), 'sale_price' => $this->decimal($remote['sale_price'] ?? null), 'regular_price' => $this->decimal($remote['regular_price'] ?? null), 'is_active' => ($remote['status'] ?? '') === 'publish'];
        DB::transaction(function () use ($product, $remote, $connection, $id, $hash, $attributes): void {
            $this->products->update($product, $attributes);
            $this->store->upsertSnapshot((int) $product->id, 'woocommerce', $connection, $id, ['permalink' => $this->safePermalink($remote['permalink'] ?? null), 'provider_status' => (string) ($remote['status'] ?? ''), 'description_text' => $this->text($remote['description'] ?? ''), 'short_description_text' => $this->text($remote['short_description'] ?? ''), 'category_names' => collect($remote['categories'] ?? [])->filter(fn ($c): bool => is_array($c))->map(fn ($c) => trim((string) ($c['name'] ?? '')))->filter()->values()->all(), 'variation_count' => is_array($remote['variations'] ?? null) ? count($remote['variations']) : (int) ($remote['variation_count'] ?? 0), 'provider_created_at' => $this->date($remote['date_created'] ?? null), 'provider_modified_at' => $this->date($remote['date_modified'] ?? null), 'last_synced_at' => now(), 'payload_hash' => $hash]);
        });
        return ['created' => $previousHash === null, 'changed' => $changed];
    }

    /** @return array{failed:bool,error:?string,items:list<array<string,mixed>>} */
    private function prepareMedia(Model $product, array $remote, string $connection): array
    {
        $images = array_values(array_filter($remote['images'] ?? [], 'is_array'));
        $image = $images[0] ?? null;
        if (! is_array($image)) return ['failed' => false, 'error' => null, 'items' => []];

        try {
            $url = $this->validatedUrl((string) ($image['src'] ?? $image['url'] ?? ''));
            if ($this->isGifUrl($url)) return ['failed' => false, 'error' => null, 'items' => []];
            [$body, $mime] = $this->downloadImage($url);
            if ($mime === 'image/gif') return ['failed' => false, 'error' => null, 'items' => []];

            return ['failed' => false, 'error' => null, 'items' => [[
                'provider_media_key' => (string) (($image['id'] ?? '') ?: hash('sha256', $url)),
                'remote_media_id' => isset($image['id']) ? (string) $image['id'] : null,
                'source_url' => $url,
                'mime_type' => $mime,
                'body' => $body,
                'size_bytes' => strlen($body),
                'sha256' => hash('sha256', $body),
                'alt_text' => trim((string) ($image['alt'] ?? '')) ?: null,
                'position' => 0,
                'is_primary' => true,
            ]]];
        } catch (\Throwable $e) {
            return ['failed' => true, 'error' => $e->getMessage(), 'items' => []];
        }
    }

    private function replaceMedia(Model $product, array $media, string $connection): void
    {
        $this->store->reconcileMedia((int) $product->id, 'woocommerce', $connection, $media['items']);
    }

    private function isGifUrl(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        return strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'gif';
    }

    private function validatedUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || ! isset($parts['host'])) throw new RuntimeException('Media URL is not a safe HTTPS URL.');
        $allowed = array_filter(array_map('trim', explode(',', (string) config('services.woocommerce.media_allowed_hosts', ''))));
        $baseHost = parse_url((string) config('services.woocommerce.url'), PHP_URL_HOST);
        if (! in_array(strtolower((string) $parts['host']), array_map('strtolower', array_filter([$baseHost, ...$allowed])), true)) throw new RuntimeException('Media host is not allowed.');
        return $url;
    }

    /** @return array{0:string,1:string} */
    private function downloadImage(string $url): array
    {
        for ($attempt = 0; $attempt <= 3; $attempt++) {
            $response = Http::withOptions(['allow_redirects' => false])->get($url);
            if ($response->redirect()) { $url = $this->validatedUrl((string) $response->header('Location')); continue; }
            if (! $response->successful()) throw new RuntimeException('Media download failed.');
            $headerMime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            if ($headerMime === 'image/gif') return ['', 'image/gif'];
            $body = $response->body();
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body) ?: '';
            $imageInfo = @getimagesizefromstring($body);
            $decodedMime = is_array($imageInfo) ? strtolower((string) ($imageInfo['mime'] ?? '')) : '';
            if ($detected === 'image/gif' || $decodedMime === 'image/gif') return ['', 'image/gif'];
            $maxBodyBytes = max(1, (int) config('services.woocommerce.media_max_bytes', 10485760));
            if (strlen($body) > $maxBodyBytes) throw new RuntimeException('Media exceeds configured size limit.');
            if (! in_array($headerMime, self::AllowedMimes, true) || ! in_array($detected, self::AllowedMimes, true) || $headerMime !== $detected || $decodedMime !== $detected) throw new RuntimeException('Media MIME type is not allowed.');
            return [$body, $detected];
        }
        throw new RuntimeException('Media redirect limit exceeded.');
    }

    private function safePermalink(mixed $value): ?string { try { return $value ? $this->validatedUrl((string) $value) : null; } catch (\Throwable) { return null; } }
    private function text(mixed $value): string { return trim(strip_tags((string) $value)); }
    private function decimal(mixed $value): ?string { $value = trim((string) $value); return $value === '' ? null : $value; }
    private function date(mixed $value): mixed { return $value ? now()->parse((string) $value) : null; }
}

final class IdentityConflictException extends RuntimeException {}
