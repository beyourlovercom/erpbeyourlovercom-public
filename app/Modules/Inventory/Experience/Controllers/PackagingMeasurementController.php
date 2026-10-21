<?php

namespace App\Modules\Inventory\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Identity\Application\AuthorizationKeys;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementMedia;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\NotificationCenter\Application\NotificationPublisher;
use App\Modules\Products\Application\PackagingPresetCatalog;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PackagingMeasurementController
{
    public function __construct(private readonly AuditRecorder $audit, private readonly PackagingPresetCatalog $presets, private readonly NotificationPublisher $notifications) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can(AuthorizationKeys::InventoryPackagingSubmit->value) || $user->can(AuthorizationKeys::InventoryPackagingReview->value) || $user->can(AuthorizationKeys::InventoryView->value), 403);
        $reviewer = $user->can(AuthorizationKeys::InventoryPackagingReview->value) || $user->can(AuthorizationKeys::InventoryView->value);
        $query = PackagingMeasurementRecord::query()->with(['product:id,sku,name','warehouse:id,name','creator:id,name','media']);
        if (! $reviewer) $query->where('created_by', $user->getAuthIdentifier());
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        if ($request->filled('search')) $query->whereHas('product', fn ($q) => $q->where('sku', 'like', '%'.$request->string('search').'%')->orWhere('name', 'like', '%'.$request->string('search').'%'));
        $records = $query->latest('id')->paginate(20)->withQueryString()->through(fn (PackagingMeasurementRecord $r) => $this->summary($r));
        return Inertia::render('Inventory/Packaging/Index', ['records'=>$records, 'filters'=>$request->only(['status','search']), 'canReview'=>$reviewer, 'canSubmit'=>$user->can(AuthorizationKeys::InventoryPackagingSubmit->value)]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeSubmit($request);
        return Inertia::render('Inventory/Packaging/Form', [
            'record'=>null,
            'warehouses'=>$this->warehouses(),
            'packagingPresets'=>$this->presets->all(),
            'packagingSop'=>null,
        ]);
    }

    public function show(Request $request, PackagingMeasurementRecord $record): Response
    {
        $this->authorizeRead($request, $record);
        $record->load(['product:id,sku,name','warehouse:id,name','creator:id,name','reviewer:id,name','media']);
        $profile = $this->activeProfile($record->product_id);
        return Inertia::render('Inventory/Packaging/Show', [
            'record'=>$this->detail($record),
            'packagingSop'=>$this->guideForMethod($record->packaging_method_key, $profile),
            'packagingPresets'=>$this->presets->all(),
            'currentStandardRecordId'=>$profile?->current_measurement_record_id,
            'isCurrentStandard'=>(int) ($profile?->current_measurement_record_id ?? 0) === (int) $record->id,
            'canAdoptStandard'=>$record->status === 'approved' && $request->user()->can(AuthorizationKeys::InventoryPackagingReview->value),
            'canReview'=>$request->user()->can(AuthorizationKeys::InventoryPackagingReview->value),
            'canEdit'=>$record->status === 'draft' && $record->created_by === $request->user()->getAuthIdentifier(),
        ]);
    }

    public function edit(Request $request, PackagingMeasurementRecord $record): Response
    {
        $this->authorizeOwnerDraft($request, $record);
        $record->load(['product:id,sku,name','warehouse:id,name','media']);
        $profile = $this->activeProfile($record->product_id);
        return Inertia::render('Inventory/Packaging/Form', [
            'record'=>$this->detail($record),
            'warehouses'=>$this->warehouses(),
            'packagingPresets'=>$this->presets->all(),
            'packagingSop'=>$profile && $this->hasPackagingGuide($profile) ? $this->packagingProfile($profile) : null,
        ]);
    }

    public function productGuide(Request $request, Product $product): JsonResponse
    {
        $this->authorizeSubmit($request);
        $profile = $this->activeProfile($product->id);
        return response()->json([
            'presets'=>$this->presets->all(),
            'packaging_sop'=>$profile && $this->hasPackagingGuide($profile) ? $this->packagingProfile($profile) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeSubmit($request);
        $record = PackagingMeasurementRecord::create([...$this->validated($request, false), 'status'=>'draft', 'created_by'=>$request->user()->getAuthIdentifier()]);
        $this->audit($request, 'packaging_measurement.created', $record, 'packaging_measurement_created');
        return redirect()->route('inventory.packaging.edit', $record)->with('success', '草稿已保存，可以继续上传证据');
    }

    public function update(Request $request, PackagingMeasurementRecord $record): RedirectResponse
    {
        $this->authorizeOwnerDraft($request, $record);
        $record->update($this->validated($request, false));
        $this->audit($request, 'packaging_measurement.updated', $record, 'packaging_measurement_updated');
        return back()->with('success', '草稿已更新');
    }

    public function upload(Request $request, PackagingMeasurementRecord $record): RedirectResponse
    {
        $this->authorizeOwnerDraft($request, $record);
        $data = $request->validate(['kind'=>['required', Rule::in(['packaging','weight','measurement'])], 'files'=>['required','array','min:1'], 'files.*'=>['required','file','image','mimes:jpeg,jpg,png,webp','max:10240']]);
        $stored = [];
        try {
            DB::transaction(function () use ($request, $record, $data, &$stored): void {
                foreach ($request->file('files', []) as $file) {
                    $path = $file->store('inventory/packaging-evidence/'.$record->id.'/'.$data['kind'], 'local');
                    $stored[] = $path;
                    $record->media()->create(['kind'=>$data['kind'], 'disk'=>'local', 'path'=>$path, 'original_name'=>$file->getClientOriginalName(), 'mime_type'=>$file->getMimeType(), 'size_bytes'=>$file->getSize(), 'sort_order'=>$record->media()->where('kind', $data['kind'])->count()]);
                }
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) Storage::disk('local')->delete($path);
            throw $e;
        }
        return back()->with('success', '证据图片已上传');
    }

    public function removeMedia(Request $request, PackagingMeasurementMedia $media): RedirectResponse
    {
        $this->authorizeOwnerDraft($request, $media->record()->firstOrFail());
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
        return back()->with('success', '图片已删除');
    }

    public function submit(Request $request, PackagingMeasurementRecord $record): RedirectResponse
    {
        $this->authorizeOwnerDraft($request, $record);
        $data = $this->validated($request, true);
        abort_unless($record->media()->where('kind','measurement')->exists() && $record->media()->where('kind','weight')->exists(), 422, '提交前必须上传尺寸测量图和称重记录图');
        $record->update([...$data, 'status'=>'submitted', 'submitted_at'=>now()]);
        $this->audit($request, 'packaging_measurement.submitted', $record, 'packaging_measurement_submitted');
        $record->loadMissing('product:id,sku,name');
        $this->notifications->publishToPermission(AuthorizationKeys::InventoryPackagingReview->value, [
            'category'=>'inventory',
            'title'=>'新的包装测量待审核',
            'message'=>$record->product->sku.' 已提交包装尺寸与重量证据。',
            'action_url'=>'/inventory/packaging/'.$record->id,
            'entity_type'=>'packaging_measurement',
            'entity_id'=>$record->id,
            'actor_name'=>$request->user()->name,
        ], (int) $request->user()->getAuthIdentifier());
        return redirect()->route('inventory.packaging.show', $record)->with('success', '记录已提交审核');
    }

    public function review(Request $request, PackagingMeasurementRecord $record): RedirectResponse
    {
        abort_unless($record->status === 'submitted', 422, '只有待审核记录可以审核');
        $data = $request->validate(['decision'=>['required', Rule::in(['approved','rejected'])], 'review_note'=>['nullable','string','max:5000']]);
        $record->update(['status'=>$data['decision'], 'review_note'=>$data['review_note'] ?? null, 'reviewed_by'=>$request->user()->getAuthIdentifier(), 'reviewed_at'=>now()]);
        $this->audit($request, 'packaging_measurement.'.$data['decision'], $record, 'packaging_measurement_'.$data['decision']);
        $record->loadMissing(['product:id,sku,name','creator:id,name']);
        if ($record->creator && (int) $record->creator->id !== (int) $request->user()->getAuthIdentifier()) {
            $approved = $data['decision'] === 'approved';
            $this->notifications->publishToUser($record->creator, [
                'category'=>'inventory',
                'title'=>$approved ? '包装测量已批准' : '包装测量已驳回',
                'message'=>$record->product->sku.($approved ? ' 的包装尺寸与重量证据已通过审核。' : ' 的包装尺寸与重量证据需要调整。'),
                'action_url'=>'/inventory/packaging/'.$record->id,
                'required_permission'=>AuthorizationKeys::InventoryPackagingSubmit->value,
                'entity_type'=>'packaging_measurement',
                'entity_id'=>$record->id,
                'related_links'=>[['label'=>'包装记录','value'=>'#'.$record->id,'url'=>'/inventory/packaging/'.$record->id], ['label'=>'相关产品','value'=>$record->product->sku,'url'=>'/products/'.$record->product_id]],
                'related_links'=>[['label'=>'包装记录','value'=>'#'.$record->id,'url'=>'/inventory/packaging/'.$record->id], ['label'=>'相关产品','value'=>$record->product->sku,'url'=>'/products/'.$record->product_id]],
                'actor_name'=>$request->user()->name,
            ]);
        }
        return back()->with('success', $data['decision'] === 'approved' ? '已批准' : '已驳回');
    }

    public function adoptStandard(Request $request, PackagingMeasurementRecord $record): RedirectResponse
    {
        abort_unless($record->status === 'approved', 422, '只有已批准记录可以设为当前包装标准');
        DB::transaction(function () use ($request, $record): void {
            $profile = ProductPackagingProfile::query()->firstOrNew(['product_id'=>$record->product_id]);
            if (! $profile->exists && in_array($record->packaging_method_key, $this->presets->keys(), true)) {
                $profile->preset_key = $record->packaging_method_key;
            }
            if (! $profile->exists && $record->packaging_method_key === 'custom') {
                $profile->custom_method = '定制包装方案';
            }
            $profile->current_measurement_record_id = $record->id;
            $profile->is_active = true;
            $profile->save();
            $this->audit->record(
                AuditActor::user($request->user(), AuditSource::Web),
                'packaging_measurement.adopted_as_current_standard',
                $record::class, $record->id,
                after: ['product_id'=>$record->product_id, 'current_measurement_record_id'=>$record->id],
                reason: 'packaging_measurement_current_standard_adopted',
            );
        });
        return back()->with('success', '已设为该产品当前包装标准；后续订单/履约将自动调用');
    }

    public function media(Request $request, PackagingMeasurementMedia $media)
    {
        $this->authorizeRead($request, $media->record()->firstOrFail());
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);
        return Storage::disk($media->disk)->response($media->path, $media->original_name, ['Content-Type'=>$media->mime_type, 'Cache-Control'=>'private, no-store'], 'inline');
    }

    private function validated(Request $request, bool $submit): array
    {
        $methodKeys = $this->presets->keys();
        $methodKeys[] = 'custom';
        return $request->validate([
            'product_id'=>['required','integer','exists:products,id'],
            'warehouse_id'=>['nullable','integer','exists:warehouses,id'],
            'packaging_method_key'=>['nullable','string',Rule::in($methodKeys)],
            'packaging_length_cm'=>[$submit?'required':'nullable','numeric','min:0.01','max:99999999.99'],
            'packaging_width_cm'=>[$submit?'required':'nullable','numeric','min:0.01','max:99999999.99'],
            'packaging_height_cm'=>[$submit?'required':'nullable','numeric','min:0.01','max:99999999.99'],
            'packaged_weight_kg'=>[$submit?'required':'nullable','numeric','min:0.001','max:9999999.999'],
            'measured_at'=>[$submit?'required':'nullable','date'],
            'note'=>['nullable','string','max:5000'],
        ]);
    }

    private function authorizeSubmit(Request $request): void { abort_unless($request->user()->can(AuthorizationKeys::InventoryPackagingSubmit->value), 403); }
    private function authorizeOwnerDraft(Request $request, PackagingMeasurementRecord $record): void { $this->authorizeSubmit($request); abort_unless($record->created_by === $request->user()->getAuthIdentifier() && $record->status === 'draft', 403); }
    private function authorizeRead(Request $request, PackagingMeasurementRecord $record): void { $user=$request->user(); abort_unless($user->can(AuthorizationKeys::InventoryPackagingReview->value) || $user->can(AuthorizationKeys::InventoryView->value) || ($user->can(AuthorizationKeys::InventoryPackagingSubmit->value) && $record->created_by === $user->getAuthIdentifier()), 403); }
    private function audit(Request $request, string $action, PackagingMeasurementRecord $record, string $reason): void { $this->audit->record(AuditActor::user($request->user(), AuditSource::Web), $action, $record::class, $record->id, after: ['status'=>$record->status], reason: $reason); }
    private function warehouses() { return Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id','name']); }
    private function activeProfile(int $productId): ?ProductPackagingProfile { return ProductPackagingProfile::query()->with('media')->where('product_id', $productId)->where('is_active', true)->first(); }

    private function summary(PackagingMeasurementRecord $r): array
    {
        return [
            'id'=>$r->id,'status'=>$r->status,'packaging_method_key'=>$r->packaging_method_key,
            'product'=>['id'=>$r->product->id,'sku'=>$r->product->sku,'name'=>$r->product->name],
            'warehouse'=>$r->warehouse?->name,'operator'=>$r->creator?->name,'measured_at'=>$r->measured_at?->toIso8601String(),
            'dimensions'=>implode(' × ',[$r->packaging_length_cm,$r->packaging_width_cm,$r->packaging_height_cm]).' cm',
            'packaged_weight_kg'=>$r->packaged_weight_kg,'media_count'=>$r->media->count(),
        ];
    }

    private function detail(PackagingMeasurementRecord $r): array
    {
        return [...$this->summary($r), 'warehouse_id'=>$r->warehouse_id,'packaging_length_cm'=>$r->packaging_length_cm,
            'packaging_width_cm'=>$r->packaging_width_cm,'packaging_height_cm'=>$r->packaging_height_cm,'packaged_weight_kg'=>$r->packaged_weight_kg,
            'note'=>$r->note,'submitted_at'=>$r->submitted_at?->toIso8601String(),'reviewer'=>$r->reviewer?->name,'reviewed_at'=>$r->reviewed_at?->toIso8601String(),
            'review_note'=>$r->review_note,'media'=>$r->media->map(fn($m)=>['id'=>$m->id,'kind'=>$m->kind,'name'=>$m->original_name,'mime_type'=>$m->mime_type,'size_bytes'=>$m->size_bytes,'url'=>route('inventory.packaging.media',$m)])->values()];
    }

    private function guideForMethod(?string $methodKey, ?ProductPackagingProfile $profile): ?array
    {
        if ($methodKey === null || $methodKey === '') return null;
        if ($methodKey === 'custom') {
            return $profile && $profile->preset_key === null ? $this->packagingProfile($profile) : null;
        }
        if ($profile && $profile->preset_key === $methodKey) return $this->packagingProfile($profile);
        return $this->presets->guide($methodKey);
    }

    private function hasPackagingGuide(ProductPackagingProfile $profile): bool
    {
        return $profile->preset_key !== null
            || $profile->custom_method !== null
            || $profile->custom_materials !== null
            || $profile->custom_steps !== null
            || $profile->custom_tips !== null
            || ($profile->relationLoaded('media') ? $profile->media->isNotEmpty() : $profile->media()->exists());
    }

    private function packagingProfile(ProductPackagingProfile $profile): array
    {
        $base = $profile->preset_key ? $this->presets->guide($profile->preset_key) : null;
        $media = $profile->relationLoaded('media') ? $profile->media : $profile->media()->get();
        $defaultMedia = $media->firstWhere('kind', 'default');
        $renderedMedia = $media->firstWhere('kind', 'rendered');
        $uploadedExamples = $media->where('kind', 'example')->values()->map(fn ($item) => [
            'title'=>$item->original_name ?: '真实包装案例',
            'image_url'=>route('products.packaging.media', $item),
        ])->all();
        return [
            'id'=>$profile->id,
            'title'=>$base['title'] ?? '产品定制包装教程',
            'method'=>$base['method'] ?? $profile->custom_method ?? '定制包装方案',
            'preset_key'=>$profile->preset_key,
            'scenario'=>$profile->scenario ?: ($base['scenario'] ?? null),
            'version'=>$profile->version ?: ($base['version'] ?? 'custom-v1'),
            'default_illustration_url'=>$defaultMedia ? route('products.packaging.media', $defaultMedia) : ($base['default_illustration_url'] ?? null),
            'rendered_illustration_url'=>$renderedMedia ? route('products.packaging.media', $renderedMedia) : null,
            'rendered_illustration_name'=>$renderedMedia?->original_name,
            'materials'=>$profile->custom_materials ?? ($base['materials'] ?? []),
            'steps'=>$profile->custom_steps ?? ($base['steps'] ?? []),
            'tips'=>$profile->custom_tips ?? ($base['tips'] ?? []),
            'examples'=>$uploadedExamples,
        ];
    }
}
