<?php

namespace App\Modules\Logistics\Experience\Console\Commands;

use App\Modules\Logistics\Application\ProductionUatFulfillmentCorrectionService;
use Illuminate\Console\Command;
use Throwable;

final class RepairProductionUatFulfillmentCommand extends Command
{
    protected $signature = 'logistics:repair-production-uat-fulfillment
        {--erp-order-id= : Exact ERP order identity to reconcile}
        {--apply : Apply the reviewed correction}
        {--expect-fingerprint= : Required exact dry-run fingerprint when applying}
        {--json : Emit safe JSON evidence}';

    protected $description = 'Preview or repair a production UAT fulfillment using Woo tracking truth without guessing warehouse';

    public function handle(ProductionUatFulfillmentCorrectionService $service): int
    {
        $erpOrderId = trim((string) $this->option('erp-order-id'));
        if ($erpOrderId === '') {
            return $this->emit([
                'mode' => $this->option('apply') ? 'apply' : 'dry_run',
                'decision' => 'blocked',
                'reason' => 'erp_order_id_required',
            ], self::FAILURE);
        }

        try {
            $preview = $service->preview($erpOrderId);
        } catch (Throwable $exception) {
            return $this->emit([
                'mode' => $this->option('apply') ? 'apply' : 'dry_run',
                'decision' => 'blocked',
                'reason' => 'preview_failed',
                'error' => $exception->getMessage(),
            ], self::FAILURE);
        }

        $preview['mode'] = $this->option('apply') ? 'apply' : 'dry_run';
        if (! $this->option('apply')) {
            return $this->emit($preview, ($preview['decision'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS);
        }

        if (($preview['decision'] ?? null) !== 'change') {
            $preview['gate'] = 'preview_not_change';

            return $this->emit($preview, self::FAILURE);
        }

        $expectedFingerprint = trim((string) $this->option('expect-fingerprint'));
        if ($expectedFingerprint === '' || ! hash_equals((string) $preview['fingerprint'], $expectedFingerprint)) {
            $preview['gate'] = 'fingerprint_mismatch';

            return $this->emit($preview, self::FAILURE);
        }

        try {
            $result = $service->apply($erpOrderId, $expectedFingerprint);
        } catch (Throwable $exception) {
            $preview['gate'] = 'apply_failed';
            $preview['error'] = $exception->getMessage();

            return $this->emit($preview, self::FAILURE);
        }

        return $this->emit([
            'mode' => 'apply',
            'decision' => 'change',
            'fingerprint' => $expectedFingerprint,
            ...$result,
        ], self::SUCCESS);
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload, int $exit): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line(sprintf(
                'Production UAT fulfillment %s: %s [%s]',
                $payload['mode'] ?? 'unknown',
                $payload['erp_order_id'] ?? 'unknown',
                $payload['decision'] ?? $payload['gate'] ?? 'unknown',
            ));
            if (isset($payload['fingerprint'])) {
                $this->line('fingerprint='.$payload['fingerprint']);
            }
            if (isset($payload['reason'])) {
                $this->line('reason='.$payload['reason']);
            }
        }

        return $exit;
    }
}
