<?php

namespace App\Modules\Inventory\Experience\Console;

use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseStorageAgingAccrualService;
use Illuminate\Console\Command;
use Throwable;

final class AccrueWarehouseStorageAgingCostCommand extends Command
{
    protected $signature = 'inventory:accrue-storage-aging-cost
        {--as-of= : Accrual as-of date, default today}
        {--warehouse= : Optional canonical warehouse slug}
        {--backfill-current-remaining : Allow current remaining units to catch up all due aging months}
        {--apply : Persist immutable aging accrual rows}
        {--expect-as-of=}
        {--expect-warehouses= : Exact comma-separated warehouse slugs}
        {--expect-lots-seen=}
        {--expect-eligible-lots=}
        {--expect-eligible-units=}
        {--expect-new-rows=}
        {--expect-noop-rows=}
        {--expect-blocked=}
        {--expect-amount-cny=}
        {--expect-plan-sha=}';

    protected $description = 'Preview or gated-apply P9 overseas warehouse storage aging accrual.';

    public function handle(WarehouseStorageAgingAccrualService $service): int
    {
        try {
            $preview = $service->preview(
                $this->option('as-of'),
                $this->option('warehouse'),
                (bool) $this->option('backfill-current-remaining'),
            );
        } catch (Throwable $e) {
            $this->line(json_encode([
                'mode' => 'dry_run',
                'gate' => 'invalid_input',
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        if (! (bool) $this->option('apply')) {
            $this->line(json_encode($this->summary($preview), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if (! $this->applyAllowed()) {
            $this->error('Production --apply requires ERP_WAREHOUSE_STORAGE_AGING_ACCRUAL_PRODUCTION_APPLY_ENABLED=true for this process.');
            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->productionScopeIsExplicit($preview)) {
            $this->line(json_encode([
                'mode' => 'apply',
                'gate' => 'blocked_scope_not_explicit',
                'preflight' => $this->summary($preview),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->expectedMatches($preview)) {
            $this->line(json_encode([
                'mode' => 'apply',
                'gate' => 'blocked_preflight_mismatch',
                'preflight' => $this->summary($preview),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        try {
            $result = $service->apply(
                $this->option('as-of'),
                $this->option('warehouse'),
                (bool) $this->option('backfill-current-remaining'),
            );
        } catch (Throwable $e) {
            $this->line(json_encode([
                'mode' => 'apply',
                'gate' => 'apply_failed',
                'error' => $e->getMessage(),
                'preflight' => $this->summary($preview),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        $this->line(json_encode($this->summary($result) + [
            'applied_rows' => (int) ($result['applied_rows'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function applyAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) return true;
        if (! app()->environment('production')) return false;

        return filter_var(
            getenv('ERP_WAREHOUSE_STORAGE_AGING_ACCRUAL_PRODUCTION_APPLY_ENABLED') ?: 'false',
            FILTER_VALIDATE_BOOL,
        );
    }

    /** @param array<string,mixed> $preview */
    private function productionScopeIsExplicit(array $preview): bool
    {
        $asOf = trim((string) $this->option('as-of'));
        $expectedAsOf = trim((string) $this->option('expect-as-of'));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $asOf) !== 1 || $expectedAsOf !== $asOf) return false;

        $expectedWarehouses = $this->expectedWarehouses();
        if ($expectedWarehouses === null) return false;
        $actual = array_map('strval', (array) ($preview['warehouses_seen'] ?? []));
        sort($actual);

        return $actual === $expectedWarehouses;
    }

    /** @return list<string>|null */
    private function expectedWarehouses(): ?array
    {
        $raw = trim((string) $this->option('expect-warehouses'));
        if ($raw === '') return null;
        $values = array_values(array_unique(array_filter(array_map(
            fn (string $value): string => strtolower(trim($value)),
            explode(',', $raw),
        ), fn (string $value): bool => $value !== '')));
        if ($values === []) return null;
        sort($values);
        return $values;
    }

    /** @param array<string,mixed> $preview */
    private function expectedMatches(array $preview): bool
    {
        $ints = [
            'lots_seen' => $this->expectedInt('expect-lots-seen'),
            'eligible_lots' => $this->expectedInt('expect-eligible-lots'),
            'eligible_units' => $this->expectedInt('expect-eligible-units'),
            'new_accrual_rows' => $this->expectedInt('expect-new-rows'),
            'noop_accrual_rows' => $this->expectedInt('expect-noop-rows'),
            'blocked_total' => $this->expectedInt('expect-blocked'),
        ];
        if (in_array(null, $ints, true)) return false;
        foreach ($ints as $key => $expected) {
            if ((int) ($preview[$key] ?? -1) !== $expected) return false;
        }

        $amount = trim((string) $this->option('expect-amount-cny'));
        if (preg_match('/^\d+(?:\.\d{1,2})?$/D', $amount) !== 1) return false;
        if (bccomp((string) $preview['new_amount_cny'], $amount, 2) !== 0) return false;

        $plan = strtolower(trim((string) $this->option('expect-plan-sha')));
        return preg_match('/^[a-f0-9]{64}$/D', $plan) === 1
            && hash_equals($plan, (string) $preview['plan_sha']);
    }

    private function expectedInt(string $option): ?int
    {
        $value = $this->option($option);
        return $value !== null && preg_match('/^\d+$/D', (string) $value) === 1
            ? (int) $value
            : null;
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function summary(array $result): array
    {
        return array_intersect_key($result, array_flip([
            'mode',
            'as_of',
            'warehouse_filter',
            'backfill_current_remaining',
            'warehouses_seen',
            'lots_seen',
            'overseas_lots_seen',
            'excluded_non_overseas_lots',
            'not_due_lots',
            'eligible_lots',
            'eligible_units',
            'new_accrual_rows',
            'noop_accrual_rows',
            'blocked_total',
            'blocked',
            'new_amount_cny',
            'cumulative_current_amount_cny',
            'plan_sha',
        ]));
    }
}
