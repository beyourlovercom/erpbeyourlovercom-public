<?php

namespace App\Modules\Finance\Experience\Console\Commands;

use App\Modules\Finance\Application\PaymentFeeLegacyFxRepairService;
use Illuminate\Console\Command;

final class RepairPaymentFeeLegacyFxCommand extends Command
{
    protected $signature = 'finance:repair-payment-fee-legacy-fx
        {--order= : Repair one order ID}
        {--limit=10000 : Maximum eligible payment-fee orders to inspect}
        {--apply : Persist only legacy payment-fee FX metadata repair; dry-run is default}
        {--expect-seen= : Required Production eligible-row preflight count}
        {--expect-candidates= : Required Production repair candidate count}
        {--expect-base-changes= : Required Production base_amount change count}';

    protected $description = 'Repair legacy Payment Fee FX metadata to the immutable transaction FX snapshot.';

    public function handle(PaymentFeeLegacyFxRepairService $service): int
    {
        $orderId = $this->option('order') === null ? null : (int) $this->option('order');
        $limit = max(1, min(10000, (int) $this->option('limit')));
        $apply = (bool) $this->option('apply');

        if ($apply && ! $this->applyAllowed()) {
            $this->error('Production --apply requires ERP_ACCOUNTING_PAYMENT_FEE_LEGACY_FX_REPAIR_ENABLED=true for this process.');
            return self::FAILURE;
        }

        $preview = $service->preview($orderId, $limit);
        if (! $apply) {
            unset($preview['rows']);
            $this->line(json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if (app()->environment('production') && ! $this->expectedCountsMatch($preview)) {
            unset($preview['rows']);
            $this->line(json_encode(['mode' => 'apply', 'gate' => 'blocked_preflight_mismatch', 'preflight' => $preview], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        $this->line(json_encode($service->apply($orderId, $limit), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function applyAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) return true;
        if (! app()->environment('production')) return false;
        return filter_var(getenv('ERP_ACCOUNTING_PAYMENT_FEE_LEGACY_FX_REPAIR_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL);
    }

    /** @param array<string,mixed> $preview */
    private function expectedCountsMatch(array $preview): bool
    {
        $seen = $this->expectedInt('expect-seen');
        $candidates = $this->expectedInt('expect-candidates');
        $baseChanges = $this->expectedInt('expect-base-changes');
        if ($seen === null || $candidates === null || $baseChanges === null) return false;
        return $preview['seen'] === $seen
            && $preview['candidates'] === $candidates
            && $preview['base_changes'] === $baseChanges;
    }

    private function expectedInt(string $option): ?int
    {
        $value = $this->option($option);
        if ($value === null || preg_match('/^\d+$/D', (string) $value) !== 1) return null;
        return (int) $value;
    }
}
