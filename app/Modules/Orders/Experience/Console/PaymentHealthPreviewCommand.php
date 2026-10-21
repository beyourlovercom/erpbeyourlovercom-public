<?php

namespace App\Modules\Orders\Experience\Console;

use App\Modules\Orders\Application\PaymentHealthPreviewService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class PaymentHealthPreviewCommand extends Command
{
    protected $signature = 'payments:health-preview
        {--json : Emit the read-only payment health preview as JSON}
        {--as-of= : Evaluate health at this UTC timestamp}
        {--days=1 : Woo payment-order cohort window, 1 to 30 days}
        {--limit=50 : Maximum safe anomaly details per category}';

    protected $description = 'Preview Woo payment health and ERP payment/fulfillment anomalies without changing data';

    public function handle(PaymentHealthPreviewService $service): int
    {
        try {
            $asOf = $this->parseAsOf((string) ($this->option('as-of') ?? ''));
            $days = max(1, min((int) $this->option('days'), 30));
            $limit = max(1, (int) $this->option('limit'));
            $result = $service->preview($asOf, $days, $limit);

            if ($this->option('json')) {
                $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->info(sprintf(
                'Payment health: %s | payment_orders=%d paid=%d failed=%d pending=%d stale=%d mismatch=%d sync=%s',
                strtoupper((string) $result['health']),
                (int) $result['cohort']['payment_orders'],
                (int) $result['cohort']['paid_orders'],
                (int) $result['cohort']['hard_failed_orders'],
                (int) $result['cohort']['pending_orders'],
                (int) $result['current_backlog']['stale_unpaid'],
                (int) $result['anomalies']['fulfillment_payment_mismatch_count'],
                (string) $result['sync']['state'],
            ));

            $this->warn('Coverage: Woo payment orders are not raw provider attempts; provider attempt stream and webhook event store are unavailable in ERP V1.');

            $gatewayRows = collect($result['cohort']['by_payment_method'])
                ->map(fn (array $row, string $method): array => [
                    $method,
                    $row['payment_orders'],
                    $row['paid_orders'],
                    $row['hard_failed_orders'],
                    $row['pending_orders'],
                    $row['hold_orders'],
                    number_format(((float) $row['current_paid_rate']) * 100, 2).'%',
                ])
                ->values()
                ->all();

            $this->table(
                ['Gateway', 'Orders', 'Paid', 'Failed', 'Pending', 'Hold', 'Current paid rate'],
                $gatewayRows,
            );

            $this->line(sprintf(
                'Backlog: recent_pending=%d stale_unpaid=%d evidence_conflict=%d hold_review=%d missing_timestamp=%d',
                (int) $result['current_backlog']['recent_pending'],
                (int) $result['current_backlog']['stale_unpaid'],
                (int) $result['current_backlog']['pending_evidence_conflict'],
                (int) $result['current_backlog']['hold_review'],
                (int) $result['current_backlog']['missing_timestamp_review'],
            ));
            $this->line(sprintf(
                'Woo inbound sync: state=%s status=%s age_hours=%s failed_items=%d conflict_items=%d',
                (string) $result['sync']['state'],
                (string) ($result['sync']['run_status'] ?? '-'),
                $result['sync']['age_hours'] === null ? '-' : (string) $result['sync']['age_hours'],
                (int) $result['sync']['failed_items'],
                (int) $result['sync']['conflict_items'],
            ));

            $anomalyRows = [];
            foreach ($result['anomalies']['fulfillment_payment_mismatches'] as $row) {
                $anomalyRows[] = [
                    'fulfillment_payment_mismatch',
                    $row['erp_order_id'],
                    $row['provider_reference'],
                    $row['payment_method'],
                    $row['provider_status'],
                    $row['payment_authority'],
                    $row['erp_fulfillment_status'],
                ];
            }
            foreach ($result['anomalies']['payment_evidence_gaps'] as $row) {
                $anomalyRows[] = [
                    'payment_evidence_gap',
                    $row['erp_order_id'],
                    $row['provider_reference'],
                    $row['payment_method'],
                    $row['provider_status'],
                    $row['payment_authority'],
                    $row['erp_fulfillment_status'],
                ];
            }

            if ($anomalyRows !== []) {
                $this->table(
                    ['Anomaly', 'ERP Order', 'Woo Ref', 'Gateway', 'Provider Status', 'Authority', 'Fulfillment'],
                    $anomalyRows,
                );
            }

            if ((int) $result['anomalies']['duplicate_transaction_identity_group_count'] > 0) {
                $this->warn(sprintf(
                    'Duplicate transaction identity groups=%d extra_rows=%d methods=%s',
                    (int) $result['anomalies']['duplicate_transaction_identity_group_count'],
                    (int) $result['anomalies']['duplicate_transaction_identity_extra_rows'],
                    implode(',', array_keys((array) $result['anomalies']['duplicate_transaction_identity_methods'])),
                ));
            }

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
