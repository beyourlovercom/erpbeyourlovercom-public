<?php

namespace App\Modules\Finance\Experience\Console\Commands;

use App\Modules\Finance\Application\AccountingContinuousDeltaService;
use Illuminate\Console\Command;
use Throwable;

final class MaterializeContinuousDeltaCommand extends Command
{
    protected $signature = 'finance:materialize-continuous-delta
        {--limit=10000 : Maximum changed operational orders to inspect, 1..10000}
        {--apply : Persist eligible P2/P3/P5/P6 delta materialization; dry-run is default}';

    protected $description = 'Materialize bounded Accounting V1.1C deltas after canonical Woo/order changes.';

    public function handle(AccountingContinuousDeltaService $service): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 10000) {
            $this->error('--limit must be between 1 and 10000.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        if ($apply && app()->environment('production')
            && ! (bool) config('erp_accounting.continuous_delta.enabled', false)) {
            $this->error('Production --apply requires ERP_ACCOUNTING_CONTINUOUS_DELTA_ENABLED=true.');
            return self::FAILURE;
        }
        if ($apply && ! app()->environment(['local', 'testing', 'production'])) {
            $this->error('Apply is not enabled in this environment.');
            return self::FAILURE;
        }

        try {
            $summary = $apply ? $service->apply($limit) : $service->preview($limit);
        } catch (Throwable $exception) {
            $this->error('Continuous Accounting delta stopped: '.$exception->getMessage());
            return self::FAILURE;
        }

        $this->line((string) json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ($summary['gate'] ?? 'pass') === 'pass' && (int) ($summary['failures'] ?? 0) === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
