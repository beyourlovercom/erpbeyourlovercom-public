<?php

namespace Tests\Feature;

use App\Modules\Sync\Application\SyncRunItemStatus;
use App\Modules\Sync\Application\SyncRunService;
use App\Modules\Sync\Application\SyncRunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class SyncRunServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_run_persists_items_counts_and_audit(): void
    {
        $service = app(SyncRunService::class);
        $run = $service->start('tracking_selected', 2, null, 'woocommerce', 'shop-a');
        $first = $service->addItem($run, ['operation' => 'woocommerce.tracking_outbound']);
        $second = $service->addItem($run, ['operation' => 'woocommerce.tracking_outbound']);

        $service->finishItem($service->markProcessing($first), SyncRunItemStatus::Succeeded, 'verified');
        $service->finishItem($service->markProcessing($second), SyncRunItemStatus::Succeeded, 'already_verified');
        $completed = $service->complete($run, null);

        $this->assertSame(SyncRunStatus::Completed, $completed->status);
        $this->assertSame(2, $completed->processed_items);
        $this->assertSame(2, $completed->succeeded_items);
        $this->assertSame(0, $completed->failed_items);
        $this->assertSame(0, $completed->conflict_items);
        $this->assertDatabaseCount('sync_run_items', 2);
        $this->assertDatabaseHas('audit_records', ['action' => 'sync.run.started']);
        $this->assertDatabaseHas('audit_records', ['action' => 'sync.run.completed']);
    }

    public function test_retry_conflict_and_skip_produce_partial_summary(): void
    {
        $service = app(SyncRunService::class);
        $run = $service->start('tracking_all_pending', 4, null, 'woocommerce', 'shop-a');
        $statuses = [
            [SyncRunItemStatus::Succeeded, 'verified'],
            [SyncRunItemStatus::Retrying, 'retry_scheduled'],
            [SyncRunItemStatus::Conflict, 'remote_tracking_conflict'],
            [SyncRunItemStatus::Skipped, 'not_eligible'],
        ];

        foreach ($statuses as [$status, $code]) {
            $item = $service->addItem($run, ['operation' => 'woocommerce.tracking_outbound']);
            $service->finishItem($service->markProcessing($item), $status, $code);
        }

        $completed = $service->complete($run, null);
        $this->assertSame(SyncRunStatus::Partial, $completed->status);
        $this->assertSame(1, $completed->succeeded_items);
        $this->assertSame(1, $completed->retrying_items);
        $this->assertSame(1, $completed->conflict_items);
        $this->assertSame(1, $completed->skipped_items);
    }

    public function test_only_terminal_failures_marks_run_failed(): void
    {
        $service = app(SyncRunService::class);
        $run = $service->start('retry_pending', 2, null);
        foreach (range(1, 2) as $_) {
            $item = $service->addItem($run, ['operation' => 'woocommerce.tracking_outbound']);
            $service->finishItem(
                $service->markProcessing($item),
                SyncRunItemStatus::Failed,
                'write_failed',
                'provider_error',
                'Synthetic provider error',
            );
        }

        $completed = $service->complete($run, null);
        $this->assertSame(SyncRunStatus::Failed, $completed->status);
        $this->assertSame(2, $completed->failed_items);
    }

    public function test_run_cannot_complete_with_pending_items(): void
    {
        $service = app(SyncRunService::class);
        $run = $service->start('tracking_selected', 1, null);
        $service->addItem($run, ['operation' => 'woocommerce.tracking_outbound']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('pending or processing');
        $service->complete($run, null);
    }
}
