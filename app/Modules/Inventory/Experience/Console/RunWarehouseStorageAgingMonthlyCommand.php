<?php

namespace App\Modules\Inventory\Experience\Console;

use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseStorageAgingAccrualService;
use Illuminate\Console\Command;
use Throwable;

final class RunWarehouseStorageAgingMonthlyCommand extends Command
{
    protected $signature = 'inventory:run-storage-aging-monthly {--as-of= : Optional explicit date for deterministic UAT/testing}';
    protected $description = 'Run idempotent P9 monthly storage-aging accrual when the persistent activation flag is enabled.';

    public function handle(WarehouseStorageAgingAccrualService $service): int
    {
        if (! (bool) config('warehouse_storage_aging.monthly_enabled', false)) {
            $this->line(json_encode([
                'mode' => 'scheduled',
                'gate' => 'disabled',
                'as_of' => $this->option('as-of') ?: now()->toDateString(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $asOf = $this->option('as-of') ?: now()->toDateString();

        try {
            $preview = $service->preview($asOf, null, false);
        } catch (Throwable $e) {
            $this->line(json_encode([
                'mode' => 'scheduled',
                'gate' => 'preview_failed',
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        if ((int) ($preview['blocked_total'] ?? 0) > 0) {
            $this->line(json_encode([
                'mode' => 'scheduled',
                'gate' => 'blocked_no_write',
                'as_of' => $preview['as_of'],
                'blocked_total' => $preview['blocked_total'],
                'blocked' => $preview['blocked'],
                'new_accrual_rows' => $preview['new_accrual_rows'],
                'new_amount_cny' => $preview['new_amount_cny'],
                'plan_sha' => $preview['plan_sha'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        if ((int) ($preview['new_accrual_rows'] ?? 0) === 0) {
            $this->line(json_encode([
                'mode' => 'scheduled',
                'gate' => 'noop',
                'as_of' => $preview['as_of'],
                'noop_accrual_rows' => $preview['noop_accrual_rows'],
                'plan_sha' => $preview['plan_sha'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        try {
            $applied = $service->apply($asOf, null, false);
        } catch (Throwable $e) {
            $this->line(json_encode([
                'mode' => 'scheduled',
                'gate' => 'apply_failed',
                'error' => $e->getMessage(),
                'preflight_plan_sha' => $preview['plan_sha'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        $this->line(json_encode([
            'mode' => 'scheduled',
            'gate' => 'applied',
            'as_of' => $applied['as_of'],
            'applied_rows' => $applied['applied_rows'],
            'new_amount_cny' => $applied['new_amount_cny'],
            'plan_sha' => $applied['plan_sha'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
