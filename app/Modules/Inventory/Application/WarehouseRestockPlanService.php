<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseRestockPlan;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseRestockPlanService
{
    public function __construct(
        private readonly WarehouseRestockProjection $restock,
        private readonly ProductPackagingStandardReader $packagingStandards,
        private readonly AuditRecorder $audit,
    ) {}

    public function confirm(Warehouse $warehouse, ?Authenticatable $actor): WarehouseRestockPlan
    {
        return DB::transaction(function () use ($warehouse, $actor): WarehouseRestockPlan {
            $lockedWarehouse = Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
            $projection = $this->restock->forWarehouse($lockedWarehouse);
            $rows = collect($projection['rows'])
                ->filter(fn (array $row): bool => (int) $row['final_restock_qty'] > 0)
                ->sortBy(fn (array $row): string => sprintf('%s|%010d', (string) ($row['sku'] ?? ''), (int) $row['product_id']))
                ->values();

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['restock_plan' => '当前没有最终备货数量大于 0 的产品，无法确认备货计划。']);
            }

            $standards = $this->packagingStandards->forProductIds($rows->pluck('product_id')->all());
            $blocked = [];
            $items = [];

            foreach ($rows as $sortOrder => $row) {
                $productSku = trim((string) ($row['sku'] ?? ''));
                $labelSku = trim((string) ($row['label_sku'] ?? ''));
                if ($productSku === '' || $labelSku === '') {
                    $blocked[] = trim((string) ($row['name'] ?? '未命名产品'));
                    continue;
                }

                $standard = $standards[(int) $row['product_id']] ?? null;
                $unitsPerCarton = $row['units_per_carton'] !== null ? (int) $row['units_per_carton'] : null;
                $finalQty = (int) $row['final_restock_qty'];

                $items[] = [
                    'product_id' => (int) $row['product_id'],
                    'product_sku' => $productSku,
                    'label_sku' => $labelSku,
                    'label_description' => trim((string) ($row['label_description'] ?? '')) ?: (string) $row['name'],
                    'product_name' => (string) $row['name'],
                    'final_restock_qty' => $finalQty,
                    'formula_restock_qty' => (int) $row['formula_restock_qty'],
                    'manual_restock_qty' => (int) $row['manual_restock_qty'],
                    'shipped_120d_qty' => (int) $row['shipped_120d_qty'],
                    'quantity_available' => (int) $row['quantity_available'],
                    'quantity_on_hand' => (int) $row['quantity_on_hand'],
                    'quantity_reserved' => (int) $row['quantity_reserved'],
                    'units_per_carton' => $unitsPerCarton,
                    'carton_count' => $unitsPerCarton ? (int) ceil($finalQty / $unitsPerCarton) : null,
                    'packaging_profile_id' => $standard['product_packaging_profile_id'] ?? $row['packaging_profile_id'] ?? null,
                    'packaging_measurement_record_id' => $standard['measurement_record_id'] ?? null,
                    'packaging_method_label' => $standard['packaging_method_label'] ?? null,
                    'packaging_length_cm' => $standard['packaging_length_cm'] ?? null,
                    'packaging_width_cm' => $standard['packaging_width_cm'] ?? null,
                    'packaging_height_cm' => $standard['packaging_height_cm'] ?? null,
                    'packaged_weight_kg' => $standard['packaged_weight_kg'] ?? null,
                    'logistics_weight_kg' => $row['logistics_weight_kg'],
                    'weight_source' => (string) $row['weight_source'],
                    'sort_order' => (int) $sortOrder,
                ];
            }

            if ($blocked !== []) {
                throw ValidationException::withMessages([
                    'restock_plan' => '以下备货产品缺少可用 SKU / 蓝标 SKU，不能确认：'.implode('、', array_unique($blocked)),
                ]);
            }

            $summary = [
                ...$projection['summary'],
                'snapshot_products' => count($items),
                'cartons_known' => array_sum(array_map(fn (array $item): int => (int) ($item['carton_count'] ?? 0), $items)),
                'products_missing_units_per_carton' => count(array_filter($items, fn (array $item): bool => $item['units_per_carton'] === null)),
                'label_sku_overrides' => $rows->filter(fn (array $row): bool => (bool) ($row['label_sku_is_override'] ?? false))->count(),
            ];

            $sourceChecksum = $this->checksum([
                'warehouse_id' => (int) $lockedWarehouse->id,
                'rule' => $projection['rule'],
                'rows' => $rows->all(),
                'packaging_standards' => $standards,
            ]);
            $snapshotChecksum = $this->checksum([
                'warehouse_id' => (int) $lockedWarehouse->id,
                'rule' => $projection['rule'],
                'summary' => $summary,
                'items' => $items,
            ]);

            $existing = WarehouseRestockPlan::query()
                ->where('warehouse_id', $lockedWarehouse->id)
                ->where('snapshot_checksum', $snapshotChecksum)
                ->where('status', 'confirmed')
                ->latest('id')
                ->first();
            if ($existing !== null) {
                return $existing->load('items');
            }

            $plan = WarehouseRestockPlan::query()->create([
                'warehouse_id' => $lockedWarehouse->id,
                'reference' => 'RP-PENDING-'.Str::uuid(),
                'status' => 'confirmed',
                'source_checksum' => $sourceChecksum,
                'snapshot_checksum' => $snapshotChecksum,
                'rule_snapshot' => $projection['rule'],
                'summary_snapshot' => $summary,
                'confirmed_by' => $actor?->getAuthIdentifier(),
                'confirmed_at' => now(),
            ]);

            $referenceCode = strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '', (string) $lockedWarehouse->code));
            if ($referenceCode === '') $referenceCode = 'WAREHOUSE';
            $plan->reference = sprintf('RP-%s-%s-%06d', $referenceCode, now()->format('Ymd'), $plan->id);
            $plan->save();

            $plan->items()->createMany($items);

            $this->audit->record(
                $actor ? AuditActor::user($actor, AuditSource::Web) : AuditActor::system(AuditSource::InternalSystem),
                'inventory.restock_plan.confirmed',
                $plan::class,
                $plan->id,
                after: [
                    'reference' => $plan->reference,
                    'warehouse_id' => (int) $lockedWarehouse->id,
                    'snapshot_checksum' => $snapshotChecksum,
                    'products' => count($items),
                    'final_units' => (int) $summary['final_units'],
                ],
                reason: 'inventory_restock_plan_confirmed',
            );

            return $plan->fresh('items');
        });
    }

    public function latestForWarehouse(Warehouse $warehouse): ?array
    {
        $plan = WarehouseRestockPlan::query()
            ->where('warehouse_id', $warehouse->id)
            ->latest('confirmed_at')
            ->latest('id')
            ->first();

        if ($plan === null) return null;

        return [
            'id' => (int) $plan->id,
            'reference' => $plan->reference,
            'status' => $plan->status,
            'snapshot_checksum' => $plan->snapshot_checksum,
            'summary' => $plan->summary_snapshot,
            'confirmed_at' => $plan->confirmed_at?->toIso8601String(),
        ];
    }

    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }
}
