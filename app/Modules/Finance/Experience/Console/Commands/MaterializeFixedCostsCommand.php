<?php

namespace App\Modules\Finance\Experience\Console\Commands;

use App\Modules\Finance\Application\CostMaterializationService;
use Illuminate\Console\Command;

final class MaterializeFixedCostsCommand extends Command
{
    protected $signature = 'finance:materialize-fixed-costs
        {--order= : Materialize one order ID}
        {--limit=10000 : Maximum operational orders to inspect}
        {--apply : Persist only customs_tax and first_mile_cost; dry-run is the default}';

    protected $description = 'Preview or gated-apply Owner fixed customs and first-mile cost rules.';

    public function handle(CostMaterializationService $service): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && ! $this->applyAllowed()) {
            $this->error('Production --apply requires ERP_ACCOUNTING_FIXED_COSTS_PRODUCTION_APPLY_ENABLED=true for this process.');
            return self::FAILURE;
        }

        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';
        $query = $orderClass::query()->orderBy('id');
        if ($this->option('order') !== null) {
            $query->whereKey($this->option('order'));
        } else {
            $query->where('order_kind', 'customer')
                ->whereDoesntHave('externalReferences', fn ($refs) => $refs->where('provider', 'historical_logistics'))
                ->limit(max(1, min(10000, (int) $this->option('limit'))));
        }

        $summary = [
            'mode' => $apply ? 'apply' : 'dry_run',
            'seen' => 0,
            'applied' => ['customs_tax' => 0, 'first_mile_cost' => 0],
            'noop' => ['customs_tax' => 0, 'first_mile_cost' => 0],
            'blocked' => ['customs_tax' => [], 'first_mile_cost' => []],
        ];

        foreach ($query->get() as $order) {
            $result = $apply ? $service->applyFixedRules($order) : $service->previewFixedRules($order);
            $summary['seen']++;
            if ($apply) {
                foreach (['applied', 'noop'] as $bucket) {
                    foreach ($result[$bucket] as $row) {
                        $type = (string) ($row['cost_type'] ?? '');
                        if (isset($summary[$bucket][$type])) $summary[$bucket][$type]++;
                    }
                }
                foreach ($result['blocked'] as $row) $this->countBlocked($summary, $row);
                continue;
            }

            foreach ($result['candidates'] as $candidate) {
                $status = (string) ($candidate['materialization_status'] ?? 'blocked');
                $type = (string) ($candidate['cost_type'] ?? '');
                if ($status === 'apply' && isset($summary['applied'][$type])) $summary['applied'][$type]++;
                elseif ($status === 'noop' && isset($summary['noop'][$type])) $summary['noop'][$type]++;
                else $this->countBlocked($summary, $candidate);
            }
        }

        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function applyAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) return true;
        if (! app()->environment('production')) return false;
        return filter_var(getenv('ERP_ACCOUNTING_FIXED_COSTS_PRODUCTION_APPLY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL);
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $row */
    private function countBlocked(array &$summary, array $row): void
    {
        $type = (string) ($row['cost_type'] ?? '');
        if (! isset($summary['blocked'][$type])) return;
        $blocker = (string) ($row['blocker'] ?? 'blocked');
        $summary['blocked'][$type][$blocker] = ($summary['blocked'][$type][$blocker] ?? 0) + 1;
    }
}
