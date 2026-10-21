<?php

namespace App\Modules\Sync\Experience\Controllers;

use App\Modules\Sync\Application\SyncOrchestrationService;
use App\Modules\Sync\Infrastructure\Models\SyncRun;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class SyncOrchestrationController
{
    public function __construct(private readonly SyncOrchestrationService $orchestration) {}

    public function trackingSelected(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        return $this->execute(
            fn () => $this->orchestration->trackingSelected($data['order_ids'], $request->user()),
            '所选 Tracking 写回',
        );
    }

    public function trackingAllPending(Request $request): RedirectResponse
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return $this->execute(
            fn () => $this->orchestration->trackingAllPending($request->user(), (int) ($data['limit'] ?? 100)),
            '全部待同步 Tracking 写回',
        );
    }

    public function retryPending(Request $request): RedirectResponse
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return $this->execute(
            fn () => $this->orchestration->retryPending($request->user(), (int) ($data['limit'] ?? 100)),
            '到期重试',
        );
    }

    public function recoverStale(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'age_seconds' => ['nullable', 'integer', 'min:60', 'max:86400'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        return $this->execute(
            fn () => $this->orchestration->recoverStale(
                $request->user(),
                (int) ($data['age_seconds'] ?? 300),
                (int) ($data['limit'] ?? 100),
            ),
            '超时任务恢复',
        );
    }

    public function reconcileSelected(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        return $this->execute(
            fn () => $this->orchestration->reconcileSelected($data['order_ids'], $request->user()),
            'Tracking 核对',
        );
    }

    public function show(SyncRun $run): Response
    {
        $run = $this->orchestration->runDetail($run->id);

        return Inertia::render('Sync/RunShow', [
            'run' => $this->serializeRun($run),
        ]);
    }

    private function execute(callable $callback, string $label): RedirectResponse
    {
        try {
            $run = $callback();

            return back()->with('success', sprintf(
                '%s完成：成功 %d，重试 %d，冲突 %d，失败 %d，跳过 %d',
                $label,
                $run->succeeded_items,
                $run->retrying_items,
                $run->conflict_items,
                $run->failed_items,
                $run->skipped_items,
            ));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            return back()->with('error', '同步编排失败：'.$exception->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function serializeRun(SyncRun $run): array
    {
        return [
            'id' => $run->id,
            'run_type' => $run->run_type,
            'provider_key' => $run->provider_key,
            'connection_key' => $run->connection_key,
            'status' => $run->status->value,
            'requested_items' => $run->requested_items,
            'processed_items' => $run->processed_items,
            'succeeded_items' => $run->succeeded_items,
            'retrying_items' => $run->retrying_items,
            'failed_items' => $run->failed_items,
            'conflict_items' => $run->conflict_items,
            'skipped_items' => $run->skipped_items,
            'initiator' => $run->initiator?->name,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'items' => $run->items->map(fn ($item): array => [
                'id' => $item->id,
                'sequence' => $item->sequence,
                'operation' => $item->operation,
                'order_id' => $item->order_id,
                'shipment_id' => $item->shipment_id,
                'outbox_id' => $item->outbox_id,
                'provider_reference' => $item->provider_reference,
                'status' => $item->status->value,
                'result_code' => $item->result_code,
                'error_code' => $item->error_code,
                'error_message' => $item->error_message,
                'started_at' => $item->started_at?->toIso8601String(),
                'completed_at' => $item->completed_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
