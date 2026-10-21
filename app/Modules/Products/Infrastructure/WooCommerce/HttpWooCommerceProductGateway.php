<?php

namespace App\Modules\Products\Infrastructure\WooCommerce;

use App\Modules\Products\Application\Contracts\WooCommerceProductGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpWooCommerceProductGateway implements WooCommerceProductGateway
{
    public function descriptor(): array
    {
        return ['configured' => $this->configured(), 'url' => $this->baseUrl(), 'connection_key' => $this->connectionKey()];
    }

    public function probe(): array
    {
        $this->assertConfigured();
        $response = $this->client()->get($this->baseUrl().'/wp-json/wc/v3/products', ['per_page' => 1]);
        return ['ok' => $response->successful(), 'status' => $response->status()];
    }

    public function fetchProduct(int $remoteProductId): array
    {
        $this->assertConfigured();
        $payload = $this->client()->get($this->productUrl($remoteProductId))->throw()->json();
        if (! is_array($payload)) throw new RuntimeException('WooCommerce Product response is invalid.');
        return $payload;
    }

    public function products(int $page = 1, int $perPage = 100, ?string $modifiedAfter = null): array
    {
        $this->assertConfigured();
        $perPage = min(100, max(1, $perPage));
        $query = ['page' => max(1, $page), 'per_page' => $perPage, 'orderby' => 'modified', 'order' => 'asc'];
        if ($modifiedAfter !== null) $query['modified_after'] = $modifiedAfter;
        $response = $this->client()->get($this->baseUrl().'/wp-json/wc/v3/products', $query)->throw();
        $payload = $response->json();
        if (! is_array($payload)) throw new RuntimeException('WooCommerce Products response is invalid.');
        return array_values(array_filter($payload, 'is_array'));
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; BYL-ERP-WooCommerce-Inbound/1.0; +https://www.beyourlover.com/)'])->withBasicAuth((string) config('services.woocommerce.consumer_key'), (string) config('services.woocommerce.consumer_secret'))->timeout(max(1, (int) config('services.woocommerce.timeout', 15)));
    }
    private function assertConfigured(): void { if (! $this->configured()) throw new RuntimeException('WooCommerce connection is not configured.'); }
    private function configured(): bool { return $this->baseUrl() !== '' && trim((string) config('services.woocommerce.consumer_key')) !== '' && trim((string) config('services.woocommerce.consumer_secret')) !== ''; }
    private function baseUrl(): string { return rtrim(trim((string) config('services.woocommerce.url')), '/'); }
    private function connectionKey(): string { return trim((string) config('services.woocommerce.connection_key', 'beyourlover.com')) ?: 'beyourlover.com'; }
    private function productUrl(int $id): string { return $this->baseUrl().'/wp-json/wc/v3/products/'.$id; }
}
