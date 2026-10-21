<?php

namespace App\Modules\Finance\Experience\Console\Commands;

use App\Modules\Finance\Application\CostMaterializationService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Console\Command;

final class MaterializeMultiShipmentCostsCommand extends Command
{
    protected $signature = 'finance:materialize-multi-shipment-costs
        {--order= : Preview/apply one Parent Customer Order ID}
        {--limit=10000 : Maximum Parent Customer Orders to inspect}
        {--apply : Persist eligible P8.1 parent aggregate costs}
        {--expect-orders= : Exact Production order count}
        {--expect-shipments= : Exact Production shipment-leg count}
        {--expect-apply= : Exact Production apply candidate count}
        {--expect-noop= : Exact Production noop candidate count}
        {--expect-blocked= : Exact Production blocked candidate count}
        {--expect-review= : Exact Production review candidate count}
        {--expect-plan-sha= : Exact Production deterministic dry-run plan SHA256}';

    protected $description = 'Preview or exact-gated apply P8.1 Parent multi-shipment logistics cost aggregation.';

    public function handle(CostMaterializationService $service): int
    {
        $orders = $this->orders();
        $preflight = $this->summarize($orders, fn ($order) => $service->previewMultiShipmentGate($order), 'dry_run');
        if (! (bool) $this->option('apply')) {
            $this->line(json_encode($preflight, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }
        if (! $this->applyAllowed()) {
            $this->error('Production --apply requires ERP_ACCOUNTING_MULTI_SHIPMENT_PRODUCTION_APPLY_ENABLED=true for this process.');
            return self::FAILURE;
        }
        if (app()->environment('production') && ! $this->expectedCountsMatch($preflight)) {
            $this->line(json_encode(['mode' => 'apply', 'gate' => 'blocked_preflight_mismatch', 'preflight' => $preflight], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return self::FAILURE;
        }

        $applied = $this->summarize($orders, fn ($order) => $service->applyMultiShipmentGate($order), 'apply');
        $this->line(json_encode($applied, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }

    private function orders()
    {
        $query = Order::query()->where('order_kind', 'customer')
            ->whereDoesntHave('externalReferences', fn ($refs) => $refs->where('provider', 'historical_logistics'))
            ->orderBy('id');
        if ($this->option('order') !== null) $query->whereKey((int) $this->option('order'));
        else $query->limit(max(1, min(10000, (int) $this->option('limit'))));
        return $query->get();
    }

    /** @return array<string,mixed> */
    private function summarize($orders, callable $resolver, string $mode): array
    {
        $summary = $this->emptySummary($mode); $plan = [];
        foreach ($orders as $order) {
            $result = $resolver($order); $summary['orders_seen']++;
            $summary['shipments_seen'] += (int) ($result['shipment_count'] ?? 0);
            $candidates = $mode === 'apply'
                ? array_merge($result['applied'] ?? [], $result['noop'] ?? [], $result['blocked'] ?? [], $result['review'] ?? [])
                : ($result['candidates'] ?? []);
            if ($mode === 'apply') {
                $summary['apply'] += count($result['applied'] ?? []); $summary['noop'] += count($result['noop'] ?? []);
                $summary['blocked'] += count($result['blocked'] ?? []); $summary['review'] += count($result['review'] ?? []);
                continue;
            }
            foreach ($candidates as $candidate) $this->summarizeCandidate($summary, $plan, (int) $order->id, (int) ($result['shipment_count'] ?? 0), $candidate);
        }
        if ($mode === 'dry_run') {
            usort($plan, fn (array $a, array $b): int => [$a['order_id'], $a['cost_type']] <=> [$b['order_id'], $b['cost_type']]);
            $summary['plan_sha'] = hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        ksort($summary['by_type']); ksort($summary['delta_by_type_currency']);
        return $summary;
    }

    /** @param array<string,mixed> $summary @param list<array<string,mixed>> $plan */
    private function summarizeCandidate(array &$summary, array &$plan, int $orderId, int $shipmentCount, array $candidate): void
    {
        $type = (string) ($candidate['cost_type'] ?? 'unknown');
        $status = (string) ($candidate['materialization_status'] ?? 'blocked');
        if ($status === 'blocked' && ($candidate['blocker'] ?? null) === 'stronger_or_different_existing_fact') $bucket = 'review';
        elseif (in_array($status, ['apply', 'noop', 'blocked'], true)) $bucket = $status;
        else $bucket = 'blocked';
        $summary['candidates_seen']++; $summary[$bucket]++;
        $summary['by_type'][$type] ??= ['apply' => 0, 'noop' => 0, 'blocked' => 0, 'review' => 0];
        $summary['by_type'][$type][$bucket]++;
        if ($bucket === 'apply' && ($candidate['delta_amount'] ?? null) !== null) {
            $currency = strtoupper((string) ($candidate['currency'] ?? '')) ?: 'UNKNOWN';
            $summary['delta_by_type_currency'][$type] ??= [];
            $summary['delta_by_type_currency'][$type][$currency] = bcadd(
                $summary['delta_by_type_currency'][$type][$currency] ?? '0', (string) $candidate['delta_amount'], 2,
            );
        }
        $plan[] = [
            'order_id' => $orderId, 'shipment_count' => $shipmentCount, 'cost_type' => $type,
            'disposition' => $bucket, 'amount' => $candidate['amount'] ?? null, 'currency' => $candidate['currency'] ?? null,
            'previous_amount' => $candidate['previous_amount'] ?? null, 'delta_amount' => $candidate['delta_amount'] ?? null,
            'source_reference' => $candidate['source_reference'] ?? null, 'blocker' => $candidate['blocker'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function emptySummary(string $mode): array
    {
        return [
            'mode' => $mode, 'orders_seen' => 0, 'shipments_seen' => 0, 'candidates_seen' => 0,
            'apply' => 0, 'noop' => 0, 'blocked' => 0, 'review' => 0,
            'by_type' => [], 'delta_by_type_currency' => [], 'plan_sha' => null,
        ];
    }

    private function expectedCountsMatch(array $summary): bool
    {
        $expected = [
            'orders_seen' => $this->expectedInt('expect-orders'), 'shipments_seen' => $this->expectedInt('expect-shipments'),
            'apply' => $this->expectedInt('expect-apply'), 'noop' => $this->expectedInt('expect-noop'),
            'blocked' => $this->expectedInt('expect-blocked'), 'review' => $this->expectedInt('expect-review'),
        ];
        foreach ($expected as $key => $value) if ($value === null || (int) $summary[$key] !== $value) return false;
        $plan = trim((string) ($this->option('expect-plan-sha') ?? ''));
        return preg_match('/^[a-f0-9]{64}$/D', $plan) === 1 && hash_equals($plan, (string) $summary['plan_sha']);
    }

    private function expectedInt(string $option): ?int
    {
        $value = $this->option($option);
        return $value !== null && preg_match('/^\d+$/D', (string) $value) === 1 ? (int) $value : null;
    }

    private function applyAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) return true;
        if (! app()->environment('production')) return false;
        return filter_var(getenv('ERP_ACCOUNTING_MULTI_SHIPMENT_PRODUCTION_APPLY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL);
    }
}
