<?php

namespace App\Modules\Finance\Experience\Console\Commands;

use App\Modules\Finance\Application\PaymentReliabilityClosureService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class PaymentReliabilityClosureCommand extends Command
{
    protected $signature = 'payments:reliability-closure
        {--json : Emit the read-only closure report as JSON}
        {--as-of= : Evaluate through this UTC date/time}
        {--health-days=1 : Health cohort window, 1 to 30 days}
        {--economics-days=30 : Economics cohort window, 1 to 365 days}
        {--limit=50 : Maximum safe anomaly details}';

    protected $description = 'Preview the canonical Payment Reliability closure without changing data';

    public function handle(PaymentReliabilityClosureService $service): int
    {
        try {
            $result = $service->preview(
                asOf: $this->parseAsOf((string) ($this->option('as-of') ?? '')),
                healthDays: max(1, min((int) $this->option('health-days'), 30)),
                economicsDays: max(1, min((int) $this->option('economics-days'), 365)),
                detailLimit: max(1, (int) $this->option('limit')),
            );

            if ($this->option('json')) {
                $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->info(sprintf(
                'Payment Reliability Closure %s: %s',
                (string) $result['contract_version'],
                strtoupper((string) $result['runtime_state']),
            ));
            $this->warn('Owner UAT required. Production is not authorized by this closure report.');

            $this->table(
                ['Gate', 'State', 'Evidence / Action'],
                collect($result['rollout_gates'])->map(
                    fn (array $gate, string $name): array => [
                        $name,
                        (string) ($gate['state'] ?? ''),
                        $this->gateDetail($gate),
                    ],
                )->values()->all(),
            );

            $this->table(
                ['Capability', 'Owner', 'Checkout role'],
                collect($result['gateway_ownership'])->map(
                    fn (array $row, string $capability): array => [
                        $capability,
                        (string) ($row['owner'] ?? ''),
                        (string) ($row['normal_checkout'] ?? $row['placement'] ?? $row['availability'] ?? $row['purpose'] ?? ''),
                    ],
                )->values()->all(),
            );

            $this->line(sprintf(
                'Health=%s sync=%s mismatches=%d duplicate_txn_groups=%d stale_unpaid=%d',
                (string) $result['health']['health'],
                (string) $result['health']['sync']['state'],
                (int) $result['health']['anomalies']['fulfillment_payment_mismatch_count'],
                (int) $result['health']['anomalies']['duplicate_transaction_identity_group_count'],
                (int) $result['health']['current_backlog']['stale_unpaid'],
            ));
            $this->line(sprintf(
                'Economics rows=%d | actual-fee gaps preserved | cross-currency total prohibited',
                count((array) $result['economics']['rows']),
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /** @param array<string,mixed> $gate */
    private function gateDetail(array $gate): string
    {
        unset($gate['state']);

        return collect($gate)
            ->map(fn ($value, string $key): string => $key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value))
            ->implode(' ');
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
