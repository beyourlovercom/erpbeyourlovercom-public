<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Kol\Application\ImportPreview\KolImportPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class KolImportPreviewController
{
    public function __construct(private readonly KolImportPreviewService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Kol/ImportPreview', [
            'batches' => $this->service->workspace(),
            'canManageOwnerMapping' => $request->user()->can('kol.approvals.manage') && $request->user()->can('kol.affiliates.manage'),
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate(['reconciliation_batch_id' => 'required|integer|exists:kol_reconciliation_batches,id']);
        $batch = $this->service->generate((int) $data['reconciliation_batch_id'], $request->user());
        return response()->json(['batch' => $this->service->batchView($batch)]);
    }
}
