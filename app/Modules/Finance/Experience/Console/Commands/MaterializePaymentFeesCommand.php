<?php

namespace App\Modules\Finance\Experience\Console\Commands;

use App\Modules\Finance\Application\CostMaterializationService;
use Illuminate\Console\Command;

final class MaterializePaymentFeesCommand extends Command
{
    protected $signature = 'finance:materialize-payment-fees
        {--order= : Materialize one order ID}
        {--limit=10000 : Maximum operational orders to inspect}
        {--apply : Persist payment_fee only; dry-run is the default}
        {--expect-seen= : Required Production apply preflight count}
        {--expect-apply= : Required Production payment_fee apply count}
        {--expect-blocked= : Required Production blocked count}
        {--expect-fx-freeze= : Required Production transaction FX freeze count}';

    protected $description = 'Preview or gated-apply Owner-confirmed 3.5% provider-order-total payment fees.';

    public function handle(CostMaterializationService $service): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && ! $this->applyAllowed()) {
            $this->error('Production --apply requires ERP_ACCOUNTING_PAYMENT_FEE_PRODUCTION_APPLY_ENABLED=true for this process.');
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
            $preview = $service->previewPaymentFeeGate($order);
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
            $result = $service->applyPaymentFeeGate($order);
            $summary['seen']++;
            $summary['applied'] += count($result['applied']);
            $summary['noop'] += count($result['noop']);
            foreach ($result['blocked'] as $row) $this->countBlocked($summary, $row);
        }

        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function applyAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) return true;
        if (! app()->environment('production')) return false;
        return filter_var(getenv('ERP_ACCOUNTING_PAYMENT_FEE_PRODUCTION_APPLY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL);
    }

    /** @return array<string,mixed> */
    private function emptySummary(string $mode): array
    {
        return ['mode' => $mode, 'seen' => 0, 'applied' => 0, 'noop' => 0, 'blocked' => [], 'fx_freeze_orders' => 0];
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $preview @param array<int,bool> $fxFreezeOrders */
    private function summarizePreview(array &$summary, array $preview, array &$fxFreezeOrders): void
    {
        $summary['seen']++;
        $candidate = $preview['candidates'][0];
        $status = (string) ($candidate['materialization_status'] ?? 'blocked');
        if ($status === 'apply') {
            $summary['applied']++;
            if (($candidate['posting_fx']['freeze_transaction_fx'] ?? false) === true) {
                $fxFreezeOrders[(int) $preview['order_id']] = true;
            }
        } elseif ($status === 'noop') {
            $summary['noop']++;
        } else {
            $this->countBlocked($summary, $candidate);
        }
    }

    /** @param array<string,mixed> $summary */
    private function expectedCountsMatch(array $summary): bool
    {
        $expected = [
            'seen' => $this->expectedInt('expect-seen'),
            'apply' => $this->expectedInt('expect-apply'),
            'blocked' => $this->expectedInt('expect-blocked'),
            'fx' => $this->expectedInt('expect-fx-freeze'),
        ];
        if (in_array(null, $expected, true)) return false;
        return $summary['seen'] === $expected['seen']
            && $summary['applied'] === $expected['apply']
            && array_sum($summary['blocked']) === $expected['blocked']
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
        $blocker = (string) ($row['blocker'] ?? 'blocked');
        $summary['blocked'][$blocker] = ($summary['blocked'][$blocker] ?? 0) + 1;
    }
}
