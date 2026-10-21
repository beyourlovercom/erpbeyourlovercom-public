<?php

namespace App\Modules\Sync\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Sync\Application\Contracts\SyncRunRepository;
use App\Modules\Sync\Application\Contracts\SyncRunTracker;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SyncRunService implements SyncRunTracker
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly SyncRunRepository $repository,
    ) {}

    public function start(
        string $runType,
        int $requestedItems,
        ?Authenticatable $actor,
        ?string $providerKey = null,
        ?string $connectionKey = null,
        array $context = [],
    ): Model {
        if ($requestedItems < 0) {
            throw new LogicException('Sync run requested item count cannot be negative.');
        }

        $run = $this->repository->createRun([
            'run_type' => trim($runType),
            'provider_key' => $providerKey,
            'connection_key' => $connectionKey,
            'status' => SyncRunStatus::Running,
            'requested_items' => $requestedItems,
            'initiated_by' => $actor?->getAuthIdentifier(),
            'context' => $context === [] ? null : $context,
            'started_at' => now(),
        ]);

        $this->audit->record(
            $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
            'sync.run.started',
            get_class($run),
            $run->getKey(),
            after: $this->summary($run),
            reason: 'sync_orchestration_started',
        );

        return $run;
    }

    public function setRequestedItems(Model $run, int $requestedItems): Model
    {
        if ($requestedItems < 0) {
            throw new LogicException('Sync run requested item count cannot be negative.');
        }

        return DB::transaction(function () use ($run, $requestedItems): Model {
            $locked = $this->repository->lockRun((int) $run->getKey());
            $this->assertRunning($locked);
            $this->repository->updateRun($locked, ['requested_items' => $requestedItems]);

            return $this->repository->freshRun($locked);
        });
    }

    /** @param array<string,mixed> $context */
    public function annotateContext(Model $run, array $context): Model
    {
        return DB::transaction(function () use ($run, $context): Model {
            $locked = $this->repository->lockRun((int) $run->getKey());
            $this->assertRunning($locked);
            $current = is_array($locked->context) ? $locked->context : [];
            $this->repository->updateRun($locked, ['context' => [...$current, ...$context]]);

            return $this->repository->freshRun($locked);
        });
    }

    /** @param array<string,mixed> $attributes */
    public function addItem(Model $run, array $attributes): Model
    {
        return DB::transaction(function () use ($run, $attributes): Model {
            $locked = $this->repository->lockRun((int) $run->getKey());
            $this->assertRunning($locked);

            return $this->repository->createItem($locked, [
                'sequence' => $this->repository->nextItemSequence($locked),
                'operation' => (string) $attributes['operation'],
                'order_id' => $attributes['order_id'] ?? null,
                'shipment_id' => $attributes['shipment_id'] ?? null,
                'product_id' => $attributes['product_id'] ?? null,
                'outbox_id' => $attributes['outbox_id'] ?? null,
                'provider_reference' => $attributes['provider_reference'] ?? null,
                'status' => SyncRunItemStatus::Pending,
            ]);
        });
    }

    public function markProcessing(Model $item): Model
    {
        $this->repository->updateItem($item, [
            'status' => SyncRunItemStatus::Processing,
            'started_at' => $item->started_at ?? now(),
            'completed_at' => null,
            'result_code' => null,
            'error_code' => null,
            'error_message' => null,
        ]);

        return $this->repository->freshItem($item);
    }

    /** @param array<string,mixed> $attributes */
    public function annotateItem(Model $item, array $attributes): Model
    {
        $this->repository->updateItem($item, $attributes);

        return $this->repository->freshItem($item);
    }

    public function finishItem(
        Model $item,
        SyncRunItemStatus $status,
        ?string $resultCode = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?int $outboxId = null,
    ): Model {
        if (in_array($status, [SyncRunItemStatus::Pending, SyncRunItemStatus::Processing], true)) {
            throw new LogicException('Sync run item finish requires a terminal attempt outcome.');
        }

        $this->repository->updateItem($item, [
            'status' => $status,
            'outbox_id' => $outboxId ?? $item->outbox_id,
            'result_code' => $resultCode,
            'error_code' => $errorCode,
            'error_message' => $errorMessage ? mb_substr($errorMessage, 0, 500) : null,
            'started_at' => $item->started_at ?? now(),
            'completed_at' => now(),
        ]);

        return $this->repository->freshItem($item);
    }

    public function finishSucceeded(Model $item, ?string $resultCode = null): Model
    {
        return $this->finishItem($item, SyncRunItemStatus::Succeeded, $resultCode);
    }

    public function finishSkipped(Model $item, ?string $resultCode = null): Model
    {
        return $this->finishItem($item, SyncRunItemStatus::Skipped, $resultCode);
    }

    public function finishFailed(
        Model $item,
        ?string $resultCode = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): Model {
        return $this->finishItem($item, SyncRunItemStatus::Failed, $resultCode, $errorCode, $errorMessage);
    }

    public function complete(Model $run, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($run, $actor): Model {
            $locked = $this->repository->lockRun((int) $run->getKey());
            $this->assertRunning($locked);
            $items = $this->repository->items($locked);
            if ($items->contains(fn (Model $item): bool => in_array(
                $item->status,
                [SyncRunItemStatus::Pending, SyncRunItemStatus::Processing],
                true,
            ))) {
                throw new LogicException('Sync run cannot complete while items are pending or processing.');
            }

            $counts = [
                'processed_items' => $items->count(),
                'succeeded_items' => $items->where('status', SyncRunItemStatus::Succeeded)->count(),
                'retrying_items' => $items->where('status', SyncRunItemStatus::Retrying)->count(),
                'failed_items' => $items->where('status', SyncRunItemStatus::Failed)->count(),
                'conflict_items' => $items->where('status', SyncRunItemStatus::Conflict)->count(),
                'skipped_items' => $items->where('status', SyncRunItemStatus::Skipped)->count(),
            ];

            $successLike = $counts['succeeded_items'] + $counts['skipped_items'];
            $terminalIssues = $counts['failed_items'] + $counts['conflict_items'];
            $hasIssues = $terminalIssues > 0 || $counts['retrying_items'] > 0 || $counts['skipped_items'] > 0;
            $status = ! $hasIssues
                ? SyncRunStatus::Completed
                : ($terminalIssues > 0 && $successLike === 0 && $counts['retrying_items'] === 0
                    ? SyncRunStatus::Failed
                    : SyncRunStatus::Partial);

            $this->repository->updateRun($locked, [
                ...$counts,
                'status' => $status,
                'completed_at' => now(),
            ]);

            $completed = $this->repository->freshRun($locked, ['items']);
            $this->audit->record(
                $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
                'sync.run.completed',
                get_class($completed),
                $completed->getKey(),
                after: $this->summary($completed),
                reason: 'sync_orchestration_completed',
            );

            return $completed;
        });
    }

    /** @return Collection<int,Model> */
    public function recent(int $limit): Collection
    {
        return $this->repository->recent($limit);
    }

    public function detail(int $runId): Model
    {
        return $this->repository->detail($runId);
    }

    public function latestContextString(
        string $runType,
        ?string $providerKey,
        ?string $connectionKey,
        string $key,
    ): ?string {
        foreach ($this->repository->recentForScope($runType, $providerKey, $connectionKey, 50) as $run) {
            $status = $run->status instanceof \BackedEnum ? $run->status->value : (string) $run->status;
            if (! in_array($status, [SyncRunStatus::Completed->value, SyncRunStatus::Partial->value], true)) {
                continue;
            }
            $context = is_array($run->context) ? $run->context : [];
            $value = trim((string) ($context[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function summary(Model $run): array
    {
        return [
            'run_type' => $run->run_type,
            'provider_key' => $run->provider_key,
            'connection_key' => $run->connection_key,
            'status' => $run->status instanceof \BackedEnum ? $run->status->value : $run->status,
            'requested_items' => (int) $run->requested_items,
            'processed_items' => (int) $run->processed_items,
            'succeeded_items' => (int) $run->succeeded_items,
            'retrying_items' => (int) $run->retrying_items,
            'failed_items' => (int) $run->failed_items,
            'conflict_items' => (int) $run->conflict_items,
            'skipped_items' => (int) $run->skipped_items,
        ];
    }

    private function assertRunning(Model $run): void
    {
        if ($run->status !== SyncRunStatus::Running) {
            throw new LogicException('Sync run is no longer running.');
        }
    }
}
