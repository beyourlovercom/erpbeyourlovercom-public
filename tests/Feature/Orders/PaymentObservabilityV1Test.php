<?php

namespace Tests\Feature\Orders;

use App\Modules\Logistics\Infrastructure\Models\FulfillmentAllocation;
use App\Modules\Orders\Application\PaymentHealthPreviewService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Infrastructure\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PaymentObservabilityV1Test extends TestCase
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
        ]);
    }

    public function test_cohort_metrics_and_gateway_breakdown_are_descriptive_and_correct(): void
    {
        $this->healthySync();
        $this->wooOrder('woocommerce_payments', 'processing', paidAt: '2026-09-23T01:00:00Z');
        $this->wooOrder('woocommerce_payments', 'failed');
        $this->wooOrder('ppcp', 'pending');
        $this->wooOrder('ppcp', 'on-hold');
        $this->wooOrder('airwallex_klarna', 'cancelled');
        $this->wooOrder('airwallex_klarna', 'refunded');

        $result = $this->preview();

        self::assertSame(6, $result['cohort']['payment_orders']);
        self::assertSame(1, $result['cohort']['paid_orders']);
        self::assertSame(1, $result['cohort']['hard_failed_orders']);
        self::assertSame(1, $result['cohort']['pending_orders']);
        self::assertSame(1, $result['cohort']['hold_orders']);
        self::assertSame(1, $result['cohort']['cancelled_orders']);
        self::assertSame(1, $result['cohort']['refunded_orders']);
        self::assertSame(0.1667, $result['cohort']['current_paid_rate']);

        self::assertSame(2, $result['cohort']['by_payment_method']['woocommerce_payments']['payment_orders']);
        self::assertSame(2, $result['cohort']['by_payment_method']['ppcp']['payment_orders']);
        self::assertSame(2, $result['cohort']['by_payment_method']['airwallex_klarna']['payment_orders']);
    }

    public function test_zero_order_cohort_handles_paid_rate_safely(): void
    {
        $this->healthySync();

        $result = $this->preview();

        self::assertSame(0, $result['cohort']['payment_orders']);
        self::assertSame(0.0, $result['cohort']['current_paid_rate']);
        self::assertSame('ok', $result['health']);
    }

    public function test_stale_payment_backlog_is_incorporated_without_becoming_critical_by_itself(): void
    {
        $this->healthySync();
        $this->wooOrder(
            'ppcp',
            'pending',
            providerModifiedAt: '2026-09-20T00:00:00Z',
            orderDate: '2026-09-20',
        );

        $result = $this->preview();

        self::assertSame(1, $result['current_backlog']['stale_unpaid']);
        self::assertSame(0, $result['current_backlog']['pending_evidence_conflict']);
        self::assertSame('ok', $result['health']);
    }

    public function test_fulfillment_progress_with_unpaid_authority_is_critical_mismatch(): void
    {
        $this->healthySync();
        $order = $this->wooOrder('ppcp', 'pending', orderStatus: 'shipped');

        $result = $this->preview();

        self::assertSame('critical', $result['health']);
        self::assertSame(1, $result['anomalies']['fulfillment_payment_mismatch_count']);
        self::assertSame('pending', $result['anomalies']['fulfillment_payment_mismatches'][0]['payment_authority']);
        self::assertSame('shipped', $result['anomalies']['fulfillment_payment_mismatches'][0]['erp_fulfillment_status']);
        self::assertSame($order->erp_order_id, $result['anomalies']['fulfillment_payment_mismatches'][0]['erp_order_id']);
    }

    public function test_pending_unpaid_without_fulfillment_materialization_is_not_mismatch(): void
    {
        $this->healthySync();
        $this->wooOrder('ppcp', 'pending', orderStatus: 'pending_shipment');

        $result = $this->preview();

        self::assertSame(0, $result['anomalies']['fulfillment_payment_mismatch_count']);
    }

    public function test_allocation_materialization_is_detected_even_while_order_status_is_pending_shipment(): void
    {
        $this->healthySync();
        $order = $this->wooOrder('ppcp', 'pending', orderStatus: 'pending_shipment');
        FulfillmentAllocation::query()->create([
            'order_id' => $order->id,
            'decision_source' => 'manual',
            'submitted_at' => now(),
        ]);

        $result = $this->preview();

        self::assertSame('critical', $result['health']);
        self::assertSame(1, $result['anomalies']['fulfillment_payment_mismatch_count']);
        self::assertTrue($result['anomalies']['fulfillment_payment_mismatches'][0]['has_fulfillment_allocation']);
    }

    public function test_nominal_paid_provider_status_without_paid_at_is_warning_evidence_gap(): void
    {
        $this->healthySync();
        $this->wooOrder('woocommerce_payments', 'processing', paidAt: null);

        $result = $this->preview();

        self::assertSame(1, $result['anomalies']['payment_evidence_gap_count']);
        self::assertSame('unconfirmed', $result['anomalies']['payment_evidence_gaps'][0]['payment_authority']);
        self::assertSame('warning', $result['health']);
    }

    public function test_duplicate_transaction_identity_is_visible_without_exposing_raw_identity(): void
    {
        $this->healthySync();

        Schema::table('order_financials', function ($table): void {
            $table->dropUnique('order_financials_payment_transaction_unique');
        });

        $first = $this->wooOrder('ppcp', 'processing', paidAt: '2026-09-23T01:00:00Z', transactionId: 'DUPLICATE-SECRET-TXN');
        $second = $this->wooOrder('ppcp', 'processing', paidAt: '2026-09-23T02:00:00Z', transactionId: null);
        $second->financials()->update(['payment_transaction_id' => 'DUPLICATE-SECRET-TXN']);

        $result = $this->preview();

        self::assertSame('critical', $result['health']);
        self::assertSame(1, $result['anomalies']['duplicate_transaction_identity_group_count']);
        self::assertSame(1, $result['anomalies']['duplicate_transaction_identity_extra_rows']);
        self::assertSame(['ppcp' => 1], $result['anomalies']['duplicate_transaction_identity_methods']);
        self::assertStringNotContainsString('DUPLICATE-SECRET-TXN', json_encode($result));
        self::assertNotSame($first->id, $second->id);
    }

    public function test_recent_completed_sync_is_healthy(): void
    {
        $this->syncRun('completed', '2026-09-23T23:00:00Z', '2026-09-23T23:05:00Z');

        $result = $this->preview();

        self::assertSame('healthy', $result['sync']['state']);
        self::assertSame('completed', $result['sync']['run_status']);
        self::assertSame('ok', $result['health']);
    }

    public function test_partial_sync_is_warning(): void
    {
        $this->syncRun('partial', '2026-09-23T23:00:00Z', '2026-09-23T23:05:00Z', failedItems: 1);

        $result = $this->preview();

        self::assertSame('partial', $result['sync']['state']);
        self::assertSame('warning', $result['health']);
        self::assertSame(1, $result['sync']['failed_items']);
    }

    public function test_failed_stale_and_missing_sync_are_critical(): void
    {
        $this->syncRun('failed', '2026-09-23T23:00:00Z', '2026-09-23T23:05:00Z');
        self::assertSame('critical', $this->preview()['health']);

        SyncRun::query()->delete();
        $this->syncRun('completed', '2026-09-23T18:00:00Z', '2026-09-23T18:05:00Z');
        $stale = $this->preview();
        self::assertSame('stale', $stale['sync']['state']);
        self::assertSame('critical', $stale['health']);

        SyncRun::query()->delete();
        $missing = $this->preview();
        self::assertSame('missing', $missing['sync']['state']);
        self::assertSame('critical', $missing['health']);
    }

    public function test_output_explicitly_declares_attempt_and_webhook_coverage_gaps(): void
    {
        $this->healthySync();

        $result = $this->preview();

        self::assertSame('unavailable', $result['coverage']['raw_provider_attempts']);
        self::assertSame('unavailable', $result['coverage']['webhook_event_store']);
        self::assertTrue($result['coverage']['payment_orders_are_not_raw_attempts']);
    }

    public function test_json_command_exposes_no_raw_transaction_id_or_customer_pii(): void
    {
        $this->healthySync();
        $order = $this->wooOrder(
            'ppcp',
            'processing',
            paidAt: null,
            transactionId: 'TOP-SECRET-PAYMENT-TXN',
        );
        $order->address()->create([
            'recipient_name' => 'Sensitive Customer',
            'phone' => '+15550008888',
            'email' => 'private@example.test',
            'country' => 'US',
            'province_state' => 'CA',
            'city' => 'Los Angeles',
            'address_line_1' => '99 Private Road',
            'postal_code' => '90001',
        ]);

        $exit = Artisan::call('payments:health-preview', [
            '--json' => true,
            '--as-of' => '2026-09-24T00:00:00Z',
            '--days' => 1,
            '--limit' => 20,
        ]);
        $output = Artisan::output();

        self::assertSame(0, $exit);
        self::assertStringNotContainsString('TOP-SECRET-PAYMENT-TXN', $output);
        self::assertStringNotContainsString('Sensitive Customer', $output);
        self::assertStringNotContainsString('+15550008888', $output);
        self::assertStringNotContainsString('private@example.test', $output);
        self::assertStringContainsString('"payment_orders_are_not_raw_attempts":true', $output);
        self::assertStringContainsString('"has_transaction_id":true', $output);
    }

    public function test_preview_performs_zero_database_writes(): void
    {
        $this->healthySync();
        $this->wooOrder('woocommerce_payments', 'processing', paidAt: '2026-09-23T01:00:00Z');
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
    private function preview(int $days = 1): array
    {
        return app(PaymentHealthPreviewService::class)->preview($this->asOf, $days, 50);
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
        string $paymentMethod,
        string $providerStatus,
        ?string $paidAt = null,
        ?string $transactionId = null,
        ?string $providerModifiedAt = '2026-09-23T12:00:00Z',
        string $orderDate = '2026-09-24',
        string $orderStatus = 'pending_shipment',
    ): Order {
        $providerId = (string) random_int(100000, 999999);
        $order = Order::query()->create([
            'internal_order_number' => 'PAYOBS-'.uniqid(),
            'erp_order_id' => '007-0924-c'.$providerId,
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => $providerId,
            'order_date' => $orderDate,
            'order_status' => $orderStatus,
            'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'beyourlover.com',
            'reference' => $providerId,
            'provider_modified_at' => $providerModifiedAt,
        ]);
        $order->financials()->create([
            'currency' => 'USD',
            'provider' => 'woocommerce',
            'provider_reference' => $providerId,
            'provider_order_status' => $providerStatus,
            'provider_modified_at' => $providerModifiedAt,
            'payment_method' => $paymentMethod,
            'payment_transaction_id' => $transactionId,
            'paid_at' => $paidAt,
            'provider_order_total' => '49.00',
            'revenue_provenance' => 'provider_import',
            'completeness_status' => 'INCOMPLETE',
        ]);

        return $order->fresh(['financials', 'externalReferences']);
    }
}
