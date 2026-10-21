<?php

namespace App\Modules\Sync\Application;

use App\Modules\Logistics\Application\ShipmentAggregateStatusService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use Throwable;

final class WooCommerceOrderStatusOutboundService
{
    public function __construct(
        private readonly WooCommerceTrackingGateway $gateway,
        private readonly SyncOutboxService $outbox,
        private readonly ShipmentAggregateStatusService $aggregateStatus,
    ) {}

    /** @return array<string,mixed> */
    public function pushOne(Order $order): array
    {
        $aggregate = $this->aggregateStatus->reconcile($order);
        /** @var Order $root */
        $root = $aggregate['root'];
        $root->loadMissing('externalReferences');
        $sourceType = $root->source_type instanceof \BackedEnum ? $root->source_type->value : (string) $root->source_type;
        if ($sourceType !== 'woocommerce') {
            return ['status' => 'not_applicable', 'aggregate' => $aggregate['aggregate']];
        }

        $descriptor = $this->gateway->descriptor();
        if (! ($descriptor['configured'] ?? false)) {
            return ['status' => 'not_configured', 'aggregate' => $aggregate['aggregate'], 'woo_status' => $aggregate['woo_status']];
        }
        $connectionKey = (string) $descriptor['connection_key'];
        $reference = $root->externalReferences->first(fn ($item) => $item->provider === 'woocommerce'
            && $item->connection_key === $connectionKey);
        if (! $reference || ! ctype_digit((string) $reference->reference)) {
            return ['status' => 'reference_missing', 'aggregate' => $aggregate['aggregate'], 'woo_status' => $aggregate['woo_status']];
        }
        $providerOrderId = (int) $reference->reference;
        $wooStatus = (string) $aggregate['woo_status'];
        $fingerprint = hash('sha256', implode('|', [$connectionKey, $providerOrderId, $aggregate['aggregate'], $wooStatus, $aggregate['state_fingerprint']]));
        $record = $this->outbox->enqueue([
            'idempotency_key' => 'woo-order-status:'.$fingerprint,
            'operation' => 'woocommerce.order_status_outbound',
            'aggregate_type' => 'order',
            'aggregate_id' => (string) $root->getKey(),
            'provider_key' => 'woocommerce',
            'connection_key' => $connectionKey,
            'payload' => [
                'provider_order_id' => $providerOrderId,
                'root_order_id' => (int) $root->getKey(),
                'aggregate' => $aggregate['aggregate'],
                'woo_status' => $wooStatus,
                'state_fingerprint' => $aggregate['state_fingerprint'],
            ],
            'max_attempts' => 3,
        ]);

        if ($record->status === SyncOutboxStatus::Completed) {
            return ['status' => 'completed', 'aggregate' => $aggregate['aggregate'], 'woo_status' => $wooStatus, 'outbox' => $record];
        }
        if ($record->status === SyncOutboxStatus::Failed) {
            return ['status' => 'failed', 'aggregate' => $aggregate['aggregate'], 'woo_status' => $wooStatus, 'outbox' => $record];
        }
        if ($record->status === SyncOutboxStatus::Processing) {
            return ['status' => 'processing', 'aggregate' => $aggregate['aggregate'], 'woo_status' => $wooStatus, 'outbox' => $record];
        }
        if ($record->available_at?->isFuture()) {
            return ['status' => 'retry_wait', 'aggregate' => $aggregate['aggregate'], 'woo_status' => $wooStatus, 'outbox' => $record];
        }

        $claimed = $this->outbox->claim($record->id);
        $lockToken = (string) $claimed->lock_token;
        try {
            $remote = $this->gateway->syncOrderStatus($providerOrderId, $wooStatus);
        } catch (WooCommerceOrderStatusUnsupportedException $exception) {
            $failed = $this->outbox->fail($claimed->id, $lockToken, 'woocommerce_status_unsupported', $exception->getMessage());

            return ['status' => 'unsupported', 'aggregate' => $aggregate['aggregate'], 'woo_status' => $wooStatus, 'outbox' => $failed];
        } catch (Throwable $exception) {
            if ((int) $claimed->attempts < (int) $claimed->max_attempts) {
                $retry = $this->outbox->retry($claimed->id, $lockToken, 'woocommerce_status_write_failed', $exception->getMessage());

                return ['status' => 'retry_scheduled', 'aggregate' => $aggregate['aggregate'], 'woo_status' => $wooStatus, 'outbox' => $retry];
            }
            $failed = $this->outbox->fail($claimed->id, $lockToken, 'woocommerce_status_write_failed', $exception->getMessage());

            return ['status' => 'failed', 'aggregate' => $aggregate['aggregate'], 'woo_status' => $wooStatus, 'outbox' => $failed];
        }
        if (! ($remote['verified'] ?? false)) {
            $failed = $this->outbox->fail($claimed->id, $lockToken, 'woocommerce_status_verify_failed', 'WooCommerce status verification failed.');

            return ['status' => 'failed', 'aggregate' => $aggregate['aggregate'], 'woo_status' => $wooStatus, 'outbox' => $failed];
        }
        $completed = $this->outbox->complete($claimed->id, $lockToken);

        return [
            'status' => 'completed', 'aggregate' => $aggregate['aggregate'], 'woo_status' => $wooStatus,
            'written' => (bool) ($remote['written'] ?? false), 'outbox' => $completed,
        ];
    }
}
