<?php

namespace App\Modules\Logistics\Experience\Console\Commands;

use App\Modules\Logistics\Infrastructure\CarrierBilling\CarrierBillingImportService;
use Illuminate\Console\Command;
use Throwable;

final class ImportCarrierBillCommand extends Command
{
    protected $signature = 'logistics:import-carrier-bill
        {file : Canonical CSV/XLSX carrier bill path}
        {--provider= : Stable provider key}
        {--apply : Persist ready carrier-billing evidence}
        {--expect-source-sha= : Exact source SHA-256 required for Production apply}
        {--expect-plan-sha= : Exact plan SHA-256 required for Production apply}
        {--expect-ready= : Exact ready row count required for Production apply}
        {--expect-review= : Exact review row count required for Production apply}
        {--expect-skip= : Exact skip row count required for Production apply}';

    protected $description = 'Preview or gated-apply P6B canonical carrier billing evidence.';

    public function handle(CarrierBillingImportService $service): int
    {
        $path = (string) $this->argument('file');
        $provider = (string) ($this->option('provider') ?? '');
        try {
            $preview = $service->preview($path, $provider);
        } catch (Throwable $e) {
            $this->line(json_encode(['mode' => 'dry_run', 'gate' => 'invalid_source', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        if (! (bool) $this->option('apply')) {
            $this->line(json_encode($this->summary($preview), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        if (! $this->applyAllowed()) {
            $this->error('Production --apply requires ERP_CARRIER_BILL_IMPORT_PRODUCTION_APPLY_ENABLED=true for this process.');
            return self::FAILURE;
        }
        if (app()->environment('production') && ! $this->expectedMatches($preview)) {
            $this->line(json_encode(['mode' => 'apply', 'gate' => 'blocked_preflight_mismatch', 'preflight' => $this->summary($preview)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        try {
            $result = $service->apply($path, $provider);
        } catch (Throwable $e) {
            $this->line(json_encode(['mode' => 'apply', 'gate' => 'apply_failed', 'error' => $e->getMessage(), 'preflight' => $this->summary($preview)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }
        $this->line(json_encode($this->summary($result) + [
            'batch_id' => $result['batch_id'] ?? null,
            'billing_created' => $result['billing_created'] ?? 0,
            'already_applied' => $result['already_applied'] ?? false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function applyAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) return true;
        if (! app()->environment('production')) return false;
        return filter_var(getenv('ERP_CARRIER_BILL_IMPORT_PRODUCTION_APPLY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL);
    }

    /** @param array<string,mixed> $preview */
    private function expectedMatches(array $preview): bool
    {
        $source = strtolower(trim((string) $this->option('expect-source-sha')));
        $plan = strtolower(trim((string) $this->option('expect-plan-sha')));
        if (preg_match('/^[a-f0-9]{64}$/D', $source) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $plan) !== 1) return false;
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
        return [
            'mode' => $data['mode'] ?? 'dry_run', 'provider' => $data['provider'], 'original_filename' => $data['original_filename'],
            'source_checksum' => $data['source_checksum'], 'parser_version' => $data['parser_version'], 'plan_checksum' => $data['plan_checksum'],
            'total_rows' => $data['total_rows'], 'ready_count' => $data['ready_count'], 'review_count' => $data['review_count'], 'skip_count' => $data['skip_count'],
        ];
    }
}
