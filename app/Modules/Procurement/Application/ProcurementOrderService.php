<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Procurement\Infrastructure\Models\ProcurementOrder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ProcurementOrderService
{
    public function createFromOrder(Order $order, User $actor, array $items): ProcurementOrder
    {
        abort_unless($order->operationalMarkers()->where('marker', 'stockout')->exists(), 422, '只有缺货订单可以创建采购订单。');
        $order->loadMissing('items');
        $sourceItemIds = $order->items->modelKeys();
        $submittedItemIds = array_map('intval', array_keys($items));
        abort_unless(
            count($submittedItemIds) === count($sourceItemIds)
                && ! array_diff($sourceItemIds, $submittedItemIds)
                && ! array_diff($submittedItemIds, $sourceItemIds),
            422,
            '采购明细必须完整对应源订单商品。',
        );

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($order, $actor, $items): ProcurementOrder {
                    $existing = ProcurementOrder::query()->where('source_order_id', $order->id)->first();
                    if ($existing) return $existing->load('items', 'sourceOrder');

                    $procurement = ProcurementOrder::create([
                        'procurement_number' => $this->nextNumber(),
                        'source_order_id' => $order->id,
                        'status' => 'pending',
                        'created_by' => $actor->id,
                    ]);

                    foreach ($order->items as $sourceItem) {
                        $input = $items[$sourceItem->id] ?? [];
                        $procurement->items()->create([
                            'source_order_item_id' => $sourceItem->id,
                            'product_id' => $sourceItem->product_id,
                            'sku' => $sourceItem->sku,
                            'product_summary' => $sourceItem->product_summary,
                            'source_quantity' => $sourceItem->quantity,
                            'purchase_url' => $input['purchase_url'] ?? null,
                            'purchase_size' => $input['purchase_size'] ?? null,
                            'purchase_quantity' => (int) ($input['purchase_quantity'] ?? $sourceItem->quantity),
                        ]);
                    }

                    return $procurement->load('items', 'sourceOrder');
                });
            } catch (QueryException $exception) {
                if ($attempt === 2 || ! str_contains(strtolower($exception->getMessage()), 'unique')) throw $exception;
            }
        }

        throw new \RuntimeException('Unable to create procurement order.');
    }

    private function nextNumber(): string
    {
        $date = now()->format('Ymd');
        $last = ProcurementOrder::query()->where('procurement_number', 'like', "PO-{$date}-%")->lockForUpdate()->orderByDesc('procurement_number')->value('procurement_number');
        $next = $last ? ((int) substr($last, -4)) + 1 : 1;
        return sprintf('PO-%s-%04d', $date, $next);
    }
}
