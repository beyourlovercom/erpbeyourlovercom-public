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
 * A5 initial commit contract: allocated SINGLE-warehouse customer/KOL orders
 * with uncommitted held stock and no real shipment or derived legs.
 *
 * Full multi-leg/partially shipped changes are deliberately rejected until
 * the separate immutable-leg protocol is complete. No HTTP side effects run
 * in this transaction; the old hold, order lines, allocation and new hold
 * commit together or roll back together.
 */
final class OrderItemAtomicReplanService
{
    public function __construct(
        private readonly OrderAmendmentRevision $revisions,
        private readonly OrderService $orders,
        private readonly InventoryReservationService $reservations,
        private readonly FulfillmentAllocationSubmissionService $allocations,
        private readonly FulfillmentAllocationAccess $allocationAccess,
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
        if (! $actor->can('orders.edit') || ! $this->allocationAccess->allows($actor)
            || (! $actor->can('inventory.operate') && ! $actor->can('logistics.operate'))) {
            abort(403, '订单商品与库存重规划需要订单、分仓及库存操作权限');
        }
        if (strlen($expectedRevision) !== 64 || ! ctype_xdigit($expectedRevision)) {
            throw ValidationException::withMessages(['expected_revision' => '变更预检凭证不正确，请重新检查订单。']);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 4 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => '请填写 4–500 字的客户沟通或更换商品原因。']);
        }
        if ($items === [] || count($items) !== count($assignments)) {
            throw ValidationException::withMessages(['items' => '变更商品行与新分仓方案必须逐项对应。']);
        }
        $indexed = [];
        foreach ($assignments as $assignment) {
            $index = $assignment['item_index'] ?? null;
            if (! is_int($index) || $index < 0 || $index >= count($items) || isset($indexed[$index])) {
                throw ValidationException::withMessages(['assignments' => '分仓方案必须对每条商品恰好指定一次仓库。']);
            }
            $indexed[$index] = $assignment;
        }
        if (count($indexed) !== count($items)) {
            throw ValidationException::withMessages(['assignments' => '存在未选择仓库的订单商品。']);
        }
        ksort($indexed);
        $codes = collect($indexed)->pluck('source_code')->map(fn ($code) => strtoupper(trim((string) $code)))->unique();
        if ($codes->count() !== 1) {
            throw ValidationException::withMessages(['assignments' => 'A5 第一阶段仅允许单仓重规划；多仓拆单请保持原方案，不能直接覆盖。']);
        }
        $newWarehouse = Warehouse::query()->where('code', $codes->first())->where('is_active', true)->first();
        if (! $newWarehouse) {
            throw ValidationException::withMessages(['assignments' => '选择的仓库已停用或不存在。']);
        }

        try {
            return DB::transaction(function () use (
                $order, $items, $indexed, $expectedRevision, $reason, $actor, $newWarehouse,
            ): array {
                $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
                // Order lock synchronizes with the reservation repository. Lock
                // specific mutable facts before comparing the optimistic token.
                $locked->items()->orderBy('id')->lockForUpdate()->get();
                $locked->shipment()->lockForUpdate()->first();
                $locked->fulfillmentAllocation()->lockForUpdate()->first();
                $held = InventoryReservation::query()->where('order_id', $locked->id)
                    ->whereIn('status', ['reserved', 'committed'])->orderBy('id')->lockForUpdate()->get();

                abort_unless(hash_equals($this->revisions->forOrder($locked), $expectedRevision),
                    409, '商品、分仓或预留库存已变化，请重新预检后再提交。');
                abort_unless($locked->order_status?->value === 'pending_shipment'
                    && $locked->fulfillment_parent_id === null
                    && $locked->source_type !== OrderSourceType::WooCommerce,
                    409, '仅允许修改尚未实际发货且不需要 Woo 双向同步的原始订单。');
                abort_if($locked->fulfillmentChildren()->exists(),
                    409, '订单已有拆分履约；必须使用多仓专用事务，不能按单仓直接改写。');
                abort_if($locked->procurementOrder()->exists(), 409, '已关联采购单，不能直接修改商品。');
                $shipment = $locked->shipment;
                abort_if($shipment && (
                    $shipment->shipment_status !== 'pending'
                    || filled($shipment->tracking_number)
                    || $shipment->shipped_at !== null
                ), 409, '物流已经开始；不能重规划历史发货。');
                abort_if($locked->shipmentAttempts()->exists(), 409, '已有实际发货尝试，不允许覆盖原始履约。');
                $allocation = $locked->fulfillmentAllocation;
                abort_unless($allocation && $allocation->status === 'submitted', 409, '订单缺少已提交的单仓分仓方案。');
                $segments = (array) ($allocation->plan_snapshot['segments'] ?? []);
                abort_unless(count($segments) === 1, 409, '已有分仓为多段履约，不能按单仓协议改写。');
                abort_if($held->contains(fn ($reservation) => $reservation->status === 'committed'),
                    409, '库存已实际扣减，不能释放或重写历史。');
                abort_unless($held->count() === 1 && $held->first()->status === 'reserved',
                    409, '当前不是一个可安全释放的单仓库存预留。');
                abort_unless((int) $shipment?->warehouse_id === (int) $held->first()->warehouse_id,
                    409, '现有发货仓与预留仓不一致，请先人工核对。');

                $oldReservation = $held->first();
                $this->reservations->release((int) $oldReservation->id, $actor, 'order_item_atomic_replan');

                $saved = $this->orders->amendItemsWithinAtomicReplan($locked, $items, $actor);
                $existingIds = collect($items)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
                $createdIds = $saved->items()->whereNotIn('id', $existingIds)->orderBy('id')->pluck('id')->all();
                $newCursor = 0;
                $newAssignments = [];
                foreach ($items as $i => $item) {
                    $id = $item['id'] ?? null;
                    if ($id === null || $id === '') {
                        $id = $createdIds[$newCursor++] ?? null;
                    }
                    abort_unless($id !== null, 409, '新商品行生成失败，全部操作已回滚。');
                    $choice = $indexed[$i];
                    $newAssignments[] = [
                        'order_item_id' => (int) $id,
                        'source_code' => (string) $newWarehouse->code,
                        'carrier' => trim((string) ($choice['carrier'] ?? '')),
                        'service' => $choice['service'] ?? null,
                    ];
                }
                $updatedAllocation = $this->allocations->submitManual(
                    $saved, $newAssignments, '商品变更重规划：'.$reason, $actor,
                );
                $activeOrder = $saved->fresh(['shipment']);
                abort_unless((int) $activeOrder->shipment?->warehouse_id === (int) $newWarehouse->id,
                    409, '新分仓仓库与库存预留目标不一致。');

                $newKey = 'amend-'.substr(hash('sha256', implode('|', [
                    $locked->id, $expectedRevision, $updatedAllocation->selected_plan_key,
                    $newWarehouse->id, $actor->getAuthIdentifier(),
                ])), 0, 57);
                $newReservation = $this->reservations->reserve(
                    (int) $locked->id, (int) $newWarehouse->id, $newKey, null, $actor
                );

                return [
                    'order_id' => (int) $locked->id,
                    'previous_reservation_id' => (int) $oldReservation->id,
                    'new_reservation_id' => (int) $newReservation['id'],
                    'warehouse_id' => (int) $newWarehouse->id,
                    'allocation_id' => (int) $updatedAllocation->id,
                    'order_item_ids' => $activeOrder->items()->orderBy('id')->pluck('id')->all(),
                    'state' => 'pending_manual_fulfillment',
                    'has_real_shipment' => false,
                ];
            }, 3);
        } catch (DomainException $e) {
            throw ValidationException::withMessages([
                'items' => '新库存预留未成功：'.$e->getMessage().'。整次修改已回滚，旧库存预留保持不变。',
            ]);
        }
    }
}
