<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\PaymentReliabilityClosureService;
use App\Modules\Logistics\Infrastructure\Models\FulfillmentAllocation;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Infrastructure\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PaymentReliabilityClosureV1Test extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $asOf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asOf = CarbonImmutable::parse('2026-09-24T00:00:00Z');
        config([
            'payment_observability.sync_stale_after_hours' => 2.5,
            'payment_observability.max_cohort_scan' => 10000,
            'payment_observability.max_anomaly_details' => 200,
            'payment_reconciliation.pending_threshold_hours' => [
                'default' => 24,
                'ppcp' => 24,
                'cpsw_stripe_element' => 24,
                'airwallex_card' => 24,
                'airwallex_klarna' => 72,
            ],
            'payment_reconciliation.max_scan' => 10000,
            'payment_reconciliation.max_detail_limit' => 1000,
            'gateway_economics.max_scan' => 50000,
            'gateway_economics.accounting_payment_fee_rate' => '0.03500000',
        ]);
    }

    public function test_gateway_ownership_and_checkout_handoff_match_frozen_contract(): void
    {
        $this->healthySync();

        $result = $this->preview();

        self::assertSame('woocommerce_payments', $result['gateway_ownership']['primary_card']['owner']);
        self::assertSame('enabled', $result['gateway_ownership']['primary_card']['normal_checkout']);
        self::assertSame('woocommerce_payments', $result['gateway_ownership']['apple_pay']['owner']);
        self::assertSame('cart_and_checkout_only', $result['gateway_ownership']['apple_pay']['placement']);
        self::assertSame('woocommerce_payments', $result['gateway_ownership']['google_pay']['owner']);
        self::assertSame('ppcp', $result['gateway_ownership']['paypal_wallet']['owner']);
        self::assertSame('airwallex_klarna', $result['gateway_ownership']['klarna_bnpl']['owner']);
        self::assertSame('cpsw_stripe_element', $result['gateway_ownership']['card_hot_standby']['owner']);
        self::assertSame('hidden', $result['gateway_ownership']['card_hot_standby']['normal_checkout']);
        self::assertSame('hidden_candidate', $result['gateway_ownership']['paypal_card']['normal_checkout']);
        self::assertSame('hidden_candidate', $result['gateway_ownership']['airwallex_card']['normal_checkout']);

        self::assertSame('disabled', $result['checkout_handoff']['product_page_express_payment']);
        self::assertSame('capability_driven', $result['checkout_handoff']['cart_checkout_express_payment']);
        self::assertSame(1, $result['checkout_handoff']['normal_card_entry_points']);
        self::assertSame('disabled', $result['checkout_handoff']['issuer_decline_auto_retry']);
    }

    public function test_healthy_evidence_is_ready_even_with_explicit_known_coverage_gaps(): void
    {
        $this->healthySync();

        $result = $this->preview();

        self::assertSame('ready', $result['runtime_state']);
        self::assertSame('pass', $result['rollout_gates']['payment_authority_safety']['state']);
        self::assertSame('pass', $result['rollout_gates']['woo_inbound_sync']['state']);
        self::assertSame('pass', $result['rollout_gates']['stale_pending_review']['state']);
        self::assertSame('pass_with_known_gaps', $result['rollout_gates']['economics_evidence']['state']);
        self::assertSame('known_gap', $result['rollout_gates']['provider_telemetry_coverage']['state']);
        self::assertSame('unavailable', $result['coverage']['raw_provider_attempts']);
        self::assertSame('unavailable', $result['coverage']['webhook_event_store']);
        self::assertFalse($result['production_authorized']);
        self::assertTrue($result['owner_uat_required']);
    }

    public function test_fulfillment_payment_mismatch_blocks_closure(): void
    {
        $this->healthySync();
        $order = $this->wooOrder(
            method: 'ppcp',
            status: 'pending',
            paidAt: null,
            actualFeeStatus: 'missing',
        );
        FulfillmentAllocation::query()->create([
            'order_id' => $order->id,
            'decision_source' => 'manual',
            'submitted_at' => now(),
        ]);

        $result = $this->preview();

        self::assertSame('blocked', $result['runtime_state']);
        self::assertSame('blocked', $result['rollout_gates']['payment_authority_safety']['state']);
        self::assertSame(1, $result['rollout_gates']['payment_authority_safety']['fulfillment_payment_mismatch_count']);
    }

    public function test_partial_sync_or_manual_review_backlog_yields_review(): void
    {
        $this->syncRun('partial', '2026-09-23T23:00:00Z', '2026-09-23T23:05:00Z', failedItems: 1);
        $this->wooOrder(
            method: 'ppcp',
            status: 'on-hold',
            paidAt: '2026-09-23T01:00:00Z',
            actualFee: '2.40',
            actualFeeStatus: 'observed',
        );

        $result = $this->preview();

        self::assertSame('review', $result['runtime_state']);
        self::assertSame('review', $result['rollout_gates']['woo_inbound_sync']['state']);
        self::assertSame('review', $result['rollout_gates']['stale_pending_review']['state']);
        self::assertSame(1, $result['rollout_gates']['stale_pending_review']['manual_review_count']);
    }

    public function test_stale_or_missing_sync_blocks_closure(): void
    {
        $this->syncRun('completed', '2026-09-23T18:00:00Z', '2026-09-23T18:05:00Z');
        self::assertSame('blocked', $this->preview()['runtime_state']);

        SyncRun::query()->delete();
        self::assertSame('blocked', $this->preview()['runtime_state']);
    }

    public function test_scan_truncation_blocks_closure_even_when_health_has_no_other_anomaly(): void
    {
        $this->healthySync();
        config(['payment_observability.max_cohort_scan' => 1]);

        $this->wooOrder(
            method: 'woocommerce_payments',
            status: 'processing',
            paidAt: '2026-09-24T01:00:00Z',
            actualFee: '3.00',
            actualFeeStatus: 'observed',
        );
        $this->wooOrder(
            method: 'woocommerce_payments',
            status: 'processing',
            paidAt: '2026-09-24T02:00:00Z',
            actualFee: '2.00',
            actualFeeStatus: 'observed',
        );

        $result = $this->preview();

        self::assertTrue($result['health']['cohort']['scan_truncated']);
        self::assertSame('blocked', $result['runtime_state']);
    }

    public function test_health_and_economics_share_same_as_of_and_keep_currencies_separate(): void
    {
        $this->healthySync();
        $this->wooOrder(
            method: 'woocommerce_payments',
            status: 'processing',
            paidAt: '2026-09-24T01:00:00Z',
            currency: 'USD',
            gross: '100.00',
            refund: '0.00',
            actualFee: '3.00',
            actualFeeStatus: 'observed',
        );
        $this->wooOrder(
            method: 'woocommerce_payments',
            status: 'processing',
            paidAt: '2026-09-24T02:00:00Z',
            currency: 'EUR',
            gross: '80.00',
            refund: '0.00',
            actualFee: '2.50',
            actualFeeStatus: 'observed',
        );

        $result = $this->preview();
        $keys = collect($result['economics']['rows'])
            ->map(fn (array $row): string => $row['payment_method'].'|'.$row['currency'])
            ->all();

        self::assertSame('2026-09-24T00:00:00+00:00', $result['as_of']);
        self::assertSame($result['as_of'], $result['health']['as_of']);
        self::assertSame($result['as_of'], $result['economics']['as_of']);
        self::assertContains('woocommerce_payments|USD', $keys);
        self::assertContains('woocommerce_payments|EUR', $keys);
        self::assertSame('prohibited', $result['coverage']['cross_currency_amount_total']);
        self::assertArrayNotHasKey('total_amount', $result['economics']);
    }

    public function test_production_gates_remain_external_and_not_authorized(): void
    {
        $this->healthySync();

        $result = $this->preview();

        self::assertSame('pending_external', $result['rollout_gates']['production_schema']['state']);
        self::assertSame('not_authorized', $result['rollout_gates']['production_backfill']['state']);
        self::assertSame('contract_frozen_not_applied', $result['rollout_gates']['checkout_routing_change']['state']);
        self::assertFalse($result['production_authorized']);
        self::assertContains('explicit_backup_and_production_schema_gate', $result['production_prerequisites']);
        self::assertContains('production_code_deploy_gate', $result['production_prerequisites']);
        self::assertContains('no_gateway_removal_before_checkout_regression_and_payment_uat', $result['production_prerequisites']);
    }

    public function test_json_command_leaks_no_transaction_fee_meta_or_customer_pii(): void
    {
        $this->healthySync();
        $order = $this->wooOrder(
            method: 'woocommerce_payments',
            status: 'processing',
            paidAt: null,
            transactionId: 'TOP-SECRET-CLOSURE-TXN',
            actualFee: null,
            actualFeeStatus: 'missing',
        );
        $order->address()->create([
            'recipient_name' => 'Sensitive Closure Customer',
            'phone' => '+15550006666',
            'email' => 'closure-private@example.test',
            'country' => 'US',
            'province_state' => 'CA',
            'city' => 'Los Angeles',
            'address_line_1' => '77 Private Blvd',
            'postal_code' => '90001',
        ]);

        $exit = Artisan::call('payments:reliability-closure', [
            '--json' => true,
            '--as-of' => '2026-09-24T00:00:00Z',
            '--health-days' => 1,
            '--economics-days' => 30,
            '--limit' => 20,
        ]);
        $output = Artisan::output();

        self::assertSame(0, $exit);
        self::assertStringNotContainsString('TOP-SECRET-CLOSURE-TXN', $output);
        self::assertStringNotContainsString('_wcpay_transaction_fee', $output);
        self::assertStringNotContainsString('Sensitive Closure Customer', $output);
        self::assertStringNotContainsString('+15550006666', $output);
        self::assertStringNotContainsString('closure-private@example.test', $output);
        self::assertStringContainsString('"production_authorized":false', $output);
        self::assertStringContainsString('"payment_orders_are_not_raw_attempts":true', $output);
    }

    public function test_closure_preview_performs_zero_database_writes(): void
    {
        $this->healthySync();
        $this->wooOrder(
            method: 'woocommerce_payments',
            status: 'processing',
            paidAt: '2026-09-24T01:00:00Z',
            actualFee: '3.25',
            actualFeeStatus: 'observed',
        );
        $writes = [];

        DB::listen(function ($query) use (&$writes): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (preg_match('/^(insert|update|delete|replace|alter|create|drop|truncate)\b/', $sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->preview();

        self::assertSame([], $writes);
    }

    /** @return array<string,mixed> */
    private function preview(): array
    {
        return app(PaymentReliabilityClosureService::class)->preview(
            asOf: $this->asOf,
            healthDays: 1,
            economicsDays: 30,
            detailLimit: 50,
        );
    }

    private function healthySync(): SyncRun
    {
        return $this->syncRun('completed', '2026-09-23T23:00:00Z', '2026-09-23T23:05:00Z');
    }

    private function syncRun(
        string $status,
        string $startedAt,
        ?string $completedAt,
        int $failedItems = 0,
        int $conflictItems = 0,
    ): SyncRun {
        return SyncRun::query()->create([
            'run_type' => 'woocommerce_order_incremental',
            'provider_key' => 'woocommerce',
            'connection_key' => 'beyourlover.com',
            'status' => $status,
            'requested_items' => 5,
            'processed_items' => 5,
            'succeeded_items' => max(0, 5 - $failedItems - $conflictItems),
            'failed_items' => $failedItems,
            'conflict_items' => $conflictItems,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
        ]);
    }

    private function wooOrder(
        string $method,
        string $status,
        ?string $paidAt,
        string $currency = 'USD',
        string $gross = '100.00',
        ?string $refund = '0.00',
        ?string $transactionId = null,
        ?string $actualFee = null,
        ?string $actualFeeStatus = 'missing',
    ): Order {
        $providerId = (string) random_int(100000, 999999);
        $order = Order::query()->create([
            'internal_order_number' => 'CLOSE-'.uniqid(),
            'erp_order_id' => '007-0924-c'.$providerId,
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => $providerId,
            'order_date' => '2026-09-24',
            'order_status' => 'pending_shipment',
            'currency' => $currency,
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'beyourlover.com',
            'reference' => $providerId,
            'provider_modified_at' => '2026-09-23T23:00:00Z',
        ]);
        $order->financials()->create([
            'currency' => $currency,
            'provider' => 'woocommerce',
            'provider_reference' => $providerId,
            'provider_order_status' => $status,
            'provider_modified_at' => '2026-09-23T23:00:00Z',
            'payment_method' => $method,
            'payment_transaction_id' => $transactionId,
            'paid_at' => $paidAt,
            'provider_order_total' => $gross,
            'refund_amount' => $refund,
            'actual_payment_fee' => $actualFee,
            'actual_payment_fee_source' => $actualFeeStatus === 'observed'
                ? $this->feeSource($method)
                : null,
            'actual_payment_fee_status' => $actualFeeStatus,
            'revenue_provenance' => 'provider_import',
            'completeness_status' => 'INCOMPLETE',
        ]);

        return $order->fresh(['financials', 'externalReferences']);
    }

    private function feeSource(string $method): ?string
    {
        return match ($method) {
            'woocommerce_payments' => '_wcpay_transaction_fee',
            'cpsw_stripe_element' => '_cpsw_stripe_fee',
            'ppcp', 'ppcp_card' => '_paypal_fee',
            default => null,
        };
    }
}
