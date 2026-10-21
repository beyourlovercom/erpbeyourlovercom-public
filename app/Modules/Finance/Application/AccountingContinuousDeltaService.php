<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Application\Contracts\AccountingContinuousDeltaCandidateStore;
use App\Modules\Sync\Application\Contracts\SyncRunTracker;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

final class AccountingContinuousDeltaService
{
    private const RUN_TYPE = 'accounting_continuous_delta';
    private const PROVIDER_KEY = 'internal';
    private const CONNECTION_KEY = 'finance';
    private const CHECKPOINT_KEY = 'checkpoint_at';
    private const OVERLAP_MINUTES = 5;
    private const INITIAL_LOOKBACK_MINUTES = 180;
    private const MAX_LIMIT = 10000;

    public function __construct(
        private readonly AccountingContinuousDeltaCandidateStore $candidates,
        private readonly CostMaterializationService $materializer,
        private readonly OrderAnalysisService $analysis,
        private readonly SyncRunTracker $runs,
    ) {}

    /** @return array<string,mixed> */
    public function preview(int $limit = self::MAX_LIMIT, ?CarbonImmutable $windowEnd = null): array
    {
        $plan = $this->plan($limit, $windowEnd);
        $summary = $this->baseSummary('dry_run', $plan);
        if ($plan['overflow']) {
            $summary['gate'] = 'blocked_candidate_limit_exceeded';
            return $summary;
        }

        foreach ($plan['orders'] as $order) {
            $this->accumulatePreview($summary, $this->materializer->previewProductCostGate($order), 'product_cost');
            $this->accumulatePreview($summary, $this->materializer->previewFixedRules($order), 'fixed_costs');
            $this->accumulatePreview($summary, $this->materializer->previewPaymentFeeGate($order), 'payment_fee');
            if ($this->kolCommissionEnabled()) {
                $this->accumulatePreview($summary, $this->materializer->previewKolCommissionGate($order), 'kol_commission');
            }
            $this->accumulatePreview($summary, $this->materializer->previewWarehouseCostGate($order), 'warehouse_cost');
            $this->accumulatePreview($summary, $this->materializer->previewLastMileEstimateGate($order), 'last_mile_estimate');
            $this->accumulatePreview($summary, $this->materializer->previewCarrierBillingGate($order), 'last_mile_actual');
            $this->accumulatePreview($summary, $this->materializer->previewOverseasHandlingGate($order), 'overseas_handling_actual');
        }

        return $summary;
    }

    /** @return array<string,mixed> */
    public function apply(int $limit = self::MAX_LIMIT, ?CarbonImmutable $windowEnd = null): array
    {
        $plan = $this->plan($limit, $windowEnd);
        $summary = $this->baseSummary('apply', $plan);
        if ($plan['overflow']) {
            $summary['gate'] = 'blocked_candidate_limit_exceeded';
            return $summary;
        }

        $run = $this->runs->start(
            self::RUN_TYPE,
            $plan['orders']->count(),
            null,
            self::PROVIDER_KEY,
            self::CONNECTION_KEY,
            [
                'mode' => 'apply',
                'checkpoint_before' => $plan['checkpoint_before'],
                'window_start' => $plan['window_start']->toIso8601String(),
                'window_end' => $plan['window_end']->toIso8601String(),
            ],
        );
        $summary['run_id'] = (int) $run->getKey();

        foreach ($plan['orders'] as $order) {
            $item = $this->runs->addItem($run, [
                'operation' => 'finance.accounting_continuous_delta',
                'order_id' => (int) $order->getKey(),
            ]);
            $item = $this->runs->markProcessing($item);
            $blockedBefore = $this->blockedTotal($summary);

            try {
                $this->accumulateApply($summary, $this->materializer->applyProductCostGate($order), 'product_cost');
                $this->accumulateApply($summary, $this->materializer->applyFixedRules($order), 'fixed_costs');
                $this->accumulateApply($summary, $this->materializer->applyPaymentFeeGate($order), 'payment_fee');
                if ($this->kolCommissionEnabled()) {
                    $this->accumulateApply($summary, $this->materializer->applyKolCommissionGate($order), 'kol_commission');
                }
                $this->accumulateApply($summary, $this->materializer->applyWarehouseCostGate($order), 'warehouse_cost');
                $this->accumulateApply($summary, $this->materializer->applyLastMileEstimateGate($order), 'last_mile_estimate');
                $this->accumulateApply($summary, $this->materializer->applyCarrierBillingGate($order), 'last_mile_actual');
                $this->accumulateApply($summary, $this->materializer->applyOverseasHandlingGate($order), 'overseas_handling_actual');
                $this->analysis->refreshCompleteness($order, preserveReconciled: false);

                $resultCode = $this->blockedTotal($summary) > $blockedBefore
                    ? 'completed_with_business_blockers'
                    : 'completed';
                $this->runs->finishSucceeded($item, $resultCode);
                $summary['processed']++;
            } catch (Throwable $exception) {
                $summary['failures']++;
                $this->runs->finishFailed(
                    $item,
                    'failed',
                    'accounting_continuous_delta_failed',
                    $exception->getMessage(),
                );
            }
        }

        if ($summary['failures'] === 0) {
            $run = $this->runs->annotateContext($run, [self::CHECKPOINT_KEY => $plan['window_end']->toIso8601String()]);
            $summary['checkpoint_after'] = $plan['window_end']->toIso8601String();
            $summary['checkpoint_advanced'] = true;
        }
        $completed = $this->runs->complete($run, null);
        $summary['run_status'] = $completed->status instanceof \BackedEnum
            ? $completed->status->value
            : (string) $completed->status;

        return $summary;
    }

