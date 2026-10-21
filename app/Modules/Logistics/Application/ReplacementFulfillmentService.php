<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class ReplacementFulfillmentService
{
    public const REASONS = [
        'lost' => '物流丢件',
        'damaged' => '产品损坏',
        'after_sales' => '售后换新',
        'maintenance' => '维护补件',
        'missing_item' => '少发漏发',
        'other' => '其他',
    ];

    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param list<array{order_item_id:int,quantity:int}> $items
     * @param array<string,string|null>|null $address
     */
    public function create(
        Order $order,
        string $reason,
        array $items,
        int $warehouseId,
        ?int $originFulfillmentId,
        ?string $note,
        ?array $address,
        Authenticatable $actor,
    ): Order {
        return DB::transaction(function () use ($order, $reason, $items, $warehouseId, $originFulfillmentId, $note, $address, $actor): Order {
            $rootId = $order->fulfillment_parent_id ?: $order->getKey();
            $root = Order::query()->with(['address', 'items', 'shipment', 'originalFulfillmentChildren.items', 'originalFulfillmentChildren.shipment'])
                ->whereKey($rootId)->lockForUpdate()->firstOrFail();

            abort_unless(array_key_exists($reason, self::REASONS), 422, '无效补发原因');
            abort_unless($this->originalLogisticsStarted($root), 409, '原订单尚未进入实际物流，请使用重新分仓而不是补发');

            $warehouse = Warehouse::query()->whereKey($warehouseId)->where('is_active', true)->first();
            abort_unless($warehouse, 422, '补发仓库无效或未启用');

            $normalized = collect($items)->map(fn (array $row): array => [
                'order_item_id' => (int) ($row['order_item_id'] ?? 0),
                'quantity' => (int) ($row['quantity'] ?? 0),
            ])->filter(fn (array $row): bool => $row['quantity'] > 0)->values();
            abort_if($normalized->isEmpty(), 422, '请至少选择一个补发商品');
            abort_if($normalized->pluck('order_item_id')->duplicates()->isNotEmpty(), 422, '同一个商品不能重复添加到补发单');

            $rootItems = $root->items->keyBy('id');
            foreach ($normalized as $row) {
                $source = $rootItems->get($row['order_item_id']);
                abort_unless($source, 422, '补发商品不属于原订单');
                abort_if($row['quantity'] > (int) $source->quantity, 422, '单次补发数量不能超过原订单商品数量');
            }

            $origin = null;
            if ($originFulfillmentId !== null) {
                $origin = Order::query()->whereKey($originFulfillmentId)->where('fulfillment_parent_id', $root->getKey())->first();
                abort_unless($origin && $origin->fulfillment_role !== 'replacement', 422, '关联原履约无效');
                $origin->loadMissing('items');
            }

            $sequence = $this->nextSequence($root);
            $suffix = 'R'.$sequence;
            abort_if(strlen((string) $root->erp_order_id) > 60, 422, '原订单号过长，不能追加补发后缀');

            $replacement = Order::query()->create([
                'customer_id' => $root->customer_id,
                'fulfillment_parent_id' => $root->getKey(),
                'fulfillment_suffix' => $suffix,
                'fulfillment_role' => 'replacement',
                'fulfillment_origin_id' => $origin?->getKey(),
                'fulfillment_reason' => $reason,
                'fulfillment_note' => $this->nullable($note),
                'internal_order_number' => 'REPL-'.$root->getKey().'-'.$suffix,
                'erp_order_id' => $root->erp_order_id.'-'.$suffix,
                'source_type' => OrderSourceType::ManualOther,
                'order_kind' => OrderKind::FulfillmentDerived,
                'external_order_number' => null,
                'order_date' => now()->toDateString(),
                'order_note' => trim(self::REASONS[$reason].($note ? ' · '.$note : '')),
                'owner_id' => $root->owner_id,
                'order_status' => OrderStatus::PendingShipment,
                'currency' => $root->currency,
            ]);

            $replacement->address()->create($this->replacementAddress($root, $address));
            foreach ($normalized as $row) {
                $source = $rootItems->get($row['order_item_id']);
                $resolved = $this->resolvedSourceItem($source, $origin);
                $replacement->items()->create([
                    'product_id' => $resolved?->product_id ?? $source->product_id,
                    'woocommerce_line_item_id' => $source->woocommerce_line_item_id,
                    'product_summary' => $resolved?->product_summary ?? $source->product_summary,
                    'sku' => $resolved?->sku ?? $source->sku,
                    'source_sku' => $source->source_sku ?: $source->sku,
                    'resolved_fulfillment_sku' => $resolved?->resolved_fulfillment_sku ?: ($source->resolved_fulfillment_sku ?: $source->sku),
                    'quantity' => $row['quantity'],
                    'unit_price' => '0.00',
                    'line_revenue' => '0.00',
                ]);
            }

            $this->shipments->saveDraft($replacement, [
                'warehouse_id' => $warehouse->getKey(),
                'warehouse_selection_source' => 'replacement',
                'logistics_note' => self::REASONS[$reason].($note ? ' · '.$note : ''),
            ], $actor);

            $fresh = $replacement->fresh(['items', 'address', 'shipment.warehouse', 'fulfillmentOrigin']);
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'fulfillment.replacement_created',
                Order::class,
                $fresh->getKey(),
                before: null,
                after: [
                    'root_order_id' => (int) $root->getKey(),
                    'suffix' => $suffix,
                    'reason' => $reason,
                    'origin_fulfillment_id' => $origin?->getKey(),
                    'warehouse_id' => (int) $warehouse->getKey(),
                    'items' => $normalized->all(),
                ],
                reason: 'customer_replacement_fulfillment',
            );

            return $fresh;
        });
    }

    private function originalLogisticsStarted(Order $root): bool
    {
        $segments = $root->originalFulfillmentChildren->isNotEmpty()
            ? $root->originalFulfillmentChildren
            : collect([$root]);

        return $segments->contains(function (Order $segment): bool {
            $shipment = $segment->shipment;
            if (! $shipment) return false;
            return $shipment->shipment_status !== 'pending' || filled($shipment->tracking_number) || $shipment->shipped_at !== null;
        });
    }

    private function nextSequence(Order $root): int
    {
        $max = $root->replacementFulfillments()->pluck('fulfillment_suffix')->map(function ($suffix): int {
            return preg_match('/^R(\d+)$/', (string) $suffix, $match) ? (int) $match[1] : 0;
        })->max() ?? 0;

        return $max + 1;
    }

    private function resolvedSourceItem($source, ?Order $origin)
    {
        if (! $origin) return null;
        return $origin->items->first(function ($item) use ($source): bool {
            $sourceSku = trim((string) ($source->source_sku ?: $source->sku));
            $itemSourceSku = trim((string) ($item->source_sku ?: $item->sku));
            return $sourceSku !== '' && $sourceSku === $itemSourceSku;
        });
    }

    /** @param array<string,string|null>|null $override */
    private function replacementAddress(Order $root, ?array $override): array
    {
        $base = $root->address?->only([
            'recipient_name', 'phone', 'country', 'province_state', 'city',
            'address_line_1', 'address_line_2', 'postal_code',
        ]) ?? [];
        if (! $override) return $base;

        foreach (array_keys($base) as $key) {
            if (array_key_exists($key, $override) && $override[$key] !== null && trim((string) $override[$key]) !== '') {
                $base[$key] = trim((string) $override[$key]);
            }
        }
        return $base;
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
