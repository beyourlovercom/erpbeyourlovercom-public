<?php

namespace App\Modules\Logistics\Experience\Console\Commands;

use App\Modules\Logistics\Infrastructure\HistoricalFulfillmentShipmentBackfillService;
use Illuminate\Console\Command;
use Throwable;

final class BackfillHistoricalFulfillmentShipmentsCommand extends Command
{
    protected $signature = 'logistics:hfr-backfill-customer-shipments
        {file : Canonical HFR normalized candidate JSON path}
        {--apply : Persist final Class A canonical Shipments}
        {--expect-source-sha= : Exact source file SHA-256 required for Production apply}
        {--expect-plan-sha= : Exact HFR3 plan SHA-256 required for Production apply}
        {--expect-ready= : Exact ready candidate count required for Production apply}
        {--expect-review= : Exact review candidate count required for Production apply}
        {--expect-skip= : Exact skip candidate count required for Production apply}';

    protected $description = 'Dry-run or guarded-apply HFR Customer Shipment Backfill V2.';

    public function handle(HistoricalFulfillmentShipmentBackfillService $service): int
    {
        $path = (string) $this->argument('file');
        try {
            $preview = $service->preview($path);
        } catch (Throwable $e) {
            $this->line(json_encode(['mode' => 'dry_run', 'gate' => 'invalid_source', 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        if (! (bool) $this->option('apply')) {
            $this->line(json_encode($this->summary($preview), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ((int) $preview['ready_count'] <= 0) {
            $this->line(json_encode(['mode' => 'apply', 'gate' => 'blocked_no_ready_candidates', 'preflight' => $this->summary($preview)], JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
        if (! $this->applyAllowed()) {
            $this->error('Production --apply requires ERP_LOGISTICS_HFR3_PRODUCTION_APPLY_ENABLED=true for this process.');

            return self::FAILURE;
        }
        if (app()->environment('production') && ! $this->expectedMatches($preview)) {
            $this->line(json_encode(['mode' => 'apply', 'gate' => 'blocked_preflight_mismatch', 'preflight' => $this->summary($preview)], JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
        try {
            $result = $service->apply($path);
        } catch (Throwable $e) {
            $this->line(json_encode(['mode' => 'apply', 'gate' => 'apply_failed', 'error' => $e->getMessage(), 'preflight' => $this->summary($preview)], JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
        $this->line(json_encode($this->summary($result) + ['shipments_created' => $result['shipments_created'] ?? 0], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function applyAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }
        if (! app()->environment('production')) {
            return false;
        }

        return filter_var(getenv('ERP_LOGISTICS_HFR3_PRODUCTION_APPLY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL);
    }

    /** @param array<string,mixed> $preview */
    private function expectedMatches(array $preview): bool
    {
        $source = strtolower(trim((string) $this->option('expect-source-sha')));
        $plan = strtolower(trim((string) $this->option('expect-plan-sha')));
        if (preg_match('/^[a-f0-9]{64}$/D', $source) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $plan) !== 1) {
            return false;
        }
        $ready = $this->expectedInt('expect-ready');
        $review = $this->expectedInt('expect-review');
        $skip = $this->expectedInt('expect-skip');

        return $ready !== null && $review !== null && $skip !== null
            && hash_equals($source, (string) $preview['source_checksum'])
            && hash_equals($plan, (string) $preview['plan_checksum'])
            && $ready === (int) $preview['ready_count']
            && $review === (int) $preview['review_count']
            && $skip === (int) $preview['skip_count'];
    }

    private function expectedInt(string $option): ?int
    {
        $value = $this->option($option);

        return $value !== null && preg_match('/^\d+$/D', (string) $value) === 1 ? (int) $value : null;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function summary(array $data): array
    {
        $reasons = [];
        foreach ($data['rows'] ?? [] as $row) {
            $reason = $row['reason_code'] ?? null;
            if ($reason === null) {
                continue;
            }
            $reasons[(string) $reason] = ($reasons[(string) $reason] ?? 0) + 1;
        }
        ksort($reasons);

        return [
            'mode' => $data['mode'] ?? 'dry_run',
            'original_filename' => $data['original_filename'],
            'source_checksum' => $data['source_checksum'],
            'source_scan_sha256' => $data['source_scan_sha256'],
            'plan_version' => $data['plan_version'],
            'plan_checksum' => $data['plan_checksum'],
            'total_candidates' => $data['total_candidates'],
            'ready_count' => $data['ready_count'],
            'review_count' => $data['review_count'],
            'skip_count' => $data['skip_count'],
            'reasons' => $reasons,
        ];
    }
}
