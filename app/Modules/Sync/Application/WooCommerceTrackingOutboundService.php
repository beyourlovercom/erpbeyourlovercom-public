<?php

namespace App\Modules\Sync\Application;

use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class WooCommerceTrackingOutboundService
{
    public function __construct(
        private readonly WooCommerceTrackingGateway $gateway,
        private readonly SyncOutboxService $outbox,
        private readonly ManualSyncService $manualSync,
        private readonly WooCommerceOrderStatusOutboundService $orderStatusOutbound,
    ) {}

    /** @return array<string,mixed> */
    public function pushOne(Model $order, ?Authenticatable $actor): array
    {
        $prepared = $this->prepare($order);
        $record = $this->outbox->enqueue($prepared['intent']);

        if ($record->status === SyncOutboxStatus::Completed) {
            $this->manualSync->completeFromAutomaticTracking($order, $actor, 'WooCommerce tracking outbound already verified.');
            $statusSync = $this->orderStatusOutbound->pushOne($order);

            return [
                'status' => 'completed',
                'order_status_sync' => $statusSync['status'] ?? null,
                'aggregate' => $statusSync['aggregate'] ?? null,
                'woo_status' => $statusSync['woo_status'] ?? null,
                'outbox' => $record,
            ];
        }

        if ($record->status === SyncOutboxStatus::Failed) {
            return ['status' => 'failed', 'outbox' => $record];
        }

        if ($record->status === SyncOutboxStatus::Processing) {
            return ['status' => 'processing', 'outbox' => $record];
        }

        if ($record->available_at?->isFuture()) {
            return [
                'status' => 'retry_wait',
                'outbox' => $record,
                'available_at' => $record->available_at->toIso8601String(),
            ];
        }

        $claimed = $this->outbox->claim($record->id);
        $lockToken = (string) $claimed->lock_token;

        try {
            $remote = $this->gateway->syncTracking(
                $prepared['provider_order_id'],
                $prepared['provider_slug'],
                $prepared['tracking_number'],
                $prepared['ship_date'],
                $prepared['line_items'],
            );
        } catch (WooCommerceTrackingConflictException $exception) {
            $failed = $this->outbox->fail(
                $claimed->id,
                $lockToken,
                'remote_tracking_conflict',
                $exception->getMessage(),
            );

            return ['status' => 'conflict', 'outbox' => $failed];
        } catch (Throwable $exception) {
            if ((int) $claimed->attempts < (int) $claimed->max_attempts) {
                $retry = $this->outbox->retry(
                    $claimed->id,
                    $lockToken,
                    'woocommerce_tracking_write_failed',
                    $exception->getMessage(),
                );

                return [
                    'status' => 'retry_scheduled',
                    'outbox' => $retry,
                    'available_at' => $retry->available_at?->toIso8601String(),
                ];
            }

            $failed = $this->outbox->fail(
                $claimed->id,
                $lockToken,
                'woocommerce_tracking_write_failed',
                $exception->getMessage(),
            );

            return ['status' => 'failed', 'outbox' => $failed];
        }

        if (! ($remote['verified'] ?? false)) {
            $failed = $this->outbox->fail(
                $claimed->id,
                $lockToken,
                'woocommerce_tracking_verify_failed',
                'WooCommerce tracking verification failed.',
            );

            return ['status' => 'failed', 'outbox' => $failed];
        }

        $completed = $this->outbox->complete($claimed->id, $lockToken);
        $this->manualSync->completeFromAutomaticTracking($order, $actor, 'WooCommerce tracking outbound verified.');
        $statusSync = $this->orderStatusOutbound->pushOne($order);

        return [
            'status' => 'completed',
            'written' => (bool) ($remote['written'] ?? false),
            'package_count' => (int) ($remote['package_count'] ?? 0),
            'order_status_sync' => $statusSync['status'] ?? null,
            'aggregate' => $statusSync['aggregate'] ?? null,
            'woo_status' => $statusSync['woo_status'] ?? null,
            'outbox' => $completed,
        ];
    }

    /** @return array<string,mixed> */
    public function describeOrder(Model $order): array
    {
        return $this->prepare($order);
    }

    /** @return array<string,mixed> */
    private function prepare(Model $order): array
    {
        $order->loadMissing(['shipment', 'items', 'externalReferences', 'manualSync', 'fulfillmentParent.externalReferences']);
        $root = $order->fulfillment_parent_id ? $order->fulfillmentParent : $order;
        if (! $root) {
            throw new DomainException('衍生履约订单缺少根客户订单。');
        }

        $sourceType = $root->source_type instanceof \BackedEnum
            ? $root->source_type->value
            : (string) $root->source_type;
        if ($sourceType !== 'woocommerce') {
            throw new DomainException('只有 WooCommerce 来源订单可以自动写回 Tracking。');
        }

        $shipment = $order->shipment;
        if (! $shipment || $shipment->shipment_status !== 'shipped') {
            throw new DomainException('订单尚未完成发货，不能写回 Tracking。');
        }

        $trackingNumber = trim((string) $shipment->tracking_number);
        $carrier = trim((string) $shipment->carrier);
        if ($trackingNumber === '' || $carrier === '' || ! $shipment->shipped_at) {
            throw new DomainException('发货记录缺少承运商、Tracking 或发货时间。');
        }

        $lineItems = $order->items->map(function ($item): array {
            $providerId = (int) ($item->woocommerce_line_item_id ?? 0);
            $quantity = (int) $item->quantity;
            if ($providerId <= 0 || $quantity <= 0) {
                throw new DomainException('履约包裹缺少可靠的 Woo line-item identity，禁止按 SKU 猜测写回。');
            }

            return ['id' => $providerId, 'quantity' => $quantity];
        })->values()->all();
        if ($lineItems === []) {
            throw new DomainException('履约包裹没有可绑定的 Woo line items。');
        }
        if (count(array_unique(array_column($lineItems, 'id'))) !== count($lineItems)) {
            throw new DomainException('履约包裹包含重复的 Woo line-item identity。');
        }

        $descriptor = $this->gateway->descriptor();
        if (! ($descriptor['configured'] ?? false)) {
            throw new DomainException('WooCommerce outbound connection 尚未配置。');
        }

        $connectionKey = (string) $descriptor['connection_key'];
        $reference = $root->externalReferences
            ->first(fn ($item) => $item->provider === 'woocommerce'
                && $item->connection_key === $connectionKey);
        if (! $reference || ! ctype_digit((string) $reference->reference)) {
            throw new DomainException('根客户订单缺少当前 WooCommerce connection 的外部订单引用。');
        }

        $providerOrderId = (int) $reference->reference;
        $providerSlug = $this->resolveCarrier($carrier);
        $shippedAt = CarbonImmutable::parse($shipment->shipped_at)->utc();
        $lineItemFingerprint = collect($lineItems)
            ->sortBy('id')
            ->map(fn (array $item): string => $item['id'].':'.$item['quantity'])
            ->implode(',');
        $fingerprint = hash('sha256', implode('|', [
            $connectionKey,
            (string) $providerOrderId,
            (string) $shipment->getKey(),
            $providerSlug,
            $trackingNumber,
            $shippedAt->toIso8601String(),
            $lineItemFingerprint,
        ]));

        $payload = [
            'provider_order_id' => $providerOrderId,
            'root_order_id' => (int) $root->getKey(),
            'fulfillment_order_id' => (int) $order->getKey(),
            'shipment_id' => (int) $shipment->getKey(),
            'provider_slug' => $providerSlug,
            'tracking_number' => $trackingNumber,
            'shipped_at' => $shippedAt->toIso8601String(),
            'line_items' => $lineItems,
        ];

        return [
            'root_order' => $root,
            'provider_order_id' => $providerOrderId,
            'provider_slug' => $providerSlug,
            'tracking_number' => $trackingNumber,
            'ship_date' => $shippedAt->format('Y-m-d'),
            'line_items' => $lineItems,
            'intent' => [
                'idempotency_key' => 'woo-tracking:'.$fingerprint,
                'operation' => 'woocommerce.tracking_outbound',
                'aggregate_type' => 'shipment',
                'aggregate_id' => (string) $shipment->getKey(),
                'provider_key' => 'woocommerce',
                'connection_key' => $connectionKey,
                'payload' => $payload,
                'max_attempts' => 3,
            ],
        ];
    }

    private function resolveCarrier(string $carrier): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $carrier)) ?? $carrier));

        foreach ((array) config('erp_logistics.carriers', []) as $definition) {
            $aliases = (array) ($definition['aliases'] ?? []);
            $value = trim((string) ($definition['value'] ?? ''));
            if ($value !== '') {
                $aliases[] = $value;
            }

            foreach ($aliases as $alias) {
                $normalizedAlias = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', (string) $alias)) ?? (string) $alias));
                if ($normalized === $normalizedAlias) {
                    return (string) $definition['aftership_slug'];
                }
            }
        }

        throw new DomainException("承运商 {$carrier} 尚未配置 AfterShip provider slug。只有显式映射的承运商可以自动写回。");
    }
}
