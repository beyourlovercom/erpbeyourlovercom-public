<?php

namespace App\Modules\Finance\Experience\Console\Commands;

use App\Modules\Finance\Application\WooFinancialEvidenceBootstrapService;
use App\Modules\Orders\Application\Contracts\WooCommerceFinancialEvidenceReader;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BootstrapWooFinancialEvidenceCommand extends Command
{
    protected $signature = 'finance:bootstrap-woo-financials
        {--order= : Exact WooCommerce provider order ID}
        {--from= : Inclusive source date YYYY-MM-DD}
        {--to= : Inclusive source date YYYY-MM-DD}
        {--limit=10000 : Maximum source orders to inspect, 1..10000}
        {--apply : Persist eligible financial projections; dry-run is default}
        {--json : Emit only the final summary JSON}';

    protected $description = 'Preview or apply WooCommerce settlement evidence to existing canonical order financials.';

    public function handle(WooCommerceFinancialEvidenceReader $reader, WooFinancialEvidenceBootstrapService $service): int
    {
        if ($this->option('apply') && app()->environment('production')
            && ! (bool) config('erp_accounting.financial_bootstrap.production_apply_enabled', false)) {
            $this->error('Production apply is locked. Enable the dedicated Accounting financial bootstrap gate first.');
            return self::FAILURE;
        }
        if ($this->option('apply') && ! app()->environment(['local', 'testing', 'production'])) {
            $this->error('Apply is not enabled in this environment.');
            return self::FAILURE;
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("SET time_zone = '+00:00'");
        }

        $limit = max(1, min(10000, (int) $this->option('limit')));
        $summary = $this->emptySummary((bool) $this->option('apply'));
        try {
            if ($this->option('order') !== null) {
                $value = trim((string) $this->option('order'));
                if (! ctype_digit($value) || (int) $value <= 0) {
                    $this->error('--order must be a positive WooCommerce order id.');
                    return self::FAILURE;
                }
                $this->consume($reader->fetchOne((int) $value), $service, $summary);
                $summary['pages'] = 1;
            } else {
                [$from, $to] = $this->dateRange();
                if ($from === null || $to === null) {
                    return self::FAILURE;
                }
                $page = 1;
                $totalPages = 1;
                do {
                    $result = $reader->fetchPage([
                        'after' => $from->startOfDay()->utc()->toIso8601String(),
                        'before' => $to->endOfDay()->utc()->toIso8601String(),
                        'status' => 'any',
                        'orderby' => 'date',
                        'order' => 'asc',
                        'per_page' => 100,
                        'page' => $page,
                    ]);
                    foreach ($result['items'] as $source) {
                        if ($summary['seen'] >= $limit) {
                            break 2;
                        }
                        $this->consume($source, $service, $summary);
                    }
                    $totalPages = max(1, (int) $result['total_pages']);
                    $summary['pages'] = $page;
                    $page++;
                } while ($page <= $totalPages && $summary['seen'] < $limit);
            }
        } catch (Throwable $exception) {
            $summary['fatal_error'] = get_class($exception);
            if (! $this->option('json')) {
                $this->error('Financial evidence bootstrap stopped: '.$exception->getMessage());
            }
            $this->line((string) json_encode($summary, JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        $this->line((string) json_encode($summary, JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function consume(array $source, WooFinancialEvidenceBootstrapService $service, array &$summary): void
    {
        $summary['seen']++;
        $sourceStatus = (string) ($source['source_status'] ?? 'error');
        if ($sourceStatus === 'error') {
            $summary['errors']++;
            $this->blocker($summary, (string) ($source['blocker'] ?? 'source_error'));
            return;
        }
        if ($sourceStatus === 'unresolved') {
            $summary['unresolved']++;
            $this->blocker($summary, (string) ($source['blocker'] ?? 'canonical_order_not_found'));
            return;
        }
        if (in_array($sourceStatus, ['ambiguous', 'blocked'], true)) {
            $summary['blocked']++;
            $this->blocker($summary, (string) ($source['blocker'] ?? 'source_blocked'));
            return;
        }

        $result = $this->option('apply') ? $service->apply($source) : $service->preview($source);
        $disposition = (string) ($result['disposition'] ?? 'blocked');
        $bucket = match ($disposition) {
            'create', 'created' => 'create',
            'update', 'updated' => 'update',
            'noop' => 'noop',
            'unresolved' => 'unresolved',
            'error' => 'errors',
            default => 'blocked',
        };
        $summary[$bucket]++;
        if ($bucket === 'blocked') {
            $this->blocker($summary, (string) ($result['blocker'] ?? 'blocked'));
        }
    }

    private function dateRange(): array
    {
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        if ($from === '' || $to === '') {
            $this->error('Use --order or provide both --from and --to.');
            return [null, null];
        }
        try {
            $fromDate = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
            $toDate = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');
        } catch (Throwable) {
            $this->error('--from and --to must be valid YYYY-MM-DD dates.');
            return [null, null];
        }
        if (! $fromDate || ! $toDate || $fromDate->gt($toDate)) {
            $this->error('The financial bootstrap date range is invalid.');
            return [null, null];
        }

        return [$fromDate, $toDate];
    }

    private function blocker(array &$summary, string $reason): void
    {
        $summary['blockers'][$reason] = ($summary['blockers'][$reason] ?? 0) + 1;
        ksort($summary['blockers']);
    }

    private function emptySummary(bool $apply): array
    {
        return [
            'mode' => $apply ? 'apply' : 'dry_run',
            'seen' => 0,
            'create' => 0,
            'update' => 0,
            'noop' => 0,
            'blocked' => 0,
            'unresolved' => 0,
            'errors' => 0,
            'pages' => 0,
            'blockers' => [],
        ];
    }
}
