<?php

namespace App\Modules\Finance\Experience\Console\Commands;

use App\Modules\Finance\Application\GatewayEconomicsPreviewService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class GatewayEconomicsPreviewCommand extends Command
{
    protected $signature = 'payments:economics-preview
        {--json : Emit read-only gateway economics as JSON}
        {--as-of= : Evaluate through this UTC date/time}
        {--days=30 : Woo payment-order cohort window, 1 to 365 days}';

    protected $description = 'Preview gateway economics by payment method and currency without changing data';

    public function handle(GatewayEconomicsPreviewService $service): int
    {
        try {
            $asOf = $this->parseAsOf((string) ($this->option('as-of') ?? ''));
            $days = max(1, min((int) $this->option('days'), 365));
            $result = $service->preview($asOf, $days);

            if ($this->option('json')) {
                $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->warn('Economics are separated by order currency. No cross-currency amount total is produced.');
            $this->warn('Woo payment orders are not raw processor attempts. 3.5% is an accounting estimate, not actual gateway fee.');

            $this->table(
                ['Gateway', 'Currency', 'Orders', 'Captured', 'Gross', 'Refunds', 'Actual fee', 'Fee coverage', 'Fee rate', '3.5% estimate', 'Net after actual fee'],
                collect($result['rows'])->map(fn (array $row): array => [
                    $row['payment_method'],
                    $row['currency'],
                    $row['payment_orders'],
                    $row['captured_orders'],
                    $row['captured_gross'],
                    $row['refund_amount'],
                    $row['actual_fee_total'],
                    number_format(((float) $row['actual_fee_coverage']) * 100, 2).'%',
                    $row['actual_fee_effective_rate'] === null
                        ? '-'
                        : number_format(((float) $row['actual_fee_effective_rate']) * 100, 2).'%',
                    $row['accounting_fee_estimate_3_5pct'],
                    $row['net_after_refund_and_actual_fee'] ?? '-',
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
