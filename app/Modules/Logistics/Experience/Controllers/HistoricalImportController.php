<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Backup\Application\BackupEngineService;
use App\Modules\Backup\Application\BackupType;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalLogisticsControlCenterService;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalRescueShipmentAttemptProjectionService;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalReviewRescueService;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalShipmentImportService;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalRescueOrderProjectionBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalRescueShipmentAttemptProjectionBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class HistoricalImportController
{
    public function __construct(
        private readonly HistoricalShipmentImportService $service,
        private readonly HistoricalReviewRescueService $rescue,
        private readonly HistoricalLogisticsControlCenterService $controlCenter,
        private readonly HistoricalRescueShipmentAttemptProjectionService $rescueAttempts,
        private readonly BackupEngineService $backups,
    ) {}

    public function index(Request $request): Response
    {
        $batch = HistoricalImportBatch::query()->latest('id')->first();
        $page = max(1, (int) $request->integer('page', 1));
        return Inertia::render('Logistics/HistoricalImport', [
            'batch' => $batch ? $this->service->view($batch, $page) : null,
            'commitEnabled' => (bool) config('logistics.historical_import_commit_enabled', false),
            'reviewRescue' => $batch ? $this->rescue->plan($batch) : null,
            'rescueCommitEnabled' => (bool) config('logistics.historical_review_rescue_commit_enabled', false),
            'controlCenter' => $batch ? $this->controlCenter->view(
                $batch,
                max(1, (int) $request->integer('attempt_page', 1)),
                $request->string('attempt_q')->toString(),
                $request->string('attempt_decision', 'all')->toString(),
                $request->string('attempt_warehouse', 'all')->toString(),
            ) : null,
            'canOperate' => (bool) $request->user()?->can('logistics.operate'),
            'canViewOrders' => (bool) $request->user()?->can('orders.view'),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:65536']]);
        $batch = $this->service->preview($request->file('file'), $request->user());
        return response()->json(['batch' => $this->service->view($batch), 'reviewRescue' => $this->rescue->plan($batch)]);
    }

    public function commitReviewRescue(Request $request, HistoricalImportBatch $batch): JsonResponse
    {
        $batch = $this->rescue->commit($batch, $request->user());
        return response()->json(['batch' => $this->service->view($batch), 'reviewRescue' => $this->rescue->plan($batch)]);
    }

    public function commit(Request $request, HistoricalImportBatch $batch): JsonResponse
    {
        $batch = $this->service->commit($batch, $request->user());
        return response()->json(['batch' => $this->service->view($batch), 'reviewRescue' => $this->rescue->plan($batch)]);
    }

    public function previewRescueAttempts(Request $request, HistoricalImportBatch $batch): RedirectResponse
    {
        $orderBatch = $this->appliedRescueOrderBatch($batch);
        $this->rescueAttempts->preview($orderBatch, $request->user());
        return back()->with('success', 'R5 Historical ShipmentAttempt Preview 已冻结，可逐条审核。');
    }

    public function applyRescueAttempts(
        Request $request,
        HistoricalImportBatch $batch,
        HistoricalRescueShipmentAttemptProjectionBatch $projection,
    ): RedirectResponse {
        $validated = $request->validate([
            'ready_manifest_sha256' => ['required', 'string', 'size:64'],
            'confirmation' => ['required', 'string', 'max:64'],
        ]);
        $orderBatch = $projection->rescueOrderProjectionBatch()->firstOrFail();
        if ((int) $orderBatch->historical_import_batch_id !== (int) $batch->id) abort(404);
        if ($projection->status === 'applied') return back()->with('success', 'R5 已完成 Apply；本次操作保持幂等。');
        if ($projection->status !== 'preview' || $projection->review_count !== 0 || $projection->ready_count < 1) {
            throw ValidationException::withMessages(['projection' => '当前 R5 Preview 不满足 Apply 条件。']);
        }
        if (! hash_equals((string) $projection->ready_manifest_sha256, (string) $validated['ready_manifest_sha256'])
            || $validated['confirmation'] !== 'APPLY '.$projection->ready_count) {
            throw ValidationException::withMessages(['projection' => 'Owner approval payload 与冻结 Preview 不一致。']);
        }
        $fresh = $this->rescueAttempts->inspect($orderBatch);
        if ((int) $fresh['ready_count'] !== (int) $projection->ready_count
            || (int) $fresh['review_count'] !== 0
            || ! hash_equals((string) $projection->ready_manifest_sha256, (string) $fresh['ready_manifest_sha256'])) {
            throw ValidationException::withMessages(['projection' => 'R5 evidence 已发生漂移，请重新 Preview 后再批准。']);
        }

        $backup = $this->backups->create(BackupType::Database, (int) $request->user()->getAuthIdentifier());
        $this->backups->execute($backup);

        $previousGate = (bool) config('logistics.historical_rescue_shipment_attempt_apply_enabled', false);
        try {
            config()->set('logistics.historical_rescue_shipment_attempt_apply_enabled', true);
            $this->rescueAttempts->apply($projection, $request->user());
        } finally {
            config()->set('logistics.historical_rescue_shipment_attempt_apply_enabled', $previousGate);
        }

        return back()->with('success', 'R5 Apply 完成：历史 ShipmentAttempts 已写入，Active Shipment 未创建。');
    }

    private function appliedRescueOrderBatch(HistoricalImportBatch $batch): HistoricalRescueOrderProjectionBatch
    {
        $projection = HistoricalRescueOrderProjectionBatch::query()
            ->where('historical_import_batch_id', $batch->id)
            ->where('status', 'applied')
            ->latest('id')->first();
        if ($projection === null) {
            throw ValidationException::withMessages(['projection' => '尚无已 Apply 的 Historical Rescue Order Projection。']);
        }
        return $projection;
    }
}
