<?php

namespace App\Modules\Orders\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Finance\Application\Contracts\ConfirmedLossReader;
use App\Modules\Orders\Application\Inbound\NormalizedInboundOrder;
use App\Modules\Products\Application\Contracts\ProductExternalReferenceRepository;
use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Products\Application\SkuIdentityResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Orders\Infrastructure\Models\Order;

final class OrderService
{
    private const ADDRESS_FIELDS = [
        'recipient_name', 'phone', 'email', 'company', 'country', 'province_state', 'city',
        'address_line_1', 'address_line_2', 'postal_code',
    ];

    private const OPERATIONAL_MARKERS = ['exception', 'in_transit', 'return', 'stockout'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly ProductRepository $productRepository,
        private readonly SkuIdentityResolver $skuResolver,
        private readonly OrderIdentityService $orderIdentities,
        private readonly ProductExternalReferenceRepository $productExternalRefs,
        private readonly ConfirmedLossReader $confirmedLosses,
    ) {}

    public function create(array $attributes, array $items, ?Authenticatable $actor, bool $allowMissingAddress = false): Model
    {
        return DB::transaction(function () use ($attributes, $items, $actor, $allowMissingAddress): Model {
            $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';
            $address = array_intersect_key($attributes, array_flip(self::ADDRESS_FIELDS));
            $orderAttributes = array_diff_key($attributes, $address);
            $isManualKol = $attributes['order_kind'] === OrderKind::KolFulfillment->value
                && blank($orderAttributes['erp_order_id'] ?? null);
            $order = $orderClass::create([
                ...$orderAttributes,
                'external_order_number' => trim((string) $attributes['external_order_number']),
                'internal_order_number' => $this->nextOrderNumber($attributes['order_kind']),
                'erp_order_id' => $attributes['order_kind'] === OrderKind::Customer->value
                    ? $this->orderIdentities->allocateCustomer((string) $attributes['order_date'])
                    : ($orderAttributes['erp_order_id'] ?? null),
                'order_status' => OrderStatus::PendingShipment,
                'owner_id' => $orderAttributes['owner_id'] ?? $actor?->getAuthIdentifier(),
            ]);
            if ($isManualKol) {
                // KOL master IDs use the ordinary kN namespace. Manual-only orders use
                // a reserved high numeric range derived from the persisted order PK.
                $order->erp_order_id = $this->orderIdentities->generate(
                    'k', (string) (900000000000 + $order->id), (string) $attributes['order_date']
                );
                $order->save();
            }
            $requiredAddressFields = ['recipient_name', 'phone', 'country', 'province_state', 'city', 'address_line_1'];
            $hasCompleteRequiredAddress = collect($requiredAddressFields)->every(
                fn (string $field): bool => trim((string) ($address[$field] ?? '')) !== '',
            );
            if (! $allowMissingAddress || $hasCompleteRequiredAddress) {
                $order->address()->create($address);
            }

            foreach ($items as $item) {
                $resolved = $this->resolveProductFields($item);
                $order->items()->create([
                    ...$item,
                    'sku' => $resolved['sku'],
                    'product_summary' => $resolved['product_summary'],
                    'line_revenue' => $this->lineRevenue($item['unit_price'], (int) $item['quantity']),
                ]);
            }

            $this->record($actor, 'order.created', $order, after: $this->auditData($order), reason: 'manual_order_created');

            return $order->load('items', 'owner', 'address');
        });
    }

