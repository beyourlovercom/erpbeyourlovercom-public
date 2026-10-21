<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Products\Application\ProductService;
use App\Modules\Products\Application\ProductWeightEvidenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class ProductWeightEvidenceController
{
    public function __construct(
        private readonly ProductWeightEvidenceService $evidenceService,
        private readonly ProductService $productService,
    ) {}

    public function index(Request $request): Response
    {
        $evidences = $this->evidenceService->list(
            $request->only(['status', 'source_type', 'weight_kind', 'search']),
        );

        $summary = $this->evidenceService->coverageSummary();

        return Inertia::render('Products/WeightEvidence', [
            'evidences' => $evidences->through(fn ($e): array => $this->serializeEvidence($e))->toArray(),
            'summary' => $summary,
            'filters' => $request->only(['status', 'source_type', 'weight_kind', 'search']),
            'canOperate' => Auth::user()?->can('products.operate') ?? false,
            'sourceTypes' => ProductWeightEvidenceService::SOURCE_TYPES,
            'weightKinds' => ProductWeightEvidenceService::WEIGHT_KINDS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'weight_kind' => ['required', 'string', 'in:product_weight,shipping_reference_weight'],
            'observed_weight_kg' => ['required', 'numeric', 'gt:0'],
            'source_type' => ['required', 'string', 'in:'.implode(',', ProductWeightEvidenceService::SOURCE_TYPES)],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'source_note' => ['nullable', 'string'],
            'observed_at' => ['nullable', 'date'],
            'raw_sku' => ['nullable', 'string', 'max:255'],
        ]);

        $product = $this->productService->findById((int) $data['product_id']);
        if ($product === null) {
            return back()->withErrors(['product_id' => 'Product not found']);
        }

        $this->evidenceService->createEvidence($product, $data, Auth::id());

        return redirect()->route('products.weight-evidence.index')->with('success', '证据已创建');
    }

    public function adopt(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $evidence = $this->evidenceService->findById($id);
        if ($evidence === null || $evidence->status !== 'pending') {
            return back()->withErrors(['evidence' => 'Evidence not found or not pending']);
        }

        $confirmed = (bool) $request->input('confirmed', false);

        $error = $this->evidenceService->adoptEvidence($evidence, $confirmed, Auth::id());

        if ($error === 'confirm_required') {
            return back()->with('confirm_required', true)->with('evidence_id', $id);
        }

        return redirect()->route('products.weight-evidence.index')->with('success', '证据已采纳');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'rejection_note' => ['nullable', 'string'],
        ]);

        $evidence = $this->evidenceService->findById($id);
        if ($evidence === null || $evidence->status !== 'pending') {
            return back()->withErrors(['evidence' => 'Evidence not found or not pending']);
        }

        $this->evidenceService->rejectEvidence($evidence, $data['rejection_note'] ?? null, Auth::id());

        return redirect()->route('products.weight-evidence.index')->with('success', '证据已拒绝');
    }

    public function clearWeight(Request $request, int $productId): RedirectResponse
    {
        $data = $request->validate([
            'weight_kind' => ['required', 'string', 'in:product_weight,shipping_reference_weight'],
        ]);

        $product = $this->productService->findById($productId);
        if ($product === null) {
            return back()->withErrors(['product' => 'Product not found']);
        }

        $this->evidenceService->clearWeight($product, $data['weight_kind'], Auth::id());

        return redirect()->route('products.weight-evidence.index')->with('success', '重量已清除');
    }

    public function importPage(): Response
    {
        return Inertia::render('Products/WeightEvidence', [
            'importMode' => true,
            'summary' => $this->evidenceService->coverageSummary(),
            'canOperate' => Auth::user()?->can('products.operate') ?? false,
            'sourceTypes' => ProductWeightEvidenceService::SOURCE_TYPES,
            'weightKinds' => ProductWeightEvidenceService::WEIGHT_KINDS,
        ]);
    }

    public function importPreview(Request $request): Response
    {
        $request->validate(['csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);

        $preview = $this->evidenceService->previewCsv((string) $request->file('csv_file')->getRealPath());

        $token = (string) Str::uuid();
        $request->session()->put("products.weight_evidence.import.{$token}", [
            'user_id' => (string) Auth::id(),
            'candidates' => $preview['valid_candidates'],
            'can_confirm' => $preview['valid_rows'] > 0,
        ]);

        unset($preview['valid_candidates']);

        return Inertia::render('Products/WeightEvidence', [
            'importMode' => true,
            'preview' => $preview,
            'previewToken' => $token,
            'summary' => $this->evidenceService->coverageSummary(),
            'canOperate' => Auth::user()?->can('products.operate') ?? false,
            'sourceTypes' => ProductWeightEvidenceService::SOURCE_TYPES,
            'weightKinds' => ProductWeightEvidenceService::WEIGHT_KINDS,
        ]);
    }

    public function importConfirm(Request $request): Response
    {
        $token = (string) $request->input('preview_token');
        $artifact = $request->session()->get("products.weight_evidence.import.{$token}");

        if (!is_array($artifact) || !hash_equals((string) ($artifact['user_id'] ?? ''), (string) Auth::id())) {
            return Inertia::render('Products/WeightEvidence', [
                'importMode' => true,
                'importError' => '预览已失效，请重新上传 CSV',
                'summary' => $this->evidenceService->coverageSummary(),
                'canOperate' => Auth::user()?->can('products.operate') ?? false,
                'sourceTypes' => ProductWeightEvidenceService::SOURCE_TYPES,
                'weightKinds' => ProductWeightEvidenceService::WEIGHT_KINDS,
            ]);
        }

        if (!($artifact['can_confirm'] ?? false)) {
            return Inertia::render('Products/WeightEvidence', [
                'importMode' => true,
                'importError' => '无有效行可确认',
                'summary' => $this->evidenceService->coverageSummary(),
                'canOperate' => Auth::user()?->can('products.operate') ?? false,
                'sourceTypes' => ProductWeightEvidenceService::SOURCE_TYPES,
                'weightKinds' => ProductWeightEvidenceService::WEIGHT_KINDS,
            ]);
        }

        $created = $this->evidenceService->confirmCsv($artifact['candidates'], Auth::id());
        $request->session()->forget("products.weight_evidence.import.{$token}");

        return Inertia::render('Products/WeightEvidence', [
            'importMode' => true,
            'importConfirmed' => true,
            'importCreatedCount' => $created,
            'summary' => $this->evidenceService->coverageSummary(),
            'canOperate' => Auth::user()?->can('products.operate') ?? false,
            'sourceTypes' => ProductWeightEvidenceService::SOURCE_TYPES,
            'weightKinds' => ProductWeightEvidenceService::WEIGHT_KINDS,
        ]);
    }

    private function serializeEvidence(mixed $e): array
    {
        $product = $e->product;
        $isCurrentWeight = $product && (int) $product->getAttribute('weight_evidence_id') === (int) $e->id;
        $isCurrentShipping = $product && (int) $product->getAttribute('shipping_weight_evidence_id') === (int) $e->id;

        return [
            'id' => $e->id,
            'product_id' => $e->product_id,
            'product_sku' => $product?->getAttribute('sku'),
            'product_name' => $product?->getAttribute('name'),
            'weight_kind' => $e->weight_kind,
            'observed_weight_kg' => $e->observed_weight_kg,
            'raw_sku' => $e->raw_sku,
            'source_type' => $e->source_type,
            'source_reference' => $e->source_reference,
            'source_note' => $e->source_note,
            'observed_at' => $e->observed_at?->toISOString(),
            'status' => $e->status,
            'rejection_note' => $e->rejection_note,
            'created_at' => $e->created_at?->toISOString(),
            'reviewed_at' => $e->reviewed_at?->toISOString(),
            'is_current_provenance' => $isCurrentWeight || $isCurrentShipping,
        ];
    }
}
