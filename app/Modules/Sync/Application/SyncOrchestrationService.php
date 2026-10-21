<?php

namespace App\Modules\Sync\Application;

use App\Modules\Sync\Application\Contracts\SyncOrchestrationRepository;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class SyncOrchestrationService
{
    private const int MaximumBatch = 100;
    private const string TrackingOperation = 'woocommerce.tracking_outbound';

    public function __construct(
        private readonly SyncRunService $runs,
        private readonly WooCommerceTrackingOutboundService $tracking,
        private readonly WooCommerceTrackingGateway $gateway,
        private readonly SyncOutboxService $outbox,
        private readonly SyncOrchestrationRepository $repository,
    ) {}

    /** @param array<int,mixed> $orderIds */
    public function trackingSelected(array $orderIds, ?Authenticatable $actor): Model
    {
        $ids = $this->normalizeIds($orderIds);
        if ($ids === []) {
            throw new DomainException('至少选择一张订单。');
        }

        $connection = $this->connection();
        $run = $this->runs->start(
            'tracking_selected',
            count($ids),
            $actor,
            'woocommerce',
            $connection,
            ['selection' => 'selected'],
        );
        $orders = $this->repository->ordersByIds($ids);

        foreach ($ids as $id) {
            $order = $orders->get($id);
            if (! $order) {
                $item = $this->runs->addItem($run, [
                    'operation' => self::TrackingOperation,
                    'provider_reference' => 'erp-order:'.$id,
                ]);
                $this->runs->finishItem($item, SyncRunItemStatus::Skipped, 'order_missing');
                continue;
            }
            $this->executeTrackingOrder($run, $order, $actor, $connection);
        }

        return $this->runs->complete($run, $actor);
    }

    public function trackingAllPending(?Authenticatable $actor, int $limit = self::MaximumBatch): Model
    {
        $limit = $this->boundedLimit($limit);
        $connection = $this->connection();
        $orders = $this->repository->eligibleTrackingOrders($limit);
        $run = $this->runs->start(
            'tracking_all_pending',
            $orders->count(),
            $actor,
            'woocommerce',
            $connection,
            ['selection' => 'all_pending', 'limit' => $limit],
        );

        foreach ($orders as $order) {
            $this->executeTrackingOrder($run, $order, $actor, $connection);
        }

        return $this->runs->complete($run, $actor);
    }

    public function retryPending(?Authenticatable $actor, int $limit = self::MaximumBatch): Model
    {
        $limit = $this->boundedLimit($limit);
        $connection = $this->connection();
        $records = $this->repository->dueTrackingOutbox($limit);

        $run = $this->runs->start(
            'retry_pending',
            $records->count(),
            $actor,
            'woocommerce',
            $connection,
            ['limit' => $limit],
        );

        foreach ($records as $record) {
            $item = $this->runs->addItem($run, [
                'operation' => self::TrackingOperation,
                'shipment_id' => ctype_digit((string) $record->aggregate_id) ? (int) $record->aggregate_id : null,
                'outbox_id' => $record->id,
            ]);
            $this->runs->markProcessing($item);
            $this->executePendingRecord($item, $record, $actor);
        }

        return $this->runs->complete($run, $actor);
    }

    public function recoverStale(
        ?Authenticatable $actor,
        int $ageSeconds = 300,
        int $limit = self::MaximumBatch,
    ): Model {
        $limit = $this->boundedLimit($limit);
        if ($ageSeconds < 60 || $ageSeconds > 86400) {
            throw new DomainException('超时恢复窗口必须在 60 到 86400 秒之间。');
        }

        $recovered = $this->outbox->recoverStale(
            CarbonImmutable::now()->subSeconds($ageSeconds),
            $limit,
        );
        $run = $this->runs->start(
            'recover_stale',
            $recovered->count(),
            $actor,
            'woocommerce',
            $this->gateway->descriptor()['connection_key'] ?? null,
            ['age_seconds' => $ageSeconds, 'limit' => $limit],
        );

        foreach ($recovered as $record) {
            $item = $this->runs->addItem($run, [
                'operation' => 'sync.outbox.stale_recovery',
                'shipment_id' => ctype_digit((string) $record->aggregate_id) ? (int) $record->aggregate_id : null,
                'outbox_id' => $record->id,
            ]);
            $status = $record->status === SyncOutboxStatus::Failed
                ? SyncRunItemStatus::Failed
                : SyncRunItemStatus::Succeeded;
            $this->runs->finishItem(
                $item,
                $status,
                $record->last_error_code ?: 'stale_lock_released',
                $record->status === SyncOutboxStatus::Failed ? $record->last_error_code : null,
                $record->status === SyncOutboxStatus::Failed ? $record->last_error_message : null,
                $record->id,
            );
        }

        return $this->runs->complete($run, $actor);
    }

    /** @param array<int,mixed> $orderIds */
    public function reconcileSelected(array $orderIds, ?Authenticatable $actor): Model
    {
        $ids = $this->normalizeIds($orderIds);
        if ($ids === []) {
            throw new DomainException('至少选择一张订单。');
        }

        $connection = $this->connection();
        $run = $this->runs->start(
            'reconcile_tracking',
            count($ids),
            $actor,
            'woocommerce',
            $connection,
            ['selection' => 'selected'],
        );
        $orders = $this->repository->ordersByIds($ids);
        foreach ($ids as $id) {
            $order = $orders->get($id);
            if (! $order) {
                $item = $this->runs->addItem($run, [
                    'operation' => 'woocommerce.tracking_reconcile',
                    'provider_reference' => 'erp-order:'.$id,
                ]);
                $this->runs->finishItem($item, SyncRunItemStatus::Skipped, 'not_eligible');
                continue;
            }

            $item = $this->runs->addItem($run, [
                'operation' => 'woocommerce.tracking_reconcile',
                'order_id' => $order->id,
                'shipment_id' => $order->shipment?->id,
                'provider_reference' => $this->providerReference($order, $connection),
            ]);
            $this->runs->markProcessing($item);
            $this->reconcileOrder($item, $order);
        }

        return $this->runs->complete($run, $actor);
    }

    /** @return array{retry_due:int,retry_waiting:int,processing:int,failed:int,conflicts:int} */
    public function queueSummary(): array
    {
        return $this->repository->queueSummary(self::TrackingOperation);
    }

    public function recentRuns(int $limit = 20)
    {
        return $this->runs->recent($this->boundedLimit($limit));
    }

    public function runDetail(int $runId): Model
    {
        return $this->runs->detail($runId);
    }

    private function executeTrackingOrder(
        Model $run,
        Model $order,
        ?Authenticatable $actor,
        string $connection,
    ): void {
        $item = $this->runs->addItem($run, [
            'operation' => self::TrackingOperation,
            'order_id' => $order->id,
            'shipment_id' => $order->shipment?->id,
            'provider_reference' => $this->providerReference($order, $connection),
        ]);
        $this->runs->markProcessing($item);

        try {
            $result = $this->tracking->pushOne($order, $actor);
            $this->finishFromTrackingResult($item, $result);
        } catch (DomainException $exception) {
            $this->runs->finishItem(
                $item,
                SyncRunItemStatus::Skipped,
                'not_eligible',
                'not_eligible',
                $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            $this->runs->finishItem(
                $item,
                SyncRunItemStatus::Failed,
                'orchestrator_exception',
                'orchestrator_exception',
                $exception->getMessage(),
            );
        }
    }

    private function executePendingRecord(
        Model $item,
        Model $record,
        ?Authenticatable $actor,
    ): void {
        try {
            if (! ctype_digit((string) $record->aggregate_id)) {
                $this->runs->finishItem($item, SyncRunItemStatus::Skipped, 'aggregate_missing');
                return;
            }

            $shipment = $this->repository->shipmentWithOrder((int) $record->aggregate_id);
            if (! $shipment?->order) {
                $this->runs->finishItem($item, SyncRunItemStatus::Skipped, 'aggregate_missing');
                return;
            }

            $prepared = $this->tracking->describeOrder($shipment->order);
            $item = $this->runs->annotateItem($item, [
                'order_id' => $shipment->order->id,
                'provider_reference' => (string) $prepared['provider_order_id'],
            ]);

            if (($prepared['intent']['idempotency_key'] ?? null) !== $record->idempotency_key) {
                $this->runs->finishItem(
                    $item,
                    SyncRunItemStatus::Skipped,
                    'truth_changed',
                    'truth_changed',
                    'Canonical Shipment truth changed after this outbox intent was created.',
                    $record->id,
                );
                return;
            }

            $result = $this->tracking->pushOne($shipment->order, $actor);
            $this->finishFromTrackingResult($item, $result);
        } catch (DomainException $exception) {
            $this->runs->finishItem(
                $item,
                SyncRunItemStatus::Skipped,
                'not_eligible',
                'not_eligible',
                $exception->getMessage(),
                $record->id,
            );
        } catch (Throwable $exception) {
            $this->runs->finishItem(
                $item,
                SyncRunItemStatus::Failed,
                'retry_runner_exception',
                'retry_runner_exception',
                $exception->getMessage(),
                $record->id,
            );
        }
    }

    /** @param array<string,mixed> $result */
    private function finishFromTrackingResult(Model $item, array $result): void
    {
        $status = (string) ($result['status'] ?? 'failed');
        $outboxId = isset($result['outbox']) ? (int) $result['outbox']->id : $item->outbox_id;

        match ($status) {
            'completed' => $this->runs->finishItem(
                $item,
                SyncRunItemStatus::Succeeded,
                ($result['written'] ?? false) ? 'remote_written_verified' : 'already_verified',
                outboxId: $outboxId,
            ),
            'retry_scheduled', 'retry_wait' => $this->runs->finishItem(
                $item,
                SyncRunItemStatus::Retrying,
                $status,
                outboxId: $outboxId,
            ),
            'conflict' => $this->runs->finishItem(
                $item,
                SyncRunItemStatus::Conflict,
                'remote_tracking_conflict',
                $result['outbox']?->last_error_code ?? 'remote_tracking_conflict',
                $result['outbox']?->last_error_message,
                $outboxId,
            ),
            'processing' => $this->runs->finishItem(
                $item,
                SyncRunItemStatus::Skipped,
                'already_processing',
                outboxId: $outboxId,
            ),
            default => $this->runs->finishItem(
                $item,
                SyncRunItemStatus::Failed,
                $status,
                $result['outbox']?->last_error_code ?? 'tracking_outbound_failed',
                $result['outbox']?->last_error_message,
                $outboxId,
            ),
        };
    }

    private function reconcileOrder(Model $item, Model $order): void
    {
        try {
            $expected = $this->tracking->describeOrder($order);
            $remote = $this->gateway->inspectTracking((int) $expected['provider_order_id']);
            if (! $remote['has_tracking']) {
                $this->runs->finishItem($item, SyncRunItemStatus::Conflict, 'remote_missing');
                return;
            }

            if ($remote['tracking_number'] === $expected['tracking_number']
                && $remote['provider'] === $expected['provider_slug']) {
                $this->runs->finishItem($item, SyncRunItemStatus::Succeeded, 'matched');
                return;
            }

            $this->runs->finishItem($item, SyncRunItemStatus::Conflict, 'conflict');
        } catch (DomainException $exception) {
            $this->runs->finishItem(
                $item,
                SyncRunItemStatus::Skipped,
                'not_eligible',
                'not_eligible',
                $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            $this->runs->finishItem(
                $item,
                SyncRunItemStatus::Failed,
                'reconciliation_failed',
                'reconciliation_failed',
                $exception->getMessage(),
            );
        }
    }

    private function providerReference(Model $order, string $connection): ?string
    {
        return $order->externalReferences
            ->first(fn ($reference): bool => $reference->provider === 'woocommerce'
                && $reference->connection_key === $connection)
            ?->reference;
    }

    private function connection(): string
    {
        $descriptor = $this->gateway->descriptor();
        if (! ($descriptor['configured'] ?? false)) {
            throw new DomainException('WooCommerce connection 尚未配置。');
        }

        $connection = trim((string) ($descriptor['connection_key'] ?? ''));
        if ($connection === '') {
            throw new DomainException('WooCommerce connection key 缺失。');
        }

        return $connection;
    }

    /** @param array<int,mixed> $ids @return list<int> */
    private function normalizeIds(array $ids): array
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));
        if (count($normalized) > self::MaximumBatch) {
            throw new DomainException('单次同步最多处理 100 个项目。');
        }

        return $normalized;
    }

    private function boundedLimit(int $limit): int
    {
        if ($limit < 1 || $limit > self::MaximumBatch) {
            throw new DomainException('单次同步数量必须在 1 到 100 之间。');
        }

        return $limit;
    }
}