    /** @return array{order:Model,created:bool} */
    public function upsertInbound(NormalizedInboundOrder $data, ?Authenticatable $actor): array
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("SET time_zone = '+00:00'");
        }

        return DB::transaction(function () use ($data, $actor): array {
            $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';
            $referenceClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\OrderExternalReference';
            $reference = $referenceClass::query()
                ->where('provider', $data->provider)
                ->where('connection_key', $data->connectionKey)
                ->where('reference', $data->reference)
                ->lockForUpdate()
                ->first();

            $created = $reference === null;
            if ($reference) {
                $order = $orderClass::query()->whereKey($reference->order_id)->lockForUpdate()->firstOrFail();
                $before = $this->inboundAuditData($order);
                $localStatus = $order->order_status instanceof \BackedEnum ? $order->order_status->value : (string) $order->order_status;
                $nextStatus = $localStatus;
                if ($localStatus === OrderStatus::PendingShipment->value && $data->orderStatus === OrderStatus::Cancelled->value) {
                    $nextStatus = OrderStatus::Cancelled->value;
                }
                $order->fill([
                    'source_type' => OrderSourceType::WooCommerce->value,
                    'order_kind' => OrderKind::Customer->value,
                    'external_order_number' => $data->externalOrderNumber,
                    'order_date' => $data->orderDate,
                    'order_note' => $data->orderNote,
                    'currency' => $data->currency,
                    'order_status' => $nextStatus,
                ])->save();
            } else {
                $order = $orderClass::create([
                    'internal_order_number' => $this->nextOrderNumber(OrderKind::Customer->value),
                    'erp_order_id' => $this->orderIdentities->generateWooCustomer($data->externalOrderNumber, $data->reference, $data->orderDate),
                    'source_type' => OrderSourceType::WooCommerce->value,
                    'order_kind' => OrderKind::Customer->value,
                    'external_order_number' => $data->externalOrderNumber,
                    'order_date' => $data->orderDate,
                    'order_note' => $data->orderNote,
                    'currency' => $data->currency,
                    'order_status' => $data->orderStatus,
                    'owner_id' => null,
                ]);
                $reference = $order->externalReferences()->create([
                    'provider' => $data->provider,
                    'connection_key' => $data->connectionKey,
                    'reference' => $data->reference,
                    'provider_modified_at' => $data->providerModifiedAt,
                ]);
                $before = null;
            }

            $order->address()->updateOrCreate(['order_id' => $order->getKey()], $data->address);
            $order->items()->delete();
            $wooReference = $data->provider.':'.$data->reference;
            foreach ($data->items as $item) {
                $resolved = $this->resolveInboundProductFields($item, $wooReference, $data->connectionKey);
                $order->items()->create([
                    'product_id' => $resolved['product_id'],
                    'woocommerce_line_item_id' => $item['woocommerce_line_item_id'] ?? null,
                    'sku' => $resolved['sku'],
                    'product_summary' => $resolved['product_summary'],
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => (string) $item['unit_price'],
                    'line_revenue' => $this->lineRevenue((string) $item['unit_price'], (int) $item['quantity']),
                ]);
            }
            $reference->forceFill(['provider_modified_at' => $data->providerModifiedAt])->save();
            if ($data->settlementEvidence !== null) {
                $evidence = $data->settlementEvidence;
                $financials = $order->financials()->lockForUpdate()->first();
                if ($financials === null) {
                    $order->financials()->create([
                        ...$evidence,
                        'revenue_provenance' => 'provider_import',
                        'completeness_status' => 'INCOMPLETE',
                    ]);
                } elseif ($financials->revenue_provenance !== 'confirmed_manual') {
                    $currencyChanged = $financials->currency !== $evidence['currency'];
                    $financials->fill([
                        ...$evidence,
                        'revenue_provenance' => 'provider_import',
                        'completeness_status' => 'INCOMPLETE',
                    ]);
                    if ($currencyChanged) {
                        $financials->fx_rate = null;
                        $financials->fx_rate_date = null;
                        $financials->fx_rate_source = null;
                    }
                    $financials->save();
                }
            }
            $order = $order->fresh(['items', 'owner', 'address', 'externalReferences']);
            $this->record(
                $actor,
                $created ? 'order.inbound_created' : 'order.inbound_updated',
                $order,
                before: $before,
                after: $this->inboundAuditData($order),
                reason: 'woocommerce_inbound',
                source: AuditSource::WooCommerceInbound,
            );

            return ['order' => $order, 'created' => $created];
        });
    }

    public function update(Model $order, array $attributes, ?array $items, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($order, $attributes, $items, $actor): Model {
            $lockedOrder = $order->newQuery()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->auditData($lockedOrder);
            $before['address'] = $lockedOrder->address?->toArray();
            $before['items'] = $lockedOrder->items()->get()->toArray();
            $address = array_intersect_key($attributes, array_flip(self::ADDRESS_FIELDS));
            $orderAttributes = array_diff_key($attributes, $address);
            $lockedOrder->fill($orderAttributes)->save();
            if ($address !== []) {
                $lockedOrder->address()->updateOrCreate(['order_id' => $lockedOrder->getKey()], $address);
            }
            if ($items !== null) {
                $this->reconcileOrderItems($lockedOrder, $items);
            }
            $after = $this->auditData($lockedOrder);
            $after['address'] = $lockedOrder->address?->fresh()?->toArray();
            $after['items'] = $lockedOrder->items()->get()->toArray();
            $this->record($actor, 'order.updated', $lockedOrder, before: $before, after: $after, reason: 'manual_correction');

            return $lockedOrder->fresh(['items', 'owner', 'address']);
        });
    }

    /**
     * Called only by OrderItemAtomicReplanService within its encompassing
     * inventory + allocation transaction, after live guards and old holds
     * have been checked. Never expose a generic controller override.
     *
     * @param list<array<string,mixed>> $items
     */
    public function amendItemsWithinAtomicReplan(Order $order, array $items, Authenticatable $actor): Order
    {
        return DB::transaction(function () use ($order, $items, $actor): Order {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $before = ['items' => $locked->items()->get()->toArray()];
            $this->reconcileOrderItems($locked, $items, true);
            $this->record($actor, 'order.items.atomic_replan', $locked,
                before: $before, after: ['items' => $locked->items()->get()->toArray()],
                reason: 'approved_pending_fulfillment_replan');

            return $locked->fresh(['items', 'address', 'shipment']);
        });
    }

    /**
     * Preserve stable order_item IDs. FulfillmentAllocation plans and SKU-level
     * reservations refer to these identities; never delete/recreate all items.
     *
     * @param list<array<string,mixed>> $submitted
     */
    private function reconcileOrderItems(Model $order, array $submitted, bool $atomicReplan = false): void
    {
        $existing = $order->items()->lockForUpdate()->orderBy('id')->get();
        $existingById = $existing->keyBy('id');
        $seen = [];
        $planned = [];

        foreach ($submitted as $index => $item) {
            $id = isset($item['id']) && $item['id'] !== '' ? (int) $item['id'] : null;
            // Backwards-compatible single-line PATCH without an ID; multi-line
            // updates must address an existing line explicitly to avoid drift.
            if ($id === null && $existing->count() === 1 && count($submitted) === 1) {
                $id = (int) $existing->first()->id;
            }
            if ($id !== null && (! $existingById->has($id) || isset($seen[$id]))) {
                throw ValidationException::withMessages(["items.{$index}.id" => '订单商品行不存在或重复，请刷新订单。']);
            }
            if ($id === null && $existing->count() > 1 && ! array_key_exists('id', $item)) {
                throw ValidationException::withMessages(["items.{$index}.id" => '更新多种商品时需要原商品行编号，请刷新订单。']);
            }
            if ($id !== null) $seen[$id] = true;

            $resolved = $this->resolveProductFields($item);
            $fields = [
                'product_id' => (int) $item['product_id'],
                'sku' => $resolved['sku'],
                'product_summary' => $resolved['product_summary'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => number_format((float) $item['unit_price'], 2, '.', ''),
                'line_revenue' => $this->lineRevenue($item['unit_price'], (int) $item['quantity']),
            ];
            $row = $id !== null ? $existingById->get($id) : null;
            $changed = $row === null
                || (int) $row->product_id !== $fields['product_id']
                || (string) $row->sku !== (string) $fields['sku']
                || (string) $row->product_summary !== (string) $fields['product_summary']
                || (int) $row->quantity !== $fields['quantity']
                || (string) $row->unit_price !== $fields['unit_price'];
            $planned[] = ['row' => $row, 'fields' => $fields, 'changed' => $changed];
        }

        $removed = $existing->filter(fn ($row) => ! isset($seen[(int) $row->id]));
        $hasChanges = $removed->isNotEmpty() || collect($planned)->contains(fn (array $row) => $row['changed']);
        if (! $hasChanges) return;

        // Phase 1: only an unallocated, never-dispatched customer/KOL order
        // can be amended. A submitted allocation or reserved item needs the
        // separate transactional replan workflow before touching the lines.
        $shipment = $order->shipment()->lockForUpdate()->first();
        $unsafe = $order->order_status?->value !== 'pending_shipment'
            || $order->fulfillment_parent_id !== null
            || (! $atomicReplan && $order->fulfillmentChildren()->exists())
            || (! $atomicReplan && $order->fulfillmentAllocation()->exists())
            || ($shipment && (
                $shipment->shipment_status !== 'pending'
                || filled($shipment->tracking_number)
                || $shipment->shipped_at !== null
            ))
            || InventoryReservation::query()->where('order_id', $order->getKey())->whereIn('status', ['reserved', 'committed'])->exists()
            || $order->procurementOrder()->exists();
        if ($unsafe) {
            throw ValidationException::withMessages([
                'items' => '该订单已分仓、预留库存或进入物流。不能直接覆盖商品，请使用受控订单变更与重新分仓流程。',
            ]);
        }
        // Woo-linked order items must retain external source identity. A
        // separate Woo reconciliation contract is required before amending.
        if (! in_array($order->source_type, [OrderSourceType::Manual, OrderSourceType::ManualOther], true)
            || $order->externalReferences()->where('provider', 'woocommerce')->exists()) {
            throw ValidationException::withMessages([
                'items' => '此订单关联外部商城。商品变更必须先完成官网/ERP 双向同步契约，当前不可直接改写。',
            ]);
        }
        if ($order->financials()->exists() || $order->costEntries()->exists()) {
            throw ValidationException::withMessages([
                'items' => '订单已有付款、退款或成本入账证据。请先完成财务差额与补退款审核，不能直接改写商品金额。',
            ]);
        }

        foreach ($planned as $change) {
            if (! $change['changed']) continue;
            if ($change['row']) {
                $change['row']->fill($change['fields'])->save();
            } else {
                $order->items()->create($change['fields']);
            }
        }
        foreach ($removed as $row) $row->delete();

        // An earlier, not-reserved warehouse choice must be explicitly
        // reconfirmed against the new SKU/quantity/stock. No shipment sent.
        if ($shipment && $shipment->warehouse_id !== null) {
            $shipment->forceFill([
                'warehouse_id' => null,
                'warehouse_selection_source' => null,
                'carrier' => null,
                'service' => null,
                'carrier_service_id' => null,
                'carrier_selection_source' => null,
            ])->save();
        }
    }

    public function setOperationalMarker(Model $order, string $marker, bool $active, ?Authenticatable $actor): Model
    {
        if (! in_array($marker, self::OPERATIONAL_MARKERS, true)) {
            throw new \InvalidArgumentException('Unsupported order operational marker.');
        }

        return DB::transaction(function () use ($order, $marker, $active, $actor): Model {
            $locked = $order->newQuery()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $existing = $locked->operationalMarkers()->where('marker', $marker)->first();
            $wasActive = $existing !== null;

            if ($active && ! $existing) {
                $locked->operationalMarkers()->create([
                    'marker' => $marker,
                    'marked_by' => $actor?->getAuthIdentifier(),
                    'marked_at' => now(),
                ]);
            } elseif (! $active && $existing) {
                $existing->delete();
            }

            if ($wasActive !== $active) {
                $this->record(
                    $actor,
                    'order.operational_marker.updated',
                    $locked,
                    before: ['marker' => $marker, 'active' => $wasActive],
                    after: ['marker' => $marker, 'active' => $active],
                    reason: 'manual_operational_marker',
                );
            }

            return $locked->fresh(['operationalMarkers']);
        });
    }

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->paginate(min($perPage, 200))
            ->withQueryString();
    }

    public function exportCursor(array $filters): LazyCollection
    {
        return $this->filteredQuery($filters)->cursor();
    }

    private function filteredQuery(array $filters): Builder
    {
        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';
        $query = $orderClass::query()
            ->with(['items', 'owner', 'address', 'shipment.warehouse', 'operationalMarkers'])
            ->when($filters['order_kind'] ?? null, fn ($query, string $kind) => $query->where('order_kind', $kind))
            ->when($filters['owner_id'] ?? null, fn ($query, int $ownerId) => $query->where('owner_id', $ownerId))
            ->when($filters['warehouse_ownership'] ?? null, fn ($query, string $ownership) => $query->whereHas('shipment.warehouse', fn ($warehouse) => $warehouse->where('ownership_type', $ownership)))
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $search = trim($search);

                if (preg_match('/^dbid:(\d+)$/i', $search, $match) === 1) {
                    $query->whereKey((int) $match[1]);

                    return;
                }

                if (ctype_digit($search)) {
                    $query->where(function ($nested) use ($search): void {
                        $nested->where('external_order_number', $search)
                            ->orWhereHas('externalReferences', fn ($references) => $references->where('reference', $search))
                            ->orWhereHas('shipment', fn ($shipment) => $shipment->where('tracking_number', $search));
                    });

                    return;
                }

                $query->where(function ($nested) use ($search): void {
                    $nested->where('internal_order_number', 'like', "%{$search}%")
                        ->orWhereHas('address', fn ($address) => $address->where('recipient_name', 'like', "%{$search}%"))
                        ->orWhere('external_order_number', 'like', "%{$search}%")
                        ->orWhere('erp_order_id', 'like', "%{$search}%")
                        ->orWhereHas('externalReferences', fn ($references) => $references->where('reference', 'like', "%{$search}%"))
                        ->orWhereHas('shipment', fn ($shipment) => $shipment->where('tracking_number', 'like', "%{$search}%"));
                });
            })
            ->when($filters['source_type'] ?? null, fn ($query, string $source) => $query->where('source_type', $source))
            ->when($filters['order_status'] ?? null, fn ($query, string $status) => $query->where('order_status', $status));

        $this->applyBusinessView($query, (string) ($filters['view'] ?? 'all'));

        return $query->latest('order_date')->latest('id');
    }

    private function applyBusinessView(Builder $query, string $view): void
    {
        match ($view) {
            '', 'all' => null,
            'pending' => $query->where('order_status', OrderStatus::PendingShipment->value),
            'exception' => $this->applyMarkerView($query, 'exception'),
            'in_transit' => $this->applyMarkerView($query, 'in_transit'),
            'shipped' => $query->where('order_status', OrderStatus::Shipped->value),
            'return' => $this->applyMarkerView($query, 'return'),
            'loss' => $this->applyLossView($query),
            'overweight' => $query
                ->whereHas('shipment.warehouse', fn ($warehouse) => $warehouse->where('is_domestic', true))
                ->whereHas('shipment.billingEvidence', fn ($billing) => $billing->where('chargeable_weight_kg', '>', 1.5)),
            'stockout' => $this->applyStockoutView($query),
            default => null,
        };
    }

    private function applyMarkerView(Builder $query, string $marker): Builder
    {
        return $query->whereHas('operationalMarkers', fn ($markers) => $markers->where('marker', $marker));
    }

    private function applyLossView(Builder $query): Builder
    {
        return $this->confirmedLosses->applyConfirmedLossScope($query);
    }

    private function applyStockoutView(Builder $query): Builder
    {
        return $this->applyMarkerView($query, 'stockout')
            ->whereExists(function ($stockout): void {
                $stockout->selectRaw('1')
                    ->from('order_items as stockout_items')
                    ->join('shipments as stockout_shipments', 'stockout_shipments.order_id', '=', 'stockout_items.order_id')
                    ->leftJoin('warehouse_inventory as stockout_inventory', function ($join): void {
                        $join->on('stockout_inventory.warehouse_id', '=', 'stockout_shipments.warehouse_id')
                            ->on('stockout_inventory.product_id', '=', 'stockout_items.product_id');
                    })
                    ->whereColumn('stockout_items.order_id', 'orders.id')
                    ->whereNotNull('stockout_shipments.warehouse_id')
                    ->whereNotNull('stockout_items.product_id')
                    ->whereRaw('(COALESCE(stockout_inventory.quantity_on_hand, 0) - COALESCE(stockout_inventory.quantity_reserved, 0)) < stockout_items.quantity');
            });
    }

    private function nextOrderNumber(string $orderKind): string
    {
        $prefix = match ($orderKind) {
            OrderKind::Customer->value => 'CUS',
            OrderKind::KolFulfillment->value => 'KOL',
            default => 'MAN',
        };
        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';

        do {
            $number = $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while ($orderClass::query()->where('internal_order_number', $number)->exists());

        return $number;
    }

    /**
     * Pre-generates the operator-facing business order number (CUS/KOL + date + 4-char code + 007),
     * shown on the create form before save so the operator can edit it prior to first save.
     */
    public function generateExternalOrderNumber(OrderKind $orderKind): string
    {
        $prefix = match ($orderKind) {
            OrderKind::Customer => 'CUS',
            OrderKind::KolFulfillment => 'KOL',
        };
        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';

        do {
            $number = $prefix.now()->format('Ymd').Str::upper(Str::random(4)).'007';
        } while ($orderClass::query()->where('external_order_number', $number)->exists());

        return $number;
    }

    private function resolveInboundProductFields(array $item, ?string $wooReference = null, ?string $connectionKey = null): array
    {
        $product = null;
        $remoteId = $item['woocommerce_product_id'] ?? null;

        if (! empty($remoteId)) {
            // Step 1: connection-scoped external reference lookup (M1.4 authority).
            if ($connectionKey !== null) {
                $product = $this->productExternalRefs->findProduct('woocommerce', $connectionKey, (string) $remoteId);
            }

            // Step 2: legacy global Woo ID is a bounded compatibility path for the configured primary connection only.
            $primaryConnectionKey = (string) config('services.woocommerce.connection_key', 'beyourlover.com');
            if ($product === null && $connectionKey !== null && $connectionKey === $primaryConnectionKey) {
                $product = $this->productRepository->findByWooId((int) $remoteId);
            }
        }

        $rawSku = trim((string) ($item['sku'] ?? ''));
        if (! $product && $rawSku !== '') {
            // Step 3: exact canonical SKU / SKU Alias resolver.
            $result = $this->skuResolver->resolve($rawSku);
            if ($result->isResolved()) {
                $product = $result->product;
            } else {
                $this->skuResolver->observeUnmatched($rawSku, 'woocommerce_order', $wooReference);
            }
        }

        // Always derive the persisted SKU from the resolved product's canonical SKU.
        $canonicalSku = $product !== null ? (string) $product->getAttribute('sku') : $rawSku;

        $summary = trim((string) ($item['product_summary'] ?? ''));
        if ($summary === '') {
            $summary = $product?->name ? (string) $product->name : ($rawSku !== '' ? $rawSku : 'WooCommerce item');
        }

        return [
            'product_id' => $product?->getKey(),
            'sku' => $canonicalSku,
            'product_summary' => $summary,
        ];
    }

    private function resolveProductFields(array $item): array
    {
        $product = ! empty($item['product_id']) ? $this->productRepository->findById((int) $item['product_id']) : null;

        $sku = $product ? (string) $product->sku : trim((string) ($item['sku'] ?? ''));

        $explicit = trim((string) ($item['product_summary'] ?? ''));
        if ($explicit !== '' && $explicit !== '—') {
            return ['sku' => $sku, 'product_summary' => $explicit];
        }

        if ($product) {
            if (! empty($product->name)) {
                return ['sku' => $sku, 'product_summary' => (string) $product->name];
            }

            return ['sku' => $sku, 'product_summary' => $sku];
        }

        return ['sku' => $sku, 'product_summary' => $sku];
    }

    private function lineRevenue(string|int|float $unitPrice, int $quantity): string
    {
        $parts = explode('.', (string) $unitPrice, 2);
        $whole = (int) ($parts[0] ?? 0);
        $fraction = str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
        $cents = ($whole * 100) + (int) $fraction;

        return number_format(($cents * $quantity) / 100, 2, '.', '');
    }

    private function inboundAuditData(Model $order): array
    {
        $data = $this->auditData($order);
        $data['currency'] = $order->currency;
        $data['address'] = $order->address?->toArray();
        $data['items'] = $order->items()->get()->toArray();
        $data['external_references'] = $order->externalReferences()->get(['provider', 'connection_key', 'reference', 'provider_modified_at'])->toArray();
        $financials = $order->financials()->first();
        $data['financial_evidence'] = $financials?->only([
            'currency', 'merchandise_list_amount', 'discount_amount', 'shipping_revenue', 'tax_collected',
            'provider_order_total', 'refund_amount', 'refund_tax', 'payment_method', 'payment_transaction_id', 'actual_payment_fee', 'actual_payment_fee_source', 'actual_payment_fee_status', 'paid_at', 'provider',
            'provider_reference', 'provider_order_status', 'provider_modified_at', 'revenue_provenance',
            'reconciliation_status', 'reconciliation_exception',
        ]);

        return $data;
    }

    private function record(?Authenticatable $actor, string $action, Model $order, ?array $before = null, ?array $after = null, ?string $reason = null, AuditSource $source = AuditSource::InternalSystem): void
    {
        $this->audit->record(
            $actor ? AuditActor::user($actor, $source) : AuditActor::system($source),
            $action,
            get_class($order),
            $order->getKey(),
            before: $before,
            after: $after,
            reason: $reason,
        );
    }

    private function auditData(Model $order): array
    {
        return [
            'internal_order_number' => $order->internal_order_number,
            'erp_order_id' => $order->erp_order_id,
            'external_order_number' => $order->external_order_number,
            'source_type' => $order->source_type instanceof \BackedEnum ? $order->source_type->value : $order->source_type,
            'order_kind' => $order->order_kind instanceof \BackedEnum ? $order->order_kind->value : $order->order_kind,
            'order_status' => $order->order_status instanceof \BackedEnum ? $order->order_status->value : $order->order_status,
            'owner_id' => $order->owner_id,
            'currency' => $order->currency,
        ];
    }
}
