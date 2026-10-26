<?php

namespace App\Modules\Orders\Application;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * A4 read-only preview. This is intentionally NOT a reservation, shipment
 * amendment, or authorization to dispatch. Every committing write must be
 * revalidated inside a separate locked transaction.
 */
final class OrderItemAmendmentPreviewService
{
    public function __construct(
        private readonly InventoryAvailabilityReader $inventory,
        private readonly OrderAmendmentRevision $revisions,
    ) {}

    /**
     * @param list<array{id?:int|null, product_id:int, quantity:int, unit_price:string|int|float, product_summary?:string|null}> $proposed
     * @return array<string,mixed>
     */
    public function preview(Order $order, array $proposed): array
    {
        $order->loadMissing(['items', 'shipment', 'fulfillmentAllocation', 'fulfillmentChildren.shipment']);
        $existing = $order->items->keyBy('id');
        $productIds = collect($proposed)->pluck('product_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $rows = [];
        $seen = [];
        $required = [];
        $oldGross = 0.0;
        $newGross = 0.0;

        foreach ($order->items as $line) {
            $oldGross += (int) $line->quantity * (float) $line->unit_price;
        }
        foreach ($proposed as $index => $next) {
            $id = ! empty($next['id']) ? (int) $next['id'] : null;
            if ($id === null && $existing->count() === 1 && count($proposed) === 1) {
                $id = (int) $existing->first()->id;
            }
            if ($id !== null && (! $existing->has($id) || isset($seen[$id]))) {
                throw ValidationException::withMessages(["items.{$index}.id" => '订单商品 ID 不属于当前订单或重复，请刷新后检查。']);
            }
            if ($id !== null) $seen[$id] = true;
            $product = $products->get((int) $next['product_id']);
            if (! $product || trim((string) $product->sku) === '') {
                throw ValidationException::withMessages(["items.{$index}.product_id" => '请选择产品目录中存在 SKU 的真实商品。']);
            }
            $old = $id !== null ? $existing->get($id) : null;
            $qty = (int) $next['quantity'];
            $price = round((float) $next['unit_price'], 2);
            $summary = trim((string) ($next['product_summary'] ?? ''));
            $summary = $summary === '' ? (string) $product->name : $summary;
            $newGross += $qty * $price;
            $required[(int) $product->id] = ($required[(int) $product->id] ?? 0) + $qty;
            $changed = ! $old
                || (int) $old->product_id !== (int) $product->id
                || (string) $old->sku !== (string) $product->sku
                || (int) $old->quantity !== $qty
                || abs((float) $old->unit_price - $price) > 0.001
                || (string) $old->product_summary !== $summary;

            $rows[] = [
                'order_item_id' => $id,
                'operation' => $old ? ($changed ? 'update' : 'unchanged') : 'add',
                'before' => $old ? [
                    'sku' => $old->sku,
                    'product_id' => $old->product_id,
                    'quantity' => (int) $old->quantity,
                    'unit_price' => $old->unit_price,
                ] : null,
                'after' => [
                    'sku' => (string) $product->sku,
                    'product_id' => (int) $product->id,
                    'quantity' => $qty,
                    'unit_price' => number_format($price, 2, '.', ''),
                ],
            ];
        }
        foreach ($order->items as $old) {
            if (isset($seen[(int) $old->id])) continue;
            $rows[] = [
                'order_item_id' => (int) $old->id,
                'operation' => 'remove',
                'before' => [
                    'sku' => $old->sku, 'product_id' => $old->product_id,
                    'quantity' => (int) $old->quantity, 'unit_price' => $old->unit_price,
                ],
                'after' => null,
            ];
        }

        $changed = collect($rows)->contains(fn (array $row): bool => $row['operation'] !== 'unchanged');
        $reservations = InventoryReservation::query()->where('order_id', $order->id)
            ->whereIn('status', ['reserved', 'committed'])
            ->orderBy('id')->get(['id', 'warehouse_id', 'status']);
        $children = $order->fulfillmentChildren;
        $shipment = $order->shipment;
        $actuallyStarted = $order->order_status?->value !== 'pending_shipment'
            || ($shipment && ($shipment->shipment_status !== 'pending'
                || filled($shipment->tracking_number) || $shipment->shipped_at !== null))
            || $children->contains(function (Order $child): bool {
                $s = $child->shipment;
                return $child->order_status?->value !== 'pending_shipment'
                    || ($s && ($s->shipment_status !== 'pending' || filled($s->tracking_number) || $s->shipped_at !== null));
            });
        $linked = $order->fulfillmentAllocation !== null || $children->isNotEmpty();
        $hasActiveReservation = $reservations->isNotEmpty();
        $issues = [];
        if ($actuallyStarted) $issues[] = '订单或部分履约已进入实际物流，不能修改已发货商品，请使用补发或售后流程。';
        $externalOrder = ! in_array($order->source_type, [OrderSourceType::Manual, OrderSourceType::ManualOther], true)
            || $order->externalReferences()->where('provider', 'woocommerce')->exists();
        $financialized = $order->financials()->exists() || $order->costEntries()->exists();
        if ($externalOrder) $issues[] = '外部商城来源商品变更需先建立官网与 ERP 一致性契约。';
        if ($financialized) $issues[] = '订单已有财务成本或结算记录，需先完成财务调整契约。';
        if ($order->fulfillment_parent_id !== null) $issues[] = '衍生履约订单不能直接修改商品，请操作原订单。';
        if ($order->procurementOrder()->exists()) $issues[] = '订单已关联采购单，需先处理采购影响。';
        if ($linked) $issues[] = '现有分仓/拆单需要重新审核并重建，不得直接复用旧分仓。';
        if ($hasActiveReservation) $issues[] = '已存在库存预留或扣减；必须受控释放旧预留并核实新库存，禁止直接覆盖。';

        $availability = $this->inventory->forProductIds($productIds);
        $warehouses = $this->inventory->activeWarehouses();
        $stock = [];
        foreach ($warehouses as $wh) {
            $warehouseId = (int) $wh['id'];
            $details = [];
            $allEnough = $required !== [];
            foreach ($required as $productId => $quantity) {
                $candidate = collect($availability[$productId] ?? [])->first(
                    fn (array $row): bool => (int) ($row['warehouse_id'] ?? 0) === $warehouseId
                );
                $available = $candidate['quantity_available'] ?? null;
                $enough = $available !== null && (int) $available >= $quantity
                    && (bool) ($wh['can_auto_allocate'] ?? false);
                if (! $enough) $allEnough = false;
                $details[] = [
                    'product_id' => $productId,
                    'required' => $quantity,
                    'available' => $available,
                    'enough' => $enough,
                ];
            }
            $stock[] = [
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $wh['name'],
                'warehouse_code' => (string) ($wh['code'] ?? ''),
                'single_warehouse_candidate' => $allEnough,
                'details' => $details,
            ];
        }

        $singleAtomicEligible = $changed
            && ! $externalOrder && ! $financialized
            && $order->fulfillment_parent_id === null
            && $order->order_status?->value === 'pending_shipment'
            && ! $actuallyStarted
            && $children->isEmpty()
            && $order->fulfillmentAllocation?->status === 'submitted'
            && count((array) ($order->fulfillmentAllocation?->plan_snapshot['segments'] ?? [])) === 1
            && $reservations->count() === 1
            && $reservations->first()?->status === 'reserved'
            && $shipment !== null
            && (int) $shipment->warehouse_id === (int) $reservations->first()?->warehouse_id
            && ! $order->procurementOrder()->exists()
            && ! $order->shipmentAttempts()->exists();

        // Multi-warehouse is a separate, stricter protocol. Every existing
        // child must remain pending with exactly one RESERVED hold matching its
        // shipment warehouse. Partial shipment/committed stock fail closed.
        $childIds = $children->pluck('id')->all();
        $multiHeld = InventoryReservation::query()->whereIn('order_id', [...$childIds, $order->id])
            ->whereIn('status', ['reserved', 'committed'])->get();
        $multiChildSafe = $children->count() >= 2
            && $children->every(function (Order $child): bool {
                $shipment = $child->shipment;
                return $child->fulfillment_role === 'original'
                    && $child->order_status?->value === 'pending_shipment'
                    && $child->order_kind?->value === 'fulfillment_derived'
                    && $shipment !== null && $shipment->shipment_status === 'pending'
                    && blank($shipment->tracking_number) && $shipment->shipped_at === null
                    && ! $child->shipmentAttempts()->exists()
                    && ! $child->manualSync()->exists()
                    && ! $child->financials()->exists()
                    && ! $child->costEntries()->exists()
                    && ! $child->procurementOrder()->exists()
                    && ! $child->replacementFulfillments()->exists()
                    && ! $child->logisticsReview()->exists()
                    && ! $shipment->overseaReview()->exists()
                    && $multiHeld->where('order_id', $child->id)->count() === 1
                    && $multiHeld->where('order_id', $child->id)->first()?->status === 'reserved'
                    && (int) $multiHeld->where('order_id', $child->id)->first()?->warehouse_id
                        === (int) $shipment->warehouse_id;
            });
        $multiEligible = $changed
            && ! $externalOrder && ! $financialized && ! $actuallyStarted
            && $order->order_status?->value === 'pending_shipment'
            && $order->fulfillment_parent_id === null
            && ! $order->procurementOrder()->exists()
            && ! $order->shipmentAttempts()->exists()
            && ! $order->manualSync()->exists()
            && $order->fulfillmentAllocation?->status === 'submitted'
            && count((array) ($order->fulfillmentAllocation?->plan_snapshot['segments'] ?? [])) >= 2
            && ! $multiHeld->contains(fn ($held) => $held->status === 'committed')
            && $multiHeld->where('order_id', $order->id)->isEmpty()
            && $multiHeld->count() === $children->count()
            && $multiChildSafe;

        return [
            'order_id' => (int) $order->id,
            'expected_revision' => $this->revisions->forOrder($order),
            'changed' => $changed,
            'rows' => $rows,
            'required_by_product' => $required,
            'old_items_gross' => number_format($oldGross, 2, '.', ''),
            'new_items_gross' => number_format($newGross, 2, '.', ''),
            'gross_delta' => number_format($newGross - $oldGross, 2, '.', ''),
            'currency' => (string) ($order->currency ?: 'USD'),
            'active_reservations' => $reservations->map(fn ($r) => [
                'id' => (int) $r->id, 'warehouse_id' => (int) $r->warehouse_id, 'status' => $r->status,
            ])->values()->all(),
            'requires_replan' => $linked || $hasActiveReservation,
            'can_atomic_single_replan' => $singleAtomicEligible,
            'can_atomic_multi_replan' => $multiEligible,
            'can_save_direct' => ! $changed || $issues === [],
            'issues' => $issues,
            'stock_by_warehouse' => $stock,
            'inventory_note' => '仅为当前库存快照，不保证提交时仍有库存；多仓拆分须由正式分仓流程独立确认。',
            'no_writes' => true,
        ];
    }
}
