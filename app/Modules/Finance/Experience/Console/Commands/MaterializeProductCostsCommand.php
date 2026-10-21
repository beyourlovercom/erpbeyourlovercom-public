<?php

namespace App\Modules\Finance\Experience\Console\Commands;

use App\Modules\Finance\Application\CostMaterializationService;
use Illuminate\Console\Command;

final class MaterializeProductCostsCommand extends Command
{
    protected $signature = 'finance:materialize-product-costs
        {--order= : Materialize one order ID}
        {--limit=10000 : Maximum operational orders to inspect}
        {--apply : Persist only confirmed product_cost and first_mile_cost}
        {--expect-seen= : Required Production apply preflight count}
        {--expect-product-apply= : Required Production product_cost apply count}
        {--expect-first-mile-apply= : Required Production first_mile_cost apply count}
        {--expect-fx-freeze= : Required Production transaction FX freeze count}';

    protected $description = 'Preview or gated-apply confirmed Product Cost plus FX-released first-mile cost.';

    public function handle(CostMaterializationService $service): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && ! $this->applyAllowed()) {
            $this->error('Production --apply requires ERP_ACCOUNTING_PRODUCT_COST_PRODUCTION_APPLY_ENABLED=true for this process.');
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

        $orders = $query->get();
        $preflight = $this->emptySummary('dry_run');
        $fxFreezeOrders = [];
        foreach ($orders as $order) {
            $preview = $service->previewProductCostGate($order);
            $this->summarizePreview($preflight, $preview, $fxFreezeOrders);
        }
        $preflight['fx_freeze_orders'] = count($fxFreezeOrders);

        if (! $apply) {
            $this->line(json_encode($preflight, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if (app()->environment('production') && ! $this->expectedCountsMatch($preflight)) {
            $this->line(json_encode(['mode' => 'apply', 'gate' => 'blocked_preflight_mismatch', 'preflight' => $preflight], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        $summary = $this->emptySummary('apply');
        $summary['fx_freeze_orders'] = $preflight['fx_freeze_orders'];
        foreach ($orders as $order) {
            $result = $service->applyProductCostGate($order);
            $summary['seen']++;
            foreach (['applied', 'noop'] as $bucket) {
                foreach ($result[$bucket] as $row) {
                    $type = (string) ($row['cost_type'] ?? '');
                    if (isset($summary[$bucket][$type])) $summary[$bucket][$type]++;
                }
            }
            foreach ($result['blocked'] as $row) $this->countBlocked($summary, $row);
        }

        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function applyAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) return true;
        if (! app()->environment('production')) return false;
        return filter_var(getenv('ERP_ACCOUNTING_PRODUCT_COST_PRODUCTION_APPLY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL);
    }

    /** @return array<string,mixed> */
    private function emptySummary(string $mode): array
    {
        return [
            'mode' => $mode,
            'seen' => 0,
            'applied' => ['product_cost' => 0, 'first_mile_cost' => 0],
            'noop' => ['product_cost' => 0, 'first_mile_cost' => 0],
            'blocked' => ['product_cost' => [], 'first_mile_cost' => []],
            'fx_freeze_orders' => 0,
        ];
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $preview @param array<int,bool> $fxFreezeOrders */
    private function summarizePreview(array &$summary, array $preview, array &$fxFreezeOrders): void
    {
        $summary['seen']++;
        foreach ($preview['candidates'] as $candidate) {
            $type = (string) ($candidate['cost_type'] ?? '');
            $status = (string) ($candidate['materialization_status'] ?? 'blocked');
            if ($status === 'apply' && isset($summary['applied'][$type])) {
                $summary['applied'][$type]++;
                if (($candidate['posting_fx']['freeze_transaction_fx'] ?? false) === true) {
                    $fxFreezeOrders[(int) $preview['order_id']] = true;
                }
            } elseif ($status === 'noop' && isset($summary['noop'][$type])) {
                $summary['noop'][$type]++;
            } else {
                $this->countBlocked($summary, $candidate);
            }
        }
    }

    /** @param array<string,mixed> $summary */
    private function expectedCountsMatch(array $summary): bool
    {
        $expected = [
            'seen' => $this->expectedInt('expect-seen'),
            'product' => $this->expectedInt('expect-product-apply'),
            'first_mile' => $this->expectedInt('expect-first-mile-apply'),
            'fx' => $this->expectedInt('expect-fx-freeze'),
        ];
        if (in_array(null, $expected, true)) return false;
        return $summary['seen'] === $expected['seen']
            && $summary['applied']['product_cost'] === $expected['product']
            && $summary['applied']['first_mile_cost'] === $expected['first_mile']
            && $summary['fx_freeze_orders'] === $expected['fx'];
    }

    private function expectedInt(string $option): ?int
    {
        $value = $this->option($option);
        if ($value === null || preg_match('/^\d+$/D', (string) $value) !== 1) return null;
        return (int) $value;
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
