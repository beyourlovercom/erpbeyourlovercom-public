<?php

namespace App\Modules\Finance\Application;

use App\Modules\Orders\Application\PaymentHealthPreviewService;
use Carbon\CarbonImmutable;

final class PaymentReliabilityClosureService
{
    public function __construct(
        private readonly PaymentHealthPreviewService $health,
        private readonly GatewayEconomicsPreviewService $economics,
    ) {}

    /** @return array<string,mixed> */
    public function preview(
        CarbonImmutable $asOf,
        int $healthDays = 1,
        int $economicsDays = 30,
        int $detailLimit = 50,
    ): array {
        $health = $this->health->preview($asOf, $healthDays, $detailLimit);
        $economics = $this->economics->preview($asOf, $economicsDays);

        $runtimeState = $this->runtimeState($health, $economics);

        return [
            'contract_version' => (string) config('payment_reliability.contract_version', 'P1.9-v1'),
            'as_of' => $asOf->utc()->toIso8601String(),
            'runtime_state' => $runtimeState,
            'owner_uat_required' => true,
            'production_authorized' => false,
            'gateway_ownership' => (array) config('payment_reliability.gateway_ownership', []),
            'checkout_handoff' => (array) config('payment_reliability.checkout_handoff', []),
            'capabilities' => (array) config('payment_reliability.capabilities', []),
            'rollout_gates' => $this->rolloutGates($health),
            'production_prerequisites' => array_values((array) config('payment_reliability.production_prerequisites', [])),
            'health' => $health,
            'economics' => $economics,
            'coverage' => [
                'raw_provider_attempts' => 'unavailable',
                'webhook_event_store' => 'unavailable',
                'dispute_chargeback_cost' => 'unavailable',
                'airwallex_actual_processor_fee' => 'unavailable',
                'payment_orders_are_not_raw_attempts' => true,
                'accounting_3_5pct_is_actual_gateway_fee' => false,
                'cross_currency_amount_total' => 'prohibited',
            ],
        ];
    }

    /** @param array<string,mixed> $health @param array<string,mixed> $economics */
    private function runtimeState(array $health, array $economics): string
    {
        $healthCritical = (string) ($health['health'] ?? '') === 'critical';
        $healthTruncated = (bool) ($health['cohort']['scan_truncated'] ?? false)
            || (bool) ($health['current_backlog']['scan_truncated'] ?? false);
        $economicsTruncated = (bool) ($economics['scan_truncated'] ?? false);

        if ($healthCritical || $healthTruncated || $economicsTruncated) {
            return 'blocked';
        }

        if ((string) ($health['health'] ?? '') === 'warning'
            || (string) ($health['sync']['state'] ?? '') === 'partial') {
            return 'review';
        }

        return 'ready';
    }

    /** @param array<string,mixed> $health @return array<string,array<string,mixed>> */
    private function rolloutGates(array $health): array
    {
        $mismatchCount = (int) ($health['anomalies']['fulfillment_payment_mismatch_count'] ?? 0);
        $duplicateGroups = (int) ($health['anomalies']['duplicate_transaction_identity_group_count'] ?? 0);
        $syncState = (string) ($health['sync']['state'] ?? 'missing');
        $reviewCount = (int) ($health['current_backlog']['pending_evidence_conflict'] ?? 0)
            + (int) ($health['current_backlog']['hold_review'] ?? 0)
            + (int) ($health['current_backlog']['missing_timestamp_review'] ?? 0);

        return [
            'payment_authority_safety' => [
                'state' => ($mismatchCount === 0 && $duplicateGroups === 0) ? 'pass' : 'blocked',
                'fulfillment_payment_mismatch_count' => $mismatchCount,
                'duplicate_transaction_identity_group_count' => $duplicateGroups,
            ],
            'woo_inbound_sync' => [
                'state' => match ($syncState) {
                    'healthy' => 'pass',
                    'partial' => 'review',
                    default => 'blocked',
                },
                'sync_state' => $syncState,
            ],
            'stale_pending_review' => [
                'state' => $reviewCount > 0 ? 'review' : 'pass',
                'manual_review_count' => $reviewCount,
                'stale_unpaid_count' => (int) ($health['current_backlog']['stale_unpaid'] ?? 0),
            ],
            'economics_evidence' => [
                'state' => 'pass_with_known_gaps',
                'airwallex_actual_processor_fee' => 'unavailable',
                'dispute_chargeback_cost' => 'unavailable',
                'cross_currency_amount_total' => 'prohibited',
            ],
            'provider_telemetry_coverage' => [
                'state' => 'known_gap',
                'raw_provider_attempts' => 'unavailable',
                'webhook_event_store' => 'unavailable',
            ],
            'production_schema' => [
                'state' => 'pending_external',
                'action' => 'explicit_production_schema_gate_required',
            ],
            'production_backfill' => [
                'state' => 'not_authorized',
                'action' => 'separate_future_gate_if_desired',
            ],
            'checkout_routing_change' => [
                'state' => 'contract_frozen_not_applied',
                'action' => 'checkout_experience_workstream',
            ],
        ];
    }
}
