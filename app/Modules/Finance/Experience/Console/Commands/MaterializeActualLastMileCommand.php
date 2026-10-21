<?php

namespace App\Modules\Finance\Experience\Console\Commands;

use App\Modules\Finance\Application\CostMaterializationService;
use Illuminate\Console\Command;

final class MaterializeActualLastMileCommand extends Command
{
    protected $signature = 'finance:materialize-actual-last-mile
        {--order= : Materialize one order ID}
        {--limit=10000 : Maximum operational orders to inspect}
        {--apply : Persist eligible actual carrier-billing last-mile cost}
        {--expect-seen= : Required Production preflight count}
        {--expect-apply= : Required Production apply count}
        {--expect-noop= : Required Production noop count}
        {--expect-blocked= : Required Production blocked count}
        {--expect-fx-freeze= : Required Production transaction FX freeze count}';

    protected $description = 'Preview or gated-apply P6B actual carrier-billing last-mile cost.';

    public function handle(CostMaterializationService $service): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && ! $this->applyAllowed()) {
            $this->error('Production --apply requires ERP_ACCOUNTING_ACTUAL_LAST_MILE_PRODUCTION_APPLY_ENABLED=true for this process.');
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
        foreach ($orders as $order) $this->summarizePreview($preflight, $service->previewCarrierBillingGate($order));
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
            $result = $service->applyCarrierBillingGate($order);
            $summary['seen']++;
            if ($result['applied'] !== []) $summary['applied']++;
            elseif ($result['noop'] !== []) $summary['noop']++;
            else $this->countBlocked($summary, $result['blocked'][0] ?? ['blocker' => 'blocked']);
        }
        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function applyAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) return true;
        if (! app()->environment('production')) return false;
        return filter_var(getenv('ERP_ACCOUNTING_ACTUAL_LAST_MILE_PRODUCTION_APPLY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL);
    }

    /** @return array<string,mixed> */
    private function emptySummary(string $mode): array
    {
        return ['mode' => $mode, 'seen' => 0, 'applied' => 0, 'noop' => 0, 'blocked_total' => 0, 'blocked' => [], 'fx_freeze_orders' => 0];
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $preview */
    private function summarizePreview(array &$summary, array $preview): void
    {
        $summary['seen']++;
        $candidate = $preview['candidates'][0] ?? ['materialization_status' => 'blocked', 'blocker' => 'blocked'];
        $status = (string) ($candidate['materialization_status'] ?? 'blocked');
        if ($status === 'apply') {
            $summary['applied']++;
            if (($candidate['posting_fx']['freeze_transaction_fx'] ?? false) === true) $summary['fx_freeze_orders']++;
        } elseif ($status === 'noop') $summary['noop']++;
        else $this->countBlocked($summary, $candidate);
    }

    /** @param array<string,mixed> $summary */
    private function expectedCountsMatch(array $summary): bool
    {
        $seen = $this->expectedInt('expect-seen'); $apply = $this->expectedInt('expect-apply');
        $noop = $this->expectedInt('expect-noop'); $blocked = $this->expectedInt('expect-blocked'); $fx = $this->expectedInt('expect-fx-freeze');
        return ! in_array(null, [$seen, $apply, $noop, $blocked, $fx], true)
            && $summary['seen'] === $seen && $summary['applied'] === $apply && $summary['noop'] === $noop
            && $summary['blocked_total'] === $blocked && $summary['fx_freeze_orders'] === $fx;
    }

    private function expectedInt(string $option): ?int
    {
        $value = $this->option($option);
        return $value !== null && preg_match('/^\d+$/D', (string) $value) === 1 ? (int) $value : null;
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $row */
    private function countBlocked(array &$summary, array $row): void
    {
        $blocker = (string) ($row['blocker'] ?? 'blocked');
        $summary['blocked_total']++;
        $summary['blocked'][$blocker] = ($summary['blocked'][$blocker] ?? 0) + 1;
    }
}
