<?php

namespace App\Modules\Inventory\Experience\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Application\WarehouseRestockDocumentJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BlueLabelWorkerController extends Controller
{
    public function __construct(
        private readonly WarehouseRestockDocumentJobService $jobs,
    ) {}

    public function claim(Request $request): JsonResponse
    {
        $job = $this->jobs->claimNext($this->workerId($request));

        return response()->json([
            'job' => $job ? $this->jobs->workerPayload($job) : null,
        ]);
    }

    public function start(Request $request, string $job): JsonResponse
    {
        $updated = $this->jobs->start($job, $this->workerId($request));

        return response()->json(['job' => $this->jobs->workerPayload($updated)]);
    }

    public function heartbeat(Request $request, string $job): JsonResponse
    {
        $updated = $this->jobs->heartbeat($job, $this->workerId($request));

        return response()->json(['job' => $this->jobs->workerPayload($updated)]);
    }

    public function artifact(Request $request, string $job, string $kind, string $labelSku): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);
        $sha256 = strtolower(trim((string) $request->header('X-Content-SHA256', '')));

        $stored = $this->jobs->storeArtifact(
            $job,
            $this->workerId($request),
            $kind,
            $labelSku,
            $validated['file'],
            $sha256,
        );

        return response()->json(['artifact' => $stored]);
    }

    public function complete(Request $request, string $job): JsonResponse
    {
        $validated = $request->validate([
            'manifest' => ['required', 'array'],
            'output_root' => ['required', 'string', 'max:1024'],
        ]);

        $updated = $this->jobs->complete(
            $job,
            $this->workerId($request),
            $validated['manifest'],
            trim((string) $validated['output_root']),
        );

        return response()->json(['job' => $this->jobs->workerPayload($updated)]);
    }

    public function fail(Request $request, string $job): JsonResponse
    {
        $validated = $request->validate([
            'error_code' => ['required', 'string', 'max:128'],
            'error_message' => ['required', 'string', 'max:4000'],
        ]);

        $updated = $this->jobs->fail(
            $job,
            $this->workerId($request),
            trim((string) $validated['error_code']),
            trim((string) $validated['error_message']),
        );

        return response()->json(['job' => $this->jobs->workerPayload($updated)]);
    }

    private function workerId(Request $request): string
    {
        return (string) $request->attributes->get('blue_label_worker_id');
    }
}
