<?php

namespace App\Modules\Orders\Experience\Console;

use App\Modules\Orders\Application\StalePaymentReconciliationPreviewService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class PreviewStalePaymentsCommand extends Command
{
    protected $signature = 'orders:preview-stale-payments
        {--json : Emit the read-only reconciliation preview as JSON}
        {--as-of= : Evaluate age at this UTC timestamp}
        {--limit=100 : Maximum safe detail rows to print}';

    protected $description = 'Preview stale WooCommerce payment states without changing ERP or WooCommerce data';

    public function handle(StalePaymentReconciliationPreviewService $service): int
    {
        try {
            $asOf = $this->parseAsOf((string) ($this->option('as-of') ?? ''));
            $limit = max(1, (int) $this->option('limit'));
            $result = $service->preview($asOf, $limit);

            if ($this->option('json')) {
                $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->info(sprintf(
                'Stale payment preview: candidates=%d stale_unpaid=%d hold_review=%d evidence_conflict=%d truncated=%s',
                $result['candidate_count'],
                (int) ($result['classification_counts']['stale_unpaid'] ?? 0),
                (int) ($result['classification_counts']['hold_review'] ?? 0),
                (int) ($result['classification_counts']['pending_evidence_conflict'] ?? 0),
                $result['scan_truncated'] ? 'yes' : 'no',
            ));

            $this->table(
                ['ERP Order', 'Woo Ref', 'Gateway', 'Status', 'Classification', 'Age h', 'Threshold h', 'Paid?', 'Txn?'],
                collect($result['details'])->map(fn (array $row): array => [
                    $row['erp_order_id'],
                    $row['provider_reference'],
                    $row['payment_method'],
                    $row['provider_status'],
                    $row['classification'],
                    $row['age_hours'] ?? '-',
                    $row['threshold_hours'],
                    $row['has_paid_at'] ? 'yes' : 'no',
                    $row['has_transaction_id'] ? 'yes' : 'no',
                ])->all(),
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function parseAsOf(string $value): CarbonImmutable
    {
        if (trim($value) === '') {
            return CarbonImmutable::now('UTC');
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid --as-of timestamp.');
        }
    }
}
