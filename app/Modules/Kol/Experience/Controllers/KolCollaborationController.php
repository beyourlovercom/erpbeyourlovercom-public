<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Application\Approval\CollaborationApprovalService;
use App\Modules\Kol\Application\KolScope;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolCollaboration;
use App\Modules\Kol\Infrastructure\Models\KolOpportunity;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Application\OrderIdentityService;
use App\Modules\Orders\Application\OrderSourceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class KolCollaborationController
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly OrderService $orders,
        private readonly OrderIdentityService $identities,
        private readonly CollaborationApprovalService $approvals,
    ) {}

    public function index(Request $request): Response
    {
        $query = KolCollaboration::query()->with(['kol:id,display_name,owner_id', 'owner:id,name', 'products', 'order.shipment.warehouse', 'order.manualSync']);
        if (! KolScope::isAllScope($request->user())) {
            $query->whereHas('kol', fn ($q) => $q->where('owner_id', (int) $request->user()->getAuthIdentifier()));
        }
        if ($status = $request->string('status')->trim()->value()) {
            $query->where('status', $status);
        }
        if ($approvalStatus = $request->string('approval_status')->trim()->value()) {
            $query->where('approval_status', $approvalStatus);
        }
        if ($request->string('post_url_status')->value() === 'missing') {
            $query->where(fn ($q) => $q->whereNull('post_url')->orWhere('post_url', ''));
        } elseif ($request->string('post_url_status')->value() === 'present') {
            $query->whereNotNull('post_url')->where('post_url', '!=', '');
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search): void {
                $q->where('post_url', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhereHas('kol', fn ($k) => $k->where('display_name', 'like', "%{$search}%"));
            });
        }

        return Inertia::render('Kol/Collaborations', [
            'collaborations' => $query->latest('id')->limit(100)->get()->map(fn (KolCollaboration $item) => $this->payload($item))->values()->all(),
            'filters' => $request->only(['search', 'status', 'post_url_status', 'approval_status']),
            'canOperate' => KolScope::canOperate($request->user()),
        ]);
    }

    public function store(Request $request, Kol $kol): JsonResponse
    {
        $this->authorizeKol($request, $kol);
        $data = $this->validated($request, $kol);
        $products = $data['products'] ?? [];
        unset($data['products']);
        if (! KolScope::isAllScope($request->user())) {
            $data['owner_id'] = (int) $request->user()->getAuthIdentifier();
        }
        $data['kol_id'] = $kol->id;

        $collaboration = DB::transaction(function () use ($data, $products, $request): KolCollaboration {
            $item = KolCollaboration::create($data);
            $this->replaceProducts($item, $products);
            $this->approvals->initialize($item, $request->user());
            return $item->refresh();
        });
        $this->recordAudit($request, 'kol.collaboration.created', $collaboration, null, $this->snapshot($collaboration));

        return response()->json(['collaboration' => $this->payload($collaboration->load(['owner', 'products.product', 'opportunity', 'order.shipment.warehouse', 'order.manualSync']))], 201);
    }

    public function update(Request $request, Kol $kol, KolCollaboration $collaboration): JsonResponse
    {
        $this->authorizeCollaboration($request, $kol, $collaboration);
        $before = $this->snapshot($collaboration);
        $data = $this->validated($request, $kol, $collaboration);
        $products = $data['products'] ?? [];
        unset($data['products']);
        if (! KolScope::isAllScope($request->user())) {
            $data['owner_id'] = (int) $request->user()->getAuthIdentifier();
        }

        DB::transaction(function () use ($collaboration, $data, $products, $before, $request): void {
            $collaboration->update($data);
            $this->replaceProducts($collaboration, $products);
            $this->approvals->reconcileAfterUpdate($collaboration, $before, $request->user());
        });
        $collaboration->refresh();
        $this->recordAudit($request, 'kol.collaboration.updated', $collaboration, $before, $this->snapshot($collaboration));

        return response()->json(['collaboration' => $this->payload($collaboration->load(['owner', 'products.product', 'opportunity', 'order.shipment.warehouse', 'order.manualSync']))]);
    }

    public function convertOpportunity(Request $request, Kol $kol, KolOpportunity $opportunity): JsonResponse
    {
        $this->authorizeKol($request, $kol);
        abort_unless((int) $opportunity->kol_id === (int) $kol->id, 404);
        if ($existing = KolCollaboration::where('opportunity_id', $opportunity->id)->first()) {
            return response()->json(['collaboration' => $this->payload($existing->load(['owner', 'products.product', 'opportunity', 'order.shipment.warehouse', 'order.manualSync'])), 'already_exists' => true]);
        }

        $productRows = Product::query()->whereIn('sku', $opportunity->target_products ?? [])->get()->map(fn (Product $p) => [
            'product_id' => $p->id,
            'sku' => $p->sku,
            'quantity' => 1,
        ])->all();
        $ownerId = KolScope::isAllScope($request->user()) ? ($opportunity->owner_id ?? $kol->owner_id) : (int) $request->user()->getAuthIdentifier();
        if ($opportunity->cooperation_type === '付费发帖' && (float) ($opportunity->estimated_cost ?? 0) <= 0) {
            abort(422, '付费合作必须先填写大于 0 的合作金额');
        }

        $collaboration = DB::transaction(function () use ($opportunity, $kol, $ownerId, $productRows, $request): KolCollaboration {
            $item = KolCollaboration::create([
                'kol_id' => $kol->id,
                'opportunity_id' => $opportunity->id,
                'owner_id' => $ownerId,
                'title' => $opportunity->title,
                'cooperation_type' => $opportunity->cooperation_type,
                'status' => 'confirmed',
                'agreement_amount' => $opportunity->estimated_cost,
                'currency' => $opportunity->currency ?: 'USD',
                'agreed_at' => now()->toDateString(),
                'planned_publish_at' => $opportunity->expected_publish_at,
                'notes' => $opportunity->notes,
            ]);
            $this->replaceProducts($item, $productRows);
            $this->approvals->initialize($item, $request->user());
            $opportunity->update(['stage' => 'confirmed', 'status' => 'completed']);
            return $item->refresh();
        });
        $this->recordAudit($request, 'kol.collaboration.created_from_opportunity', $collaboration, null, $this->snapshot($collaboration));

        return response()->json(['collaboration' => $this->payload($collaboration->load(['owner', 'products.product', 'opportunity', 'order.shipment.warehouse', 'order.manualSync']))], 201);
    }

    public function createFulfillmentOrder(Request $request, Kol $kol, KolCollaboration $collaboration): JsonResponse
    {
        $this->authorizeCollaboration($request, $kol, $collaboration);

        $result = DB::transaction(function () use ($request, $kol, $collaboration): array {
            $locked = KolCollaboration::query()->whereKey($collaboration->id)->lockForUpdate()->firstOrFail();
            if ($locked->order_id) {
                return ['order' => $locked->order()->with(['shipment.warehouse', 'manualSync'])->firstOrFail(), 'created' => false];
            }
            abort_unless((bool) $locked->fulfillment_required, 422, '该正式合作未标记需要寄样 / 发货');
            $locked->load('products');
            abort_if($locked->products->isEmpty(), 422, '创建履约订单前必须至少指定一个合作产品');

            $order = $this->orders->create([
                'source_type' => OrderSourceType::Kol->value,
                'order_kind' => OrderKind::KolFulfillment->value,
                'erp_order_id' => $this->identities->generate('k', $kol->id, now()->toDateString()),
                'external_order_number' => $this->orders->generateExternalOrderNumber(OrderKind::KolFulfillment),
                'order_date' => now()->toDateString(),
                'order_note' => 'KOL Collaboration #'.$locked->id,
                'currency' => $locked->currency ?: 'USD',
                'owner_id' => $locked->owner_id ?? $request->user()->getAuthIdentifier(),
                'recipient_name' => $kol->shipping_recipient_name,
                'phone' => $kol->contact_phone,
                'country' => $kol->shipping_country,
                'province_state' => $kol->shipping_province_state,
                'city' => $kol->shipping_city,
                'address_line_1' => $kol->shipping_address_line_1,
                'address_line_2' => $kol->shipping_address_line_2,
                'postal_code' => $kol->shipping_postal_code,
            ], $locked->products->map(fn ($row) => [
                'product_id' => $row->product_id,
                'product_summary' => $row->product_name_snapshot ?: $row->sku_snapshot,
                'quantity' => (int) $row->quantity,
                'unit_price' => '0.00',
            ])->all(), $request->user());

            $locked->update(['order_id' => $order->id]);
            return ['order' => $order->load(['shipment.warehouse', 'manualSync']), 'created' => true];
        });

        $fresh = $collaboration->fresh();
        $this->recordAudit($request, 'kol.collaboration.fulfillment_order_linked', $fresh, null, $this->snapshot($fresh));

        return response()->json([
            'order' => $this->fulfillmentOrderPayload($result['order']),
            'created' => $result['created'],
        ], $result['created'] ? 201 : 200);
    }

    private function validated(Request $request, Kol $kol, ?KolCollaboration $collaboration = null): array
    {
        $data = $request->validate([
            'opportunity_id' => ['nullable', 'integer', Rule::exists('kol_opportunities', 'id')->where(fn ($q) => $q->where('kol_id', $kol->id)), Rule::unique('kol_collaborations', 'opportunity_id')->ignore($collaboration?->id)],
            'owner_id' => 'nullable|integer|exists:users,id',
            'title' => 'nullable|string|max:255',
            'post_url' => 'nullable|url|max:2048',
            'cooperation_type' => 'nullable|string|in:免费发帖,长期合作,付费发帖',
            'status' => 'required|string|in:draft,confirmed,in_progress,completed,cancelled',
            'agreement_amount' => 'nullable|numeric|min:0|max:9999999999.99',
            'currency' => 'required|string|size:3',
            'agreed_at' => 'nullable|date',
            'planned_publish_at' => 'nullable|date',
            'deliverables' => 'nullable|string|max:5000',
            'fulfillment_required' => 'boolean',
            'notes' => 'nullable|string|max:5000',
            'products' => 'nullable|array|max:10',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.sku' => 'required|string|max:128',
            'products.*.quantity' => 'required|integer|min:1|max:9999',
        ]);
        if (($data['cooperation_type'] ?? null) === '付费发帖' && (float) ($data['agreement_amount'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['agreement_amount' => '付费合作必须填写大于 0 的合作金额。']);
        }
        return $data;
    }

    private function replaceProducts(KolCollaboration $collaboration, array $rows): void
    {
        $collaboration->products()->delete();
        foreach ($rows as $row) {
            $product = Product::findOrFail((int) $row['product_id']);
            abort_unless($product->sku === $row['sku'], 422, 'SKU 与 Product Master 不匹配');
            $collaboration->products()->create([
                'product_id' => $product->id,
                'sku_snapshot' => $product->sku,
                'product_name_snapshot' => $product->name,
                'quantity' => (int) $row['quantity'],
            ]);
        }
    }

    private function authorizeKol(Request $request, Kol $kol): void
    {
        if (! KolScope::isAllScope($request->user()) && $kol->owner_id !== (int) $request->user()->getAuthIdentifier()) abort(403);
    }

    private function authorizeCollaboration(Request $request, Kol $kol, KolCollaboration $item): void
    {
        $this->authorizeKol($request, $kol);
        abort_unless((int) $item->kol_id === (int) $kol->id, 404);
    }

    private function payload(KolCollaboration $item): array
    {
        return [
            'id' => $item->id,
            'kol_id' => $item->kol_id,
            'kol' => $item->relationLoaded('kol') && $item->kol ? ['id' => $item->kol->id, 'display_name' => $item->kol->display_name] : null,
            'opportunity_id' => $item->opportunity_id,
            'opportunity' => $item->relationLoaded('opportunity') && $item->opportunity ? ['id' => $item->opportunity->id, 'title' => $item->opportunity->title] : null,
            'owner_id' => $item->owner_id,
            'order_id' => $item->order_id,
            'owner' => $item->relationLoaded('owner') && $item->owner ? ['id' => $item->owner->id, 'name' => $item->owner->name] : null,
            'title' => $item->title,
            'post_url' => $item->post_url,
            'cooperation_type' => $item->cooperation_type,
            'status' => $item->status,
            'approval_status' => $item->approval_status,
            'approval_updated_at' => $item->approval_updated_at?->toIso8601String(),
            'approved_by_user_id' => $item->approved_by_user_id,
            'approved_at' => $item->approved_at?->toIso8601String(),
            'agreement_amount' => $item->agreement_amount !== null ? (float) $item->agreement_amount : null,
            'currency' => $item->currency,
            'agreed_at' => $item->agreed_at?->format('Y-m-d'),
            'planned_publish_at' => $item->planned_publish_at?->format('Y-m-d'),
            'deliverables' => $item->deliverables,
            'fulfillment_required' => (bool) $item->fulfillment_required,
            'notes' => $item->notes,
            'products' => $item->relationLoaded('products') ? $item->products->map(fn ($row) => [
                'id' => $row->id,
                'product_id' => $row->product_id,
                'sku' => $row->sku_snapshot,
                'name' => $row->product_name_snapshot,
                'quantity' => $row->quantity,
            ])->values()->all() : [],
            'fulfillment_order' => $item->relationLoaded('order') && $item->order ? $this->fulfillmentOrderPayload($item->order) : null,
            'created_at' => $item->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function snapshot(KolCollaboration $item): array
    {
        return ['kol_id' => $item->kol_id, 'opportunity_id' => $item->opportunity_id, 'owner_id' => $item->owner_id, 'order_id' => $item->order_id, 'status' => $item->status, 'cooperation_type' => $item->cooperation_type, 'agreement_amount' => $item->agreement_amount, 'currency' => $item->currency, 'approval_status' => $item->approval_status, 'post_url' => $item->post_url, 'fulfillment_required' => (bool) $item->fulfillment_required];
    }

    private function fulfillmentOrderPayload($order): array
    {
        return [
            'id' => $order->id,
            'internal_order_number' => $order->internal_order_number,
            'erp_order_id' => $order->erp_order_id,
            'external_order_number' => $order->external_order_number,
            'order_status' => $order->order_status instanceof \BackedEnum ? $order->order_status->value : (string) $order->order_status,
            'shipment' => $order->relationLoaded('shipment') && $order->shipment ? [
                'shipment_status' => $order->shipment->shipment_status instanceof \BackedEnum ? $order->shipment->shipment_status->value : (string) $order->shipment->shipment_status,
                'warehouse_name' => $order->shipment->warehouse?->name,
                'carrier' => $order->shipment->carrier,
                'service' => $order->shipment->service,
                'tracking_number' => $order->shipment->tracking_number,
                'shipped_at' => $order->shipment->shipped_at?->toIso8601String(),
            ] : null,
            'manual_sync_status' => $order->relationLoaded('manualSync') && $order->manualSync ? $order->manualSync->website_sync_status->value : null,
        ];
    }

    private function recordAudit(Request $request, string $action, KolCollaboration $item, ?array $before, ?array $after): void
    {
        $this->audit->record(AuditActor::user($request->user(), AuditSource::Web), $action, 'kol_collaboration', $item->id, $before, $after);
    }
}
