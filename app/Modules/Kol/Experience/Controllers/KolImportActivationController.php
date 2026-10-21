<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Kol\Application\ImportActivation\KolImportActivationService;
use App\Modules\Kol\Infrastructure\Models\KolImportActivationBatch;
use App\Modules\Shell\Application\UserUiPreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class KolImportActivationController
{
    private const GRID_NAMESPACE = 'grid.kol.import_activation.v1';

    public function __construct(
        private readonly KolImportActivationService $service,
        private readonly UserUiPreferenceService $uiPreferences,
    ) {}

    public function index(Request $request): Response
    {
        $previewBatchId = $request->integer('preview_batch_id') ?: null;
        return Inertia::render('Kol/ImportActivation', [
            'workspace' => $this->service->workspace($request->user(), $previewBatchId),
            'gridPreferenceNamespace' => self::GRID_NAMESPACE,
            'gridPreference' => $this->uiPreferences->get($request->user(), self::GRID_NAMESPACE),
        ]);
    }

    public function prepare(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'preview_batch_id' => 'required|integer|exists:kol_import_preview_batches,id',
        ]);
        $batch = $this->service->prepare((int) $data['preview_batch_id'], $request->user());
        return redirect()->to('/kol/import-activation?preview_batch_id='.$batch->import_preview_batch_id)
            ->with('success', 'KOL 正式导入计划已生成。');
    }

    public function apply(Request $request, KolImportActivationBatch $activationBatch): RedirectResponse
    {
        $data = $request->validate([
            'plan_checksum' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'ready_count' => 'required|integer|min:0',
        ]);
        $this->service->apply(
            $activationBatch,
            (string) $data['plan_checksum'],
            (int) $data['ready_count'],
            $request->user(),
        );
        return redirect()->to('/kol/import-activation?preview_batch_id='.$activationBatch->import_preview_batch_id)
            ->with('success', 'KOL Master 导入已完成。');
    }
}
