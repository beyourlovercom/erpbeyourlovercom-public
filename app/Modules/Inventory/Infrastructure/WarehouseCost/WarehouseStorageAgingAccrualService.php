<?php

namespace App\Modules\Inventory\Infrastructure\WarehouseCost;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\WarehouseMasterRegistry;
use App\Modules\Inventory\Application\WarehouseStorageAgingPolicy;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Inventory\Infrastructure\Models\WarehouseStorageAgingAccrual;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class WarehouseStorageAgingAccrualService
{
    public function __construct(
        private readonly WarehouseStorageAgingPolicy $policy,
        private readonly WarehouseMasterRegistry $warehouseMaster,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string,mixed> */
    public function preview(
        CarbonInterface|string|null $asOf = null,
        ?string $warehouseSlug = null,
        bool $backfillCurrentRemaining = false,
    ): array {
        $date = $asOf instanceof CarbonInterface
            ? CarbonImmutable::instance($asOf)->startOfDay()
            : CarbonImmutable::parse($asOf ?: now()->toDateString())->startOfDay();

        $warehouse = null;
        if ($warehouseSlug !== null && trim($warehouseSlug) !== '') {
            $warehouse = $this->findWarehouse($warehouseSlug);
            if ($warehouse === null) throw new InvalidArgumentException('storage_aging_warehouse_not_found');
        }

        $lots = WarehouseInventoryLot::query()
            ->with('warehouse')
            ->where('quantity', '>', 0)
            ->when($warehouse !== null, fn ($q) => $q->where('warehouse_id', $warehouse->getKey()))
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->orderBy('stock_status')
            ->orderBy('putaway_date')
            ->get();

        $accruals = WarehouseStorageAgingAccrual::query()
            ->when($warehouse !== null, fn ($q) => $q->where('warehouse_id', $warehouse->getKey()))
            ->get(['warehouse_id','product_id','stock_status','putaway_date','aging_month_index']);

        $existingByKey = [];
        foreach ($accruals as $row) {
            $key = $this->lotKey(
                (int) $row->warehouse_id,
                (int) $row->product_id,
                (string) $row->stock_status,
                $row->putaway_date?->toDateString(),
            );
            $existingByKey[$key][(int) $row->aging_month_index] = true;
        }

        $summary = [
            'mode' => 'dry_run',
            'as_of' => $date->toDateString(),
            'warehouse_filter' => $warehouse?->slug,
            'backfill_current_remaining' => $backfillCurrentRemaining,
            'warehouses_seen' => [],
            'lots_seen' => 0,
            'overseas_lots_seen' => 0,
            'excluded_non_overseas_lots' => 0,
            'not_due_lots' => 0,
            'eligible_lots' => 0,
            'eligible_units' => 0,
            'new_accrual_rows' => 0,
            'noop_accrual_rows' => 0,
            'blocked_total' => 0,
            'blocked' => [],
            'new_amount_cny' => '0.00',
            'cumulative_current_amount_cny' => '0.00',
            'candidates' => [],
        ];

        $seenWarehouses = [];
        foreach ($lots as $lot) {
            $summary['lots_seen']++;
            $warehouseModel = $lot->warehouse;
            if ($warehouseModel === null) {
                $this->block($summary, 'warehouse_missing');
                continue;
            }

            $seenWarehouses[(string) $warehouseModel->slug] = true;
            $code = $this->canonicalWarehouseCode($warehouseModel);
            if ($code === null) {
                $this->block($summary, 'warehouse_master_identity_unknown');
                continue;
            }

            if (! $this->warehouseMaster->isOverseasFulfillmentSlug((string) $warehouseModel->slug)) {
                $summary['excluded_non_overseas_lots']++;
                continue;
            }
            $summary['overseas_lots_seen']++;

            if ($lot->putaway_date === null) {
                $this->block($summary, 'putaway_date_missing');
                continue;
            }

            try {
                $projection = $this->policy->project(
                    $lot->putaway_date,
                    $date,
                    (int) $lot->quantity,
                );
            } catch (InvalidArgumentException $e) {
                $this->block($summary, $e->getMessage());
                continue;
            }

            $months = (int) $projection['billable_months'];
            if ($months === 0) {
                $summary['not_due_lots']++;
                continue;
            }

            $summary['eligible_lots']++;
            $summary['eligible_units'] += (int) $lot->quantity;
            $summary['cumulative_current_amount_cny'] = bcadd(
                $summary['cumulative_current_amount_cny'],
                (string) $projection['cumulative_amount_cny'],
                2,
            );

            $key = $this->lotKey(
                (int) $lot->warehouse_id,
                (int) $lot->product_id,
                (string) $lot->stock_status,
                $lot->putaway_date->toDateString(),
            );
            $existing = $existingByKey[$key] ?? [];

            if (! $backfillCurrentRemaining && $existing === [] && $months > 1) {
                $this->block($summary, 'historical_cycles_require_backfill_gate');
                continue;
            }

            for ($index = 1; $index <= $months; $index++) {
                if (isset($existing[$index])) {
                    $summary['noop_accrual_rows']++;
                    continue;
                }

                $amount = $this->policy->amountForOneMonth((int) $lot->quantity);
                $putaway = CarbonImmutable::parse($lot->putaway_date->toDateString())->startOfDay();
                $cycleStartedOn = $putaway
                    ->addDays(WarehouseStorageAgingPolicy::FREE_DAYS + 1)
                    ->addDays(($index - 1) * WarehouseStorageAgingPolicy::BILLING_DAYS)
                    ->toDateString();

                $candidate = [
                    'warehouse_id' => (int) $lot->warehouse_id,
                    'warehouse_code' => $code,
                    'warehouse_slug' => (string) $warehouseModel->slug,
                    'product_id' => (int) $lot->product_id,
                    'stock_status' => (string) $lot->stock_status,
                    'putaway_date' => $lot->putaway_date->toDateString(),
                    'aging_month_index' => $index,
                    'cycle_started_on' => $cycleStartedOn,
                    'quantity_snapshot' => (int) $lot->quantity,
                    'unit_rate_cny' => WarehouseStorageAgingPolicy::UNIT_RATE_CNY,
                    'amount_cny' => $amount,
                    'source_sync_batch_id' => $lot->sync_batch_id !== null ? (int) $lot->sync_batch_id : null,
                    'source_reference' => $this->sourceReference(
                        $code,
                        (int) $lot->product_id,
                        (string) $lot->stock_status,
                        $lot->putaway_date->toDateString(),
                        $index,
                    ),
                    'accrual_as_of' => $date->toDateString(),
                    'is_backfill' => $backfillCurrentRemaining,
                ];
                $summary['candidates'][] = $candidate;
                $summary['new_accrual_rows']++;
                $summary['new_amount_cny'] = bcadd($summary['new_amount_cny'], $amount, 2);
            }
        }

        $summary['warehouses_seen'] = array_values(array_keys($seenWarehouses));
        sort($summary['warehouses_seen']);
        usort($summary['candidates'], fn (array $a, array $b): int => strcmp($a['source_reference'], $b['source_reference']));
        $summary['plan_sha'] = $this->planSha($summary);

        return $summary;
    }

    /** @return array<string,mixed> */
    public function apply(
        CarbonInterface|string|null $asOf = null,
        ?string $warehouseSlug = null,
        bool $backfillCurrentRemaining = false,
    ): array {
        return DB::transaction(function () use ($asOf, $warehouseSlug, $backfillCurrentRemaining): array {
            $preview = $this->preview($asOf, $warehouseSlug, $backfillCurrentRemaining);
            $createdByWarehouse = [];

            foreach ($preview['candidates'] as $candidate) {
                $existing = WarehouseStorageAgingAccrual::query()
                    ->where('source_reference', $candidate['source_reference'])
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    throw new RuntimeException('storage_aging_concurrent_duplicate');
                }

                $row = WarehouseStorageAgingAccrual::query()->create([
                    'warehouse_id' => $candidate['warehouse_id'],
                    'product_id' => $candidate['product_id'],
                    'stock_status' => $candidate['stock_status'],
                    'putaway_date' => $candidate['putaway_date'],
                    'aging_month_index' => $candidate['aging_month_index'],
                    'cycle_started_on' => $candidate['cycle_started_on'],
                    'quantity_snapshot' => $candidate['quantity_snapshot'],
                    'unit_rate_cny' => $candidate['unit_rate_cny'],
                    'amount_cny' => $candidate['amount_cny'],
                    'source_sync_batch_id' => $candidate['source_sync_batch_id'],
                    'source_reference' => $candidate['source_reference'],
                    'accrual_as_of' => $candidate['accrual_as_of'],
                    'is_backfill' => $candidate['is_backfill'],
                    'accrued_at' => now(),
                ]);
                $createdByWarehouse[(int) $row->warehouse_id][] = (int) $row->id;
            }

            foreach ($createdByWarehouse as $warehouseId => $ids) {
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'inventory.warehouse_storage_aging_accrual.applied',
                    Warehouse::class,
                    $warehouseId,
                    after: [
                        'as_of' => $preview['as_of'],
                        'backfill_current_remaining' => $preview['backfill_current_remaining'],
                        'accrual_ids' => $ids,
                        'plan_sha' => $preview['plan_sha'],
                    ],
                    reason: 'warehouse_storage_aging_accrual',
                );
            }

            return [
                ...$preview,
                'mode' => 'apply',
                'applied_rows' => count($preview['candidates']),
            ];
        });
    }

    private function findWarehouse(string $slug): ?Warehouse
    {
        $needle = trim($slug);
        $direct = Warehouse::query()->where('slug', $needle)->first();
        if ($direct !== null) return $direct;

        $code = $this->warehouseMaster->codeForSlug($needle);
        return $code === null ? null : Warehouse::query()->where('code', $code)->first();
    }

    private function canonicalWarehouseCode(Warehouse $warehouse): ?string
    {
        $code = strtoupper(trim((string) $warehouse->code));
        if ($code !== '' && $this->warehouseMaster->forCode($code) !== []) return $code;
        return $this->warehouseMaster->codeForSlug((string) $warehouse->slug);
    }

    private function lotKey(int $warehouseId, int $productId, string $stockStatus, ?string $putawayDate): string
    {
        return implode('|', [$warehouseId, $productId, $stockStatus, $putawayDate ?? '']);
    }

    private function sourceReference(string $warehouseCode, int $productId, string $stockStatus, string $putawayDate, int $index): string
    {
        return implode(':', [
            'storage-aging', 'v1', strtolower($warehouseCode), 'product', $productId,
            'status', strtolower($stockStatus), 'putaway', str_replace('-', '', $putawayDate),
            'month', $index,
        ]);
    }

    /** @param array<string,mixed> $summary */
    private function block(array &$summary, string $reason): void
    {
        $summary['blocked_total']++;
        $summary['blocked'][$reason] = ($summary['blocked'][$reason] ?? 0) + 1;
    }

    /** @param array<string,mixed> $summary */
    private function planSha(array $summary): string
    {
        $manifest = [
            'version' => 'p9-storage-aging-v1',
            'as_of' => $summary['as_of'],
            'warehouse_filter' => $summary['warehouse_filter'],
            'backfill_current_remaining' => $summary['backfill_current_remaining'],
            'candidates' => $summary['candidates'],
        ];

        return hash('sha256', json_encode(
            $manifest,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }
}
