<?php

namespace App\Modules\Orders\Infrastructure\WooCommerce;

use App\Modules\Orders\Application\Contracts\WooCommerceFinancialEvidenceReader;
use App\Modules\Orders\Application\Contracts\WooCommerceOrderGateway;
use App\Modules\Orders\Application\Inbound\WooCommerceOrderNormalizer;
use App\Modules\Orders\Infrastructure\Models\OrderExternalReference;
use BackedEnum;
use Throwable;

final class EloquentWooCommerceFinancialEvidenceReader implements WooCommerceFinancialEvidenceReader
{
    public function __construct(
        private readonly WooCommerceOrderGateway $gateway,
        private readonly WooCommerceOrderNormalizer $normalizer,
    ) {}

    public function descriptor(): array
    {
        return $this->gateway->descriptor();
    }

    public function fetchOne(int $providerOrderId): array
    {
        return $this->normalize($this->gateway->fetchOrder($providerOrderId));
    }

    public function fetchPage(array $query): array
    {
        $page = $this->gateway->fetchOrders($query);

        return [
            'items' => array_values(array_map(fn (array $payload): array => $this->normalize($payload), $page['items'])),
            'total_pages' => max(1, (int) $page['total_pages']),
        ];
    }

    private function normalize(array $payload): array
    {
        $reference = trim((string) ($payload['id'] ?? ''));
        $connectionKey = (string) ($this->descriptor()['connection_key'] ?? '');
        if ($reference === '' || ! ctype_digit($reference)) {
            return $this->error('invalid_provider_reference', $reference, $connectionKey);
        }

        $references = OrderExternalReference::query()
            ->with('order')
            ->where('provider', 'woocommerce')
            ->where('connection_key', $connectionKey)
            ->where('reference', $reference)
            ->get();

        if ($references->isEmpty()) {
            return $this->source('unresolved', $reference, $connectionKey, null, null, 'canonical_order_not_found');
        }
        if ($references->count() !== 1) {
            return $this->source('ambiguous', $reference, $connectionKey, null, null, 'canonical_order_reference_ambiguous');
        }

        $order = $references->firstOrFail()->order;
        if (! $order) {
            return $this->source('unresolved', $reference, $connectionKey, null, null, 'canonical_order_not_found');
        }

        $kind = $order->order_kind;
        $kind = $kind instanceof BackedEnum ? $kind->value : (string) $kind;
        if ($kind !== 'customer') {
            return $this->source('blocked', $reference, $connectionKey, null, (int) $order->getKey(), 'order_kind_not_customer');
        }
        if ($order->externalReferences()->where('provider', 'historical_logistics')->exists()) {
            return $this->source('blocked', $reference, $connectionKey, null, (int) $order->getKey(), 'historical_logistics_excluded');
        }

        try {
            $normalized = $this->normalizer->normalize($payload, $connectionKey);
        } catch (Throwable) {
            return $this->error('settlement_normalization_failed', $reference, $connectionKey, (int) $order->getKey());
        }

        return $this->source('resolved', $reference, $connectionKey, $normalized->settlementEvidence, (int) $order->getKey(), null);
    }

    private function source(string $status, string $reference, string $connectionKey, ?array $evidence, ?int $orderId, ?string $blocker): array
    {
        return [
            'source_status' => $status,
            'provider' => 'woocommerce',
            'provider_reference' => $reference,
            'connection_key' => $connectionKey,
            'provider_modified_at' => $evidence['provider_modified_at'] ?? null,
            'order_id' => $orderId,
            'blocker' => $blocker,
            'settlement_evidence' => $evidence,
        ];
    }

    private function error(string $code, string $reference, string $connectionKey, ?int $orderId = null): array
    {
        return [
            'source_status' => 'error',
            'provider' => 'woocommerce',
            'provider_reference' => $reference,
            'connection_key' => $connectionKey,
            'order_id' => $orderId,
            'blocker' => $code,
            'settlement_evidence' => null,
        ];
    }
}
