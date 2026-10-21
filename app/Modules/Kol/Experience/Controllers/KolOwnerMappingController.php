<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Kol\Application\OwnerMapping\KolOwnerMappingService;
use App\Modules\Kol\Infrastructure\Models\KolOwnerCandidateDecision;
use App\Modules\Kol\Infrastructure\Models\KolOwnerSourceMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class KolOwnerMappingController
{
    public function __construct(private readonly KolOwnerMappingService $service) {}

    public function index(Request $request): Response
    {
        $previewBatchId = $request->integer('preview_batch_id') ?: null;
        return Inertia::render('Kol/OwnerMapping', [
            'workspace' => $this->service->workspace($request->user(), $previewBatchId),
        ]);
    }

    public function storeSource(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preview_batch_id' => 'required|integer|exists:kol_import_preview_batches,id',
            'source_owner_value' => 'required|string|max:191',
            'mapped_user_id' => 'required|integer|exists:users,id',
        ]);
        return response()->json(['workspace' => $this->service->saveSourceMapping(
            (int) $data['preview_batch_id'],
            $data['source_owner_value'],
            (int) $data['mapped_user_id'],
            $request->user(),
        )]);
    }

    public function destroySource(Request $request, KolOwnerSourceMapping $mapping): JsonResponse
    {
        $data = $request->validate(['preview_batch_id' => 'required|integer|exists:kol_import_preview_batches,id']);
        return response()->json(['workspace' => $this->service->deleteSourceMapping($mapping, (int) $data['preview_batch_id'], $request->user())]);
    }

    public function storeDecision(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preview_batch_id' => 'required|integer|exists:kol_import_preview_batches,id',
            'cluster_key' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'mapped_user_id' => 'required|integer|exists:users,id',
            'reason' => 'nullable|string|max:500',
        ]);
        return response()->json(['workspace' => $this->service->saveCandidateDecision(
            (int) $data['preview_batch_id'],
            $data['cluster_key'],
            (int) $data['mapped_user_id'],
            $data['reason'] ?? null,
            $request->user(),
        )]);
    }

    public function destroyDecision(Request $request, KolOwnerCandidateDecision $decision): JsonResponse
    {
        $data = $request->validate(['preview_batch_id' => 'required|integer|exists:kol_import_preview_batches,id']);
        return response()->json(['workspace' => $this->service->deleteCandidateDecision($decision, (int) $data['preview_batch_id'], $request->user())]);
    }
}
