<?php

namespace App\Modules\Orders\Application;

use App\Modules\Inventory\Application\InventoryReservationService;
use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\FulfillmentAllocationAccess;
use App\Modules\Logistics\Application\FulfillmentAllocationSubmissionService;
use App\Modules\Orders\Infrastructure\Models\Order;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A5-B: pending, multi-warehouse original fulfillment with one releasable
 * reservation per existing derived order. No posted/committed/shipped leg.
 *
 * Parent, children, shipment, allocation and all old holds are locked, checked
 * against a single optimistic revision, and mutated inside one DB transaction.
 * A new reservation is created for EACH rebuilt derived fulfillment order.
 *
 * We deliberately do not attempt to modify partially shipped/committed legs.
 */
final class OrderItemMultiWarehouseReplanService
{
    private const CODES = ['FACTORY', 'US-NY', 'US-LA', 'US-SM', 'DE', 'CA'];

    public function __construct(
        private readonly OrderAmendmentRevision $revisions,
        private readonly OrderService $orders,
        private readonly InventoryReservationService $reservations,
        private readonly FulfillmentAllocationSubmissionService $allocations,
        private readonly FulfillmentAllocationAccess $access,
    ) {}

    /**
     * @param list<array<string,mixed>> $items
     * @param list<array{item_index:int,source_code:string,carrier?:string|null,service?:string|null}> $assignments
     * @return array<string,mixed>
     */
    public function commit(
        Order $order,
        array $items,
        array $assignments,
        string $expectedRevision,
        string $reason,
        Authenticatable $actor,
    ): array {
        abort_unless($actor->can('orders.edit') && $this->access->allows($actor)
            && ($actor->can('inventory.operate') || $actor->can('logistics.operate')),
            403, '缺少修改订单、分仓或库存预留的操作权限。');
        if (strlen($expectedRevision) !== 64 || ! ctype_xdigit($expectedRevision)) {
            throw ValidationException::withMessages(['expected_revision' => '预检凭证无效，请重新检查订单。']);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 4 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => '请填写 4–500 字的客户变更原因。']);
        }
        if (count($items) < 2 || count($items) !== count($assignments)) {
            throw ValidationException::withMessages(['assignments' => '多仓事务要求至少两条商品，每条商品恰好选择一个发货仓。']);
        }
        $indexed = [];
        foreach ($assignments as $row) {
            $index = $row['item_index'] ?? null;
            if (! is_int($index) || $index < 0 || $index >= count($items) || isset($indexed[$index])) {
                throw ValidationException::withMessages(['assignments' => '商品行序号重复、缺失或无效，必须逐项选择仓库。']);
            }
            $code = strtoupper(trim((string) ($row['source_code'] ?? '')));
            if (! in_array($code, self::CODES, true)) {
                throw ValidationException::withMessages(["assignments.{$index}.source_code" => '仓库不在允许的发货来源列表。']);
            }
            $indexed[$index] = [
                'item_index' => $index, 'source_code' => $code,
                'carrier' => trim((string) ($row['carrier'] ?? '')),
                'service' => $row['service'] ?? null,
            ];
        }
        if (count($indexed) !== count($items)) {
            throw ValidationException::withMessages(['assignments' => '有商品未选择仓库。']);
        }
        ksort($indexed);
        $codes = collect($indexed)->pluck('source_code')->unique()->values();
        if ($codes->count() < 2 || $codes->count() > 26) {
            throw ValidationException::withMessages(['assignments' => 'A5-B 仅适用至少两个发货仓的订单，多仓变单仓请使用独立审核。']);
        }
        $warehouses = Warehouse::query()->where('is_active', true)
            ->whereIn('code', $codes)->get()->keyBy('code');
        if ($warehouses->count() !== $codes->count()) {
            throw ValidationException::withMessages(['assignments' => '有仓库已停用，请重新选择。']);
        }

        try {
            return DB::transaction(function () use (
                $order, $items, $indexed, $expectedRevision, $reason, $actor,
                $warehouses,
            ): array {
                $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
                $locked->items()->orderBy('id')->lockForUpdate()->get();
                $locked->shipment()->lockForUpdate()->first();
                $allocation = $locked->fulfillmentAllocation()->lockForUpdate()->first();
                $children = $locked->fulfillmentChildren()->orderBy('id')->lockForUpdate()->get();
                $children->each(function (Order $child): void {
                    $child->items()->orderBy('id')->lockForUpdate()->get();
                    $child->shipment()->lockForUpdate()->first();
                });

                $orderIds = $children->pluck('id')->push($locked->id)->all();
                $held = InventoryReservation::query()->whereIn('order_id', $orderIds)
                    ->whereIn('status', ['reserved', 'committed'])
                    ->orderBy('id')->lockForUpdate()->get();

                abort_unless(hash_equals($this->revisions->forOrder($locked), $expectedRevision),
                    409, '订单商品、子履约或库存预留发生变化，请重新预检。');
                abort_unless($locked->order_status?->value === 'pending_shipment'
                    && $locked->fulfillment_parent_id === null
                    && in_array($locked->source_type, [OrderSourceType::Manual, OrderSourceType::ManualOther], true)
                    && ! $locked->externalReferences()->where('provider', 'woocommerce')->exists(),
                    409, '此类订单需要先完成外部商城或履约状态审核。');
                abort_if($locked->financials()->exists() || $locked->costEntries()->exists()
                    || $locked->procurementOrder()->exists() || $locked->shipmentAttempts()->exists()
                    || $locked->manualSync()->exists(),
                    409, '订单已经有财务、采购或真实物流记录，不能直接重规划。');
                $parentShipment = $locked->shipment;
                abort_if($parentShipment && (
                    $parentShipment->shipment_status !== 'pending'
                    || filled($parentShipment->tracking_number) || $parentShipment->shipped_at !== null
                ), 409, '原订单已进入物流，不能覆盖。');
                abort_unless($allocation && $allocation->status === 'submitted'
                    && count((array) ($allocation->plan_snapshot['segments'] ?? [])) >= 2,
                    409, '原订单不是已提交的多仓分仓方案。');
                // A reduction in segment count would hard-delete a historical
                // child and potentially its released reservation evidence.
                // Preserve that history until a dedicated retirement protocol
                // exists; support same-count replacements and expansion only.
                abort_if($warehouses->count() < $children->count(), 409,
                    '减少发货仓段数可能删除既有子履约与库存释放记录，当前禁止，请使用履约历史保全流程。');
                abort_if($children->count() < 2
                    || $children->contains(fn (Order $child) =>
                        $child->fulfillment_role !== 'original' || $child->order_kind?->value !== 'fulfillment_derived'),
                    409, '拆分履约结构不完整或含补发记录，不能直接重建。');

                foreach ($children as $child) {
                    abort_if($child->order_status?->value !== 'pending_shipment'
                        || $child->shipmentAttempts()->exists() || $child->manualSync()->exists()
                        || $child->financials()->exists() || $child->costEntries()->exists()
                        || $child->procurementOrder()->exists()
                        || $child->replacementFulfillments()->exists()
                        || $child->logisticsReview()->exists(),
                        409, "子履约 {$child->erp_order_id} 已进入其他流程，不能改写。");
                    $shipment = $child->shipment;
                    abort_if(! $shipment || $shipment->shipment_status !== 'pending'
                        || filled($shipment->tracking_number) || $shipment->shipped_at !== null
                        || $shipment->overseaReview()->exists(),
                        409, "子履约 {$child->erp_order_id} 已进入实际物流或仓库审核。");
                }
                abort_if($held->contains(fn ($r) => $r->status === 'committed'),
                    409, '有子履约库存已经扣减，不允许重建。');
                abort_if($held->where('order_id', $locked->id)->isNotEmpty(),
                    409, '父订单存在库存预留，多仓子履约与父订单库存状态冲突。');
                abort_unless($held->count() === $children->count(),
                    409, '必须每个原子履约恰好一笔可释放的活动预留。');
                foreach ($children as $child) {
                    $holds = $held->where('order_id', $child->id)->values();
                    abort_unless($holds->count() === 1 && $holds->first()->status === 'reserved'
                        && (int) $holds->first()->warehouse_id === (int) $child->shipment->warehouse_id,
                        409, "子履约 {$child->erp_order_id} 的库存预留与发货仓不一致。");
                }

                foreach ($held as $reservation) {
                    $this->reservations->release((int) $reservation->id, $actor, 'order_item_multi_atomic_replan');
                }

                // The parent item identity is preserved; rebuilt pending derived
                // rows get new IDs only after all old reserved inventory is freed.
                $saved = $this->orders->amendItemsWithinAtomicReplan($locked, $items, $actor);
                $existingIds = collect($items)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
                $createdIds = $saved->items()->whereNotIn('id', $existingIds)->orderBy('id')->pluck('id')->all();
                $newCursor = 0;
                $planned = [];
                foreach ($items as $index => $item) {
                    $id = $item['id'] ?? null;
                    if ($id === null || $id === '') {
                        $id = $createdIds[$newCursor++] ?? null;
                    }
                    abort_unless($id !== null, 409, '新商品行没有生成，事务已回滚。');
                    $choice = $indexed[$index];
                    $planned[] = [
                        'order_item_id' => (int) $id,
                        'source_code' => $choice['source_code'],
                        'carrier' => $choice['carrier'],
                        'service' => $choice['service'],
                    ];
                }
                $newAllocation = $this->allocations->submitManual(
                    $saved, $planned, '多仓商品重规划：'.$reason, $actor
                );

                $freshChildren = $saved->fresh()->originalFulfillmentChildren()
                    ->with(['items', 'shipment'])->orderBy('id')->get();
                abort_unless($freshChildren->count() === $warehouses->count(),
                    409, '新的多仓拆分结果数量不一致，事务回滚。');
                $newReservations = [];
                foreach ($freshChildren as $child) {
                    $wh = $warehouses->first(fn (Warehouse $wh) => (int) $wh->id === (int) $child->shipment?->warehouse_id);
                    abort_unless($wh && $child->items->isNotEmpty()
                        && $child->order_status?->value === 'pending_shipment'
                        && $child->shipment?->shipment_status === 'pending',
                        409, '子履约目标发货仓与新分仓方案不一致，事务回滚。');
                    $key = 'amend-multi-'.substr(hash('sha256', implode('|', [
                        $locked->id, $expectedRevision, $newAllocation->selected_plan_key,
                        $child->id, $wh->id, $actor->getAuthIdentifier(),
                    ])), 0, 51);
                    $reserved = $this->reservations->reserve((int) $child->id, (int) $wh->id, $key, null, $actor);
                    $newReservations[] = [
                        'order_id' => (int) $child->id,
                        'reservation_id' => (int) $reserved['id'],
                        'warehouse_id' => (int) $wh->id,
                    ];
                }

                return [
                    'parent_order_id' => (int) $locked->id,
                    'previous_reservation_ids' => $held->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    'new_reservations' => $newReservations,
                    'allocation_id' => (int) $newAllocation->id,
                    'state' => 'pending_multi_leg_review',
                    'has_real_shipment' => false,
                ];
            }, 3);
        } catch (DomainException $e) {
            throw ValidationException::withMessages([
                'items' => '新仓库库存预留失败：'.$e->getMessage().'；整次操作已回滚，旧预留未变化。',
            ]);
        }
    }
}
