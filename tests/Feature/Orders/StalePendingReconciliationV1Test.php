<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Application\StalePaymentClassification;
use App\Modules\Orders\Application\StalePaymentReconciliationPreviewService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StalePendingReconciliationV1Test extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $asOf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asOf = CarbonImmutable::parse('2026-09-24T00:00:00Z');
        config([
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

    public function test_recent_default_pending_is_not_stale_before_24_hours(): void
    {
        $order = $this->wooOrder('ppcp', 'pending', '2026-09-23T12:00:00Z');

        $row = $this->classify($order);

        self::assertSame(StalePaymentClassification::RecentPending->value, $row['classification']);
        self::assertSame(12.0, $row['age_hours']);
        self::assertSame(24, $row['threshold_hours']);
    }

    public function test_default_pending_without_evidence_becomes_stale_at_24_hours(): void
    {
        $order = $this->wooOrder('cpsw_stripe_element', 'pending', '2026-09-23T00:00:00Z');

        $row = $this->classify($order);

        self::assertSame(StalePaymentClassification::StaleUnpaid->value, $row['classification']);
        self::assertSame(24.0, $row['age_hours']);
    }

    public function test_klarna_48_hours_is_recent_but_72_hours_is_stale(): void
    {
        $recent = $this->wooOrder('airwallex_klarna', 'pending', '2026-09-22T00:00:00Z');
        $stale = $this->wooOrder('airwallex_klarna', 'pending', '2026-09-21T00:00:00Z');

        self::assertSame(StalePaymentClassification::RecentPending->value, $this->classify($recent)['classification']);
        self::assertSame(StalePaymentClassification::StaleUnpaid->value, $this->classify($stale)['classification']);
        self::assertSame(72, $this->classify($stale)['threshold_hours']);
    }

    public function test_pending_with_paid_at_is_evidence_conflict(): void
    {
        $order = $this->wooOrder(
            'ppcp',
            'pending',
            '2026-09-20T00:00:00Z',
            paidAt: '2026-09-20T00:01:00Z',
        );

        self::assertSame(
            StalePaymentClassification::PendingEvidenceConflict->value,
            $this->classify($order)['classification'],
        );
    }

    public function test_pending_with_transaction_identity_is_evidence_conflict(): void
    {
        $order = $this->wooOrder(
            'airwallex_card',
            'pending',
            '2026-09-20T00:00:00Z',
            transactionId: 'SECRET-TXN-123',
        );

        $row = $this->classify($order);
        self::assertSame(StalePaymentClassification::PendingEvidenceConflict->value, $row['classification']);
        self::assertTrue($row['has_transaction_id']);
    }

    public function test_on_hold_is_always_review_with_or_without_paid_evidence(): void
    {
        $paid = $this->wooOrder(
            'ppcp',
            'on-hold',
            '2026-08-01T00:00:00Z',
            paidAt: '2026-07-31T23:00:00Z',
            transactionId: 'DISPUTED-PAID-TXN',
        );
        $unpaid = $this->wooOrder('ppcp', 'on-hold', '2026-08-01T00:00:00Z');

        self::assertSame(StalePaymentClassification::HoldReview->value, $this->classify($paid)['classification']);
        self::assertSame(StalePaymentClassification::HoldReview->value, $this->classify($unpaid)['classification']);
        self::assertTrue($this->classify($paid)['has_paid_at']);
    }

    public function test_missing_provider_timestamp_is_review_only(): void
    {
        $order = $this->wooOrder('ppcp', 'pending', null);

        $row = $this->classify($order);

        self::assertSame(StalePaymentClassification::MissingTimestampReview->value, $row['classification']);
        self::assertNull($row['age_hours']);
    }

    public function test_non_woo_order_is_not_candidate(): void
    {
        $order = Order::query()->create([
            'internal_order_number' => 'MAN-'.uniqid(),
            'erp_order_id' => 'MAN-'.uniqid(),
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'external_order_number' => 'EXT-'.uniqid(),
            'order_date' => '2026-09-23',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $order->financials()->create([
            'currency' => 'USD',
            'provider' => 'manual',
            'provider_reference' => 'manual',
            'provider_order_status' => 'pending',
            'provider_modified_at' => '2026-09-20 00:00:00',
            'payment_method' => 'manual',
            'revenue_provenance' => 'manual',
            'completeness_status' => 'INCOMPLETE',
        ]);

        self::assertSame(
            StalePaymentClassification::NotCandidate->value,
            $this->classify($order)['classification'],
        );

        $preview = app(StalePaymentReconciliationPreviewService::class)->preview($this->asOf);
        self::assertSame(0, $preview['candidate_count']);
    }

    public function test_preview_aggregates_by_classification_and_payment_method_deterministically(): void
    {
        $this->wooOrder('ppcp', 'pending', '2026-09-23T12:00:00Z');
        $this->wooOrder('ppcp', 'pending', '2026-09-20T00:00:00Z');
        $this->wooOrder('airwallex_klarna', 'pending', '2026-09-22T00:00:00Z');
        $this->wooOrder('ppcp', 'on-hold', '2026-09-01T00:00:00Z', paidAt: '2026-08-31T23:00:00Z');

        $preview = app(StalePaymentReconciliationPreviewService::class)->preview($this->asOf, 20);

        self::assertSame('2026-09-24T00:00:00+00:00', $preview['as_of']);
        self::assertSame(4, $preview['candidate_count']);
        self::assertSame(1, $preview['classification_counts']['stale_unpaid']);
        self::assertSame(1, $preview['classification_counts']['hold_review']);
        self::assertSame(2, $preview['classification_counts']['recent_pending']);
        self::assertSame(3, $preview['payment_method_counts']['ppcp']['total']);
        self::assertSame(1, $preview['payment_method_counts']['airwallex_klarna']['total']);
    }

    public function test_json_command_exposes_no_raw_transaction_id_or_customer_pii(): void
    {
        $order = $this->wooOrder(
            'ppcp',
            'pending',
            '2026-09-20T00:00:00Z',
            transactionId: 'TOP-SECRET-TRANSACTION-ID',
        );
        $order->address()->create([
            'recipient_name' => 'Sensitive Person',
            'phone' => '+15550009999',
            'email' => 'secret.customer@example.test',
            'country' => 'US',
            'province_state' => 'CA',
            'city' => 'Los Angeles',
            'address_line_1' => '123 Private Street',
            'postal_code' => '90001',
        ]);

        $exit = Artisan::call('orders:preview-stale-payments', [
            '--json' => true,
            '--as-of' => '2026-09-24T00:00:00Z',
            '--limit' => 10,
        ]);
        $output = Artisan::output();

        self::assertSame(0, $exit);
        self::assertStringNotContainsString('TOP-SECRET-TRANSACTION-ID', $output);
        self::assertStringNotContainsString('secret.customer@example.test', $output);
        self::assertStringNotContainsString('+15550009999', $output);
        self::assertStringNotContainsString('Sensitive Person', $output);
        self::assertStringContainsString('"has_transaction_id":true', $output);
    }

    public function test_preview_performs_no_database_writes(): void
    {
        $this->wooOrder('ppcp', 'pending', '2026-09-20T00:00:00Z');
        $writes = [];

        DB::listen(function ($query) use (&$writes): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (preg_match('/^(insert|update|delete|replace|alter|create|drop|truncate)\b/', $sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        app(StalePaymentReconciliationPreviewService::class)->preview($this->asOf, 10);

        self::assertSame([], $writes);
    }

    /** @return array<string,mixed> */
    private function classify(Order $order): array
    {
        return app(StalePaymentReconciliationPreviewService::class)->classify($order->fresh(), $this->asOf);
    }

    private function wooOrder(
        string $paymentMethod,
        string $status,
        ?string $providerModifiedAt,
        ?string $paidAt = null,
        ?string $transactionId = null,
    ): Order {
        $providerId = (string) random_int(100000, 999999);
        $order = Order::query()->create([
            'internal_order_number' => 'WOO-'.uniqid(),
            'erp_order_id' => '007-0924-c'.$providerId,
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => $providerId,
            'order_date' => '2026-09-20',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'shop-a',
            'reference' => $providerId,
            'provider_modified_at' => $providerModifiedAt,
        ]);
        $order->financials()->create([
            'currency' => 'USD',
            'provider' => 'woocommerce',
            'provider_reference' => $providerId,
            'provider_order_status' => $status,
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
