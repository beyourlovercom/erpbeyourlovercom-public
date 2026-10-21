<?php

namespace App\Modules\Finance\Experience\Console\Commands;

use App\Modules\Finance\Application\CostMaterializationService;
use Illuminate\Console\Command;

final class MaterializeP82EstimatedLastMileCommand extends Command
{
    protected $signature = 'finance:p8-2-materialize-estimated-last-mile
        {--order= : Inspect one order ID}
        {--limit=10000 : Maximum direct-shipment customer orders to inspect}
        {--apply : Persist eligible P8.2 estimated last-mile cost}
        {--expect-seen= : Required Production preflight seen count}
        {--expect-apply= : Required Production apply count}
        {--expect-noop= : Required Production noop count}
        {--expect-blocked= : Required Production blocked count}
        {--expect-plan-sha= : Required Production preflight plan SHA}';

    protected $description = 'Preview or guarded-apply P8.2 historical/fallback estimated last-mile costs.';

    public function handle(CostMaterializationService $service): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && ! $this->applyAllowed()) {
            $this->error('Production --apply requires ERP_ACCOUNTING_P82_ESTIMATED_FALLBACK_PRODUCTION_APPLY_ENABLED=true for this process.');
            return self::FAILURE;
        }

        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';
        $query = $orderClass::query()->orderBy('id');
        if ($this->option('order') !== null) {
            $query->whereKey($this->option('order'));
        } else {
            $query->where('order_kind', 'customer')
                ->whereDoesntHave('externalReferences', fn ($refs) => $refs->where('provider', 'historical_logistics'))
                ->whereHas('shipment', fn ($shipment) => $shipment->whereNotNull('warehouse_id'))
                ->limit(max(1, min(10000, (int) $this->option('limit'))));
        }

        $orders = $query->get();
        $preflight = $this->emptySummary('dry_run');
        $manifest = [];
        foreach ($orders as $order) {
            $preview = $service->previewP82LastMileEstimateGate($order);
            $candidate = $preview['candidates'][0] ?? ['materialization_status' => 'blocked', 'blocker' => 'blocked'];
            $this->summarizeCandidate($preflight, $candidate);
            $manifest[] = $this->manifestRow((int) $order->getKey(), $candidate);
        }
        $preflight['plan_sha'] = hash('sha256', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        if (! $apply) {
            $this->line(json_encode($preflight, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if (app()->environment('production') && ! $this->expectedMatch($preflight)) {
            $this->line(json_encode(['mode' => 'apply', 'gate' => 'blocked_preflight_mismatch', 'preflight' => $preflight], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        $summary = $this->emptySummary('apply');
        $summary['plan_sha'] = $preflight['plan_sha'];
        foreach ($orders as $order) {
            $result = $service->applyP82LastMileEstimateGate($order);
            $candidate = $result['applied'][0] ?? $result['noop'][0] ?? $result['blocked'][0] ?? ['blocker' => 'blocked'];
            $summary['seen']++;
            if ($result['applied'] !== []) $summary['applied']++;
            elseif ($result['noop'] !== []) $summary['noop']++;
            else $this->countBlocked($summary, $candidate);
        }

        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function applyAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) return true;
        if (! app()->environment('production')) return false;
        return filter_var(getenv('ERP_ACCOUNTING_P82_ESTIMATED_FALLBACK_PRODUCTION_APPLY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL);
    }

    /** @return array<string,mixed> */
    private function emptySummary(string $mode): array
    {
        return [
            'mode' => $mode, 'seen' => 0, 'applied' => 0, 'noop' => 0,
            'blocked_total' => 0, 'blocked' => [], 'tiers' => [], 'confidence' => [],
            'plan_sha' => null,
        ];
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $candidate */
    private function summarizeCandidate(array &$summary, array $candidate): void
    {
        $summary['seen']++;
        $status = (string) ($candidate['materialization_status'] ?? 'blocked');
        if ($status === 'apply') $summary['applied']++;
        elseif ($status === 'noop') $summary['noop']++;
        else $this->countBlocked($summary, $candidate);

        $tier = (string) ($candidate['p82_tier'] ?? 'none');
        $confidence = (string) ($candidate['p82_confidence'] ?? 'none');
        $summary['tiers'][$tier] = ($summary['tiers'][$tier] ?? 0) + 1;
        $summary['confidence'][$confidence] = ($summary['confidence'][$confidence] ?? 0) + 1;
        ksort($summary['tiers']);
        ksort($summary['confidence']);
    }

    /** @return array<string,mixed> */
    private function manifestRow(int $orderId, array $candidate): array
    {
        return [
            'order_id' => $orderId,
            'disposition' => (string) ($candidate['materialization_status'] ?? 'blocked'),
            'amount' => $candidate['amount'] ?? null,
            'currency' => $candidate['currency'] ?? null,
            'tier' => $candidate['p82_tier'] ?? null,
            'confidence' => $candidate['p82_confidence'] ?? null,
            'sample_count' => $candidate['p82_sample_count'] ?? null,
            'blocker' => $candidate['blocker'] ?? null,
            'source_reference' => $candidate['source_reference'] ?? null,
        ];
    }

    /** @param array<string,mixed> $summary */
    private function expectedMatch(array $summary): bool
    {
        $seen = $this->expectedInt('expect-seen');
        $apply = $this->expectedInt('expect-apply');
        $noop = $this->expectedInt('expect-noop');
        $blocked = $this->expectedInt('expect-blocked');
        $sha = $this->option('expect-plan-sha');
        if ($seen === null || $apply === null || $noop === null || $blocked === null || ! is_string($sha) || $sha === '') return false;
        return $summary['seen'] === $seen
            && $summary['applied'] === $apply
            && $summary['noop'] === $noop
            && $summary['blocked_total'] === $blocked
            && hash_equals((string) $summary['plan_sha'], $sha);
    }

    private function expectedInt(string $option): ?int
    {
        $value = $this->option($option);
        if ($value === null || preg_match('/^\d+$/D', (string) $value) !== 1) return null;
        return (int) $value;
    }

    /** @param array<string,mixed> $summary */
    private function countBlocked(array &$summary, array $candidate): void
    {
        $reason = (string) ($candidate['blocker'] ?? 'blocked');
        $summary['blocked_total']++;
        $summary['blocked'][$reason] = ($summary['blocked'][$reason] ?? 0) + 1;
        ksort($summary['blocked']);
    }
}