    /** @return array<string,mixed> */
    private function plan(int $limit, ?CarbonImmutable $windowEnd): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new RuntimeException('accounting_continuous_delta_limit_out_of_range');
        }

        $end = ($windowEnd ?? CarbonImmutable::now('UTC'))->utc();
        $checkpoint = $this->runs->latestContextString(
            self::RUN_TYPE,
            self::PROVIDER_KEY,
            self::CONNECTION_KEY,
            self::CHECKPOINT_KEY,
        );
        if ($checkpoint !== null) {
            $checkpointAt = CarbonImmutable::parse($checkpoint)->utc();
            if ($checkpointAt->gt($end)) {
                throw new RuntimeException('accounting_continuous_delta_checkpoint_in_future');
            }
            $start = $checkpointAt->subMinutes(self::OVERLAP_MINUTES);
        } else {
            $start = $end->subMinutes(self::INITIAL_LOOKBACK_MINUTES);
        }

        $orders = $this->candidates->candidates($start, $end, $limit + 1);

        return [
            'checkpoint_before' => $checkpoint,
            'window_start' => $start,
            'window_end' => $end,
            'limit' => $limit,
            'orders' => $orders,
            'overflow' => $orders->count() > $limit,
        ];
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function baseSummary(string $mode, array $plan): array
    {
        return [
            'mode' => $mode,
            'gate' => 'pass',
            'candidate_limit' => $plan['limit'],
            'candidate_count' => $plan['orders']->count(),
            'candidate_overflow' => $plan['overflow'],
            'window_start' => $plan['window_start']->toIso8601String(),
            'window_end' => $plan['window_end']->toIso8601String(),
            'checkpoint_before' => $plan['checkpoint_before'],
            'checkpoint_after' => $plan['checkpoint_before'],
            'checkpoint_advanced' => false,
            'processed' => 0,
            'failures' => 0,
            'run_id' => null,
            'run_status' => null,
            'stages' => [
                'product_cost' => $this->emptyStage(),
                'fixed_costs' => $this->emptyStage(),
                'payment_fee' => $this->emptyStage(),
                'kol_commission' => $this->emptyStage(),
                'warehouse_cost' => $this->emptyStage(),
                'last_mile_estimate' => $this->emptyStage(),
                'last_mile_actual' => $this->emptyStage(),
                'overseas_handling_actual' => $this->emptyStage(),
            ],
        ];
    }

    private function kolCommissionEnabled(): bool
    {
        return (bool) config('erp_accounting.continuous_delta.kol_commission_enabled', false);
    }

    /** @return array{applied:int,noop:int,blocked:int,blockers:array<string,int>} */
    private function emptyStage(): array
    {
        return ['applied' => 0, 'noop' => 0, 'blocked' => 0, 'blockers' => []];
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $result */
    private function accumulatePreview(array &$summary, array $result, string $stage): void
    {
        foreach ($result['candidates'] ?? [] as $candidate) {
            $status = (string) ($candidate['materialization_status'] ?? 'blocked');
            if ($status === 'apply') {
                $summary['stages'][$stage]['applied']++;
            } elseif ($status === 'noop') {
                $summary['stages'][$stage]['noop']++;
            } else {
                $this->recordBlocker($summary, $stage, $candidate);
            }
        }
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $result */
    private function accumulateApply(array &$summary, array $result, string $stage): void
    {
        $summary['stages'][$stage]['applied'] += count($result['applied'] ?? []);
        $summary['stages'][$stage]['noop'] += count($result['noop'] ?? []);
        foreach ($result['blocked'] ?? [] as $candidate) {
            $this->recordBlocker($summary, $stage, $candidate);
        }
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $candidate */
    private function recordBlocker(array &$summary, string $stage, array $candidate): void
    {
        $reason = (string) ($candidate['blocker'] ?? 'blocked');
        $summary['stages'][$stage]['blocked']++;
        $summary['stages'][$stage]['blockers'][$reason] = ($summary['stages'][$stage]['blockers'][$reason] ?? 0) + 1;
        ksort($summary['stages'][$stage]['blockers']);
    }

    /** @param array<string,mixed> $summary */
    private function blockedTotal(array $summary): int
    {
        return array_sum(array_map(
            static fn (array $stage): int => (int) ($stage['blocked'] ?? 0),
            $summary['stages'],
        ));
    }
}
