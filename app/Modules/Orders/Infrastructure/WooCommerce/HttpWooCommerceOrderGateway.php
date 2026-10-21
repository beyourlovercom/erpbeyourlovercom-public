<?php

namespace App\Modules\Orders\Infrastructure\WooCommerce;

use App\Modules\Orders\Application\Contracts\WooCommerceOrderGateway;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpWooCommerceOrderGateway implements WooCommerceOrderGateway
{
    public function descriptor(): array
    {
        return [
            'configured' => $this->configured(),
            'url' => $this->baseUrl(),
            'connection_key' => $this->connectionKey(),
        ];
    }

    public function probe(): array
    {
        $response = $this->request('orders', ['per_page' => 1]);
        return ['ok' => $response->successful(), 'status' => $response->status()];
    }

    public function fetchOrder(int $providerOrderId): array
    {
        return $this->request('orders/'.$providerOrderId)->json();
    }

    public function fetchOrders(array $query): array
    {
        $response = $this->request('orders', $query);
        $items = $response->json();
        return [
            'items' => is_array($items) ? array_values($items) : [],
            'total_pages' => max(1, (int) $response->header('X-WP-TotalPages', 1)),
        ];
    }

    private function request(string $path, array $query = []): Response
    {
        if (! $this->configured()) {
            throw new RuntimeException('WooCommerce connection is not configured.');
        }

        return Http::acceptJson()
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; BYL-ERP-WooCommerce-Inbound/1.0; +https://www.beyourlover.com/)',
            ])
            ->withBasicAuth((string) config('services.woocommerce.consumer_key'), (string) config('services.woocommerce.consumer_secret'))
            ->timeout(max(1, (int) config('services.woocommerce.timeout', 15)))
            ->get($this->baseUrl().'/wp-json/wc/v3/'.$path, $query)
            ->throw();
    }

    private function configured(): bool
    {
        return $this->baseUrl() !== ''
            && trim((string) config('services.woocommerce.consumer_key')) !== ''
            && trim((string) config('services.woocommerce.consumer_secret')) !== '';
    }

    private function baseUrl(): string
    {
        return rtrim(trim((string) config('services.woocommerce.url')), '/');
    }

    private function connectionKey(): string
    {
        return trim((string) config('services.woocommerce.connection_key', 'beyourlover.com')) ?: 'beyourlover.com';
    }
}
