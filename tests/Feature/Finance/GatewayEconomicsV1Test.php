<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\GatewayEconomicsPreviewService;
use App\Modules\Orders\Application\Inbound\WooCommerceOrderNormalizer;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatewayEconomicsV1Test extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $asOf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asOf = CarbonImmutable::parse('2026-09-24T00:00:00Z');
        config([
            'gateway_economics.max_scan' => 50000,
            'gateway_economics.accounting_payment_fee_rate' => '0.03500000',
        ]);
    }

    public function test_known_gateway_fee_meta_keys_import_actual_fee_evidence(): void
    {
        $normalizer = app(WooCommerceOrderNormalizer::class);

        $wcpay = $normalizer->normalize(
            $this->payload('woocommerce_payments', [['_wcpay_transaction_fee', '3.25']]),
            'shop-a',
        )->settlementEvidence;
        self::assertSame('3.25', $wcpay['actual_payment_fee']);
        self::assertSame('_wcpay_transaction_fee', $wcpay['actual_payment_fee_source']);
        self::assertSame('observed', $wcpay['actual_payment_fee_status']);

        $stripe = $normalizer->normalize(
            $this->payload('cpsw_stripe_element', [['_cpsw_stripe_fee', '4.10']]),
            'shop-a',
        )->settlementEvidence;
        self::assertSame('4.10', $stripe['actual_payment_fee']);
        self::assertSame('_cpsw_stripe_fee', $stripe['actual_payment_fee_source']);
        self::assertSame('observed', $stripe['actual_payment_fee_status']);

        foreach (['ppcp', 'ppcp_card'] as $method) {
            $paypal = $normalizer->normalize(
                $this->payload($method, [['_paypal_fee', '2.40']]),
                'shop-a',
            )->settlementEvidence;
            self::assertSame('2.40', $paypal['actual_payment_fee']);
            self::assertSame('_paypal_fee', $paypal['actual_payment_fee_source']);
            self::assertSame('observed', $paypal['actual_payment_fee_status']);
        }
    }

    public function test_actual_fee_evidence_persists_through_idempotent_woo_inbound(): void
    {
        $product = Product::query()->create([
            'woocommerce_product_id' => 456,
            'sku' => 'SKU-TEST',
            'name' => 'Gateway Economics Product',
            'product_type' => 'simple',
            'source_type' => 'woocommerce',
            'is_active' => true,
        ]);
        self::assertSame(456, $product->woocommerce_product_id);

        $payload = $this->payload('woocommerce_payments', [['_wcpay_transaction_fee', '3.25']]);
        $normalized = app(WooCommerceOrderNormalizer::class)->normalize($payload, 'shop-a');

        $first = app(OrderService::class)->upsertInbound($normalized, null);
        $second = app(OrderService::class)->upsertInbound($normalized, null);

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);

        $financials = $first['order']->fresh('financials')->financials;
        self::assertSame('3.25', $financials?->actual_payment_fee);
        self::assertSame('_wcpay_transaction_fee', $financials?->actual_payment_fee_source);
        self::assertSame('observed', $financials?->actual_payment_fee_status);
        self::assertSame(1, Order::query()->where('external_order_number', $payload['number'])->count());
    }

    public function test_airwallex_fee_is_explicitly_unavailable_and_never_inferred(): void
    {
        foreach (['airwallex_card', 'airwallex_klarna'] as $method) {
            $evidence = app(WooCommerceOrderNormalizer::class)
                ->normalize($this->payload($method, []), 'shop-a')
                ->settlementEvidence;

            self::assertNull($evidence['actual_payment_fee']);
            self::assertNull($evidence['actual_payment_fee_source']);
            self::assertSame('unavailable', $evidence['actual_payment_fee_status']);
        }
    }

    public function test_duplicate_identical_fee_meta_remains_observed(): void
    {
        $evidence = app(WooCommerceOrderNormalizer::class)
            ->normalize(
                $this->payload('woocommerce_payments', [
                    ['_wcpay_transaction_fee', '3.25'],
                    ['_wcpay_transaction_fee', '3.2500'],
                ]),
                'shop-a',
            )
            ->settlementEvidence;

        self::assertSame('3.25', $evidence['actual_payment_fee']);
        self::assertSame('observed', $evidence['actual_payment_fee_status']);
    }

    public function test_conflicting_invalid_and_missing_fee_meta_are_not_coerced(): void
    {
        $normalizer = app(WooCommerceOrderNormalizer::class);

        $conflict = $normalizer->normalize(
            $this->payload('woocommerce_payments', [
                ['_wcpay_transaction_fee', '3.25'],
                ['_wcpay_transaction_fee', '3.55'],
            ]),
            'shop-a',
        )->settlementEvidence;
        self::assertNull($conflict['actual_payment_fee']);
        self::assertSame('conflict', $conflict['actual_payment_fee_status']);

        $invalid = $normalizer->normalize(
            $this->payload('cpsw_stripe_element', [['_cpsw_stripe_fee', 'not-a-fee']]),
            'shop-a',
        )->settlementEvidence;
        self::assertNull($invalid['actual_payment_fee']);
        self::assertSame('invalid', $invalid['actual_payment_fee_status']);

        $missing = $normalizer->normalize(
            $this->payload('ppcp', []),
            'shop-a',
        )->settlementEvidence;
        self::assertNull($missing['actual_payment_fee']);
        self::assertSame('_paypal_fee', $missing['actual_payment_fee_source']);
        self::assertSame('missing', $missing['actual_payment_fee_status']);
    }

    public function test_economics_rows_are_gateway_and_currency_separated(): void
    {
        $this->wooOrder('woocommerce_payments', 'USD', '100.00', '10.00', '3.00', 'observed');
        $this->wooOrder('woocommerce_payments', 'USD', '50.00', '0.00', '2.00', 'observed');
        $this->wooOrder('woocommerce_payments', 'USD', '40.00', '0.00', null, 'missing', paidAt: null);
        $this->wooOrder('woocommerce_payments', 'EUR', '80.00', '5.00', '2.50', 'observed');
        $this->wooOrder('airwallex_card', 'USD', '80.00', '0.00', null, 'unavailable');

        $result = $this->preview();
        $rows = collect($result['rows'])->keyBy(fn (array $row): string => $row['payment_method'].'|'.$row['currency']);

        self::assertCount(3, $rows);
        self::assertTrue($rows->has('woocommerce_payments|USD'));
        self::assertTrue($rows->has('woocommerce_payments|EUR'));
        self::assertTrue($rows->has('airwallex_card|USD'));

        $usd = $rows['woocommerce_payments|USD'];
        self::assertSame(3, $usd['payment_orders']);
        self::assertSame(2, $usd['captured_orders']);
        self::assertSame(0.6667, $usd['created_to_captured_rate']);
        self::assertSame('150.00', $usd['captured_gross']);
        self::assertSame('10.00', $usd['refund_amount']);
        self::assertSame('5.00', $usd['actual_fee_total']);
        self::assertSame(1.0, $usd['actual_fee_coverage']);
        self::assertSame(0.0333, $usd['actual_fee_effective_rate']);
        self::assertSame('5.25', $usd['accounting_fee_estimate_3_5pct']);
        self::assertSame('140.00', $usd['net_after_refund_before_fee']);
        self::assertSame('135.00', $usd['net_after_refund_and_actual_fee']);
        self::assertTrue($usd['complete_actual_net_evidence']);

        $eur = $rows['woocommerce_payments|EUR'];
        self::assertSame('80.00', $eur['captured_gross']);
        self::assertSame('2.50', $eur['actual_fee_total']);

        $airwallex = $rows['airwallex_card|USD'];
        self::assertSame(0.0, $airwallex['actual_fee_coverage']);
        self::assertSame('0.00', $airwallex['actual_fee_total']);
        self::assertSame('2.80', $airwallex['accounting_fee_estimate_3_5pct']);
        self::assertNull($airwallex['net_after_refund_and_actual_fee']);
        self::assertFalse($airwallex['complete_actual_net_evidence']);
        self::assertSame(['unavailable' => 1], $airwallex['fee_evidence_status_counts']);
    }

    public function test_captured_uses_paid_at_even_after_provider_moves_to_hold_or_refunded(): void
    {
        $this->wooOrder('ppcp', 'USD', '60.00', '0.00', '2.40', 'observed', providerStatus: 'on-hold');
        $this->wooOrder('ppcp', 'USD', '40.00', '40.00', '1.60', 'observed', providerStatus: 'refunded');

        $row = collect($this->preview()['rows'])->firstWhere('payment_method', 'ppcp');

        self::assertSame(2, $row['captured_orders']);
        self::assertSame('100.00', $row['captured_gross']);
        self::assertSame('40.00', $row['refund_amount']);
        self::assertSame('4.00', $row['actual_fee_total']);
        self::assertSame('56.00', $row['net_after_refund_and_actual_fee']);
    }

    public function test_incomplete_actual_fee_or_refund_coverage_blocks_full_fee_adjusted_net(): void
    {
        $this->wooOrder('cpsw_stripe_element', 'USD', '100.00', null, '3.30', 'observed');
        $this->wooOrder('cpsw_stripe_element', 'USD', '50.00', '0.00', null, 'not_imported_yet');

        $row = collect($this->preview()['rows'])->firstWhere('payment_method', 'cpsw_stripe_element');

        self::assertSame(2, $row['captured_orders']);
        self::assertSame(1, $row['actual_fee_observed_orders']);
        self::assertSame(0.5, $row['actual_fee_coverage']);
        self::assertSame(0.5, $row['refund_evidence_coverage']);
        self::assertNull($row['net_after_refund_and_actual_fee']);
        self::assertFalse($row['complete_actual_net_evidence']);
    }

    public function test_empty_cohort_is_safe_and_has_no_cross_currency_total(): void
    {
        $result = $this->preview();

        self::assertSame([], $result['rows']);
        self::assertSame('prohibited', $result['coverage']['cross_currency_amount_total']);
        self::assertFalse($result['coverage']['accounting_3_5pct_is_actual_gateway_fee']);
        self::assertSame('unavailable', $result['coverage']['dispute_chargeback_cost']);
        self::assertSame('unavailable', $result['coverage']['airwallex_actual_processor_fee']);
        self::assertTrue($result['coverage']['payment_orders_are_not_raw_attempts']);
    }

    public function test_json_output_leaks_no_transaction_meta_or_customer_pii(): void
    {
        $order = $this->wooOrder(
            'woocommerce_payments',
            'USD',
            '100.00',
            '0.00',
            '3.25',
            'observed',
            transactionId: 'TOP-SECRET-TXN',
        );
        $order->address()->create([
            'recipient_name' => 'Sensitive Customer',
            'phone' => '+15550007777',
            'email' => 'private-economics@example.test',
            'country' => 'US',
            'province_state' => 'CA',
            'city' => 'Los Angeles',
            'address_line_1' => '88 Private Ave',
            'postal_code' => '90001',
        ]);

        $exit = Artisan::call('payments:economics-preview', [
            '--json' => true,
            '--as-of' => '2026-09-24T00:00:00Z',
            '--days' => 1,
        ]);
        $output = Artisan::output();

        self::assertSame(0, $exit);
        self::assertStringNotContainsString('TOP-SECRET-TXN', $output);
        self::assertStringNotContainsString('_wcpay_transaction_fee', $output);
        self::assertStringNotContainsString('Sensitive Customer', $output);
        self::assertStringNotContainsString('+15550007777', $output);
        self::assertStringNotContainsString('private-economics@example.test', $output);
        self::assertStringContainsString('"cross_currency_amount_total":"prohibited"', $output);
    }

    public function test_preview_performs_zero_database_writes(): void
    {
        $this->wooOrder('woocommerce_payments', 'USD', '100.00', '0.00', '3.25', 'observed');
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
        return app(GatewayEconomicsPreviewService::class)->preview($this->asOf, $days);
    }

    private function wooOrder(
        string $method,
        string $currency,
        string $gross,
        ?string $refund,
        ?string $actualFee,
        ?string $feeStatus,
        ?string $paidAt = '2026-09-24T01:00:00Z',
        string $providerStatus = 'processing',
        ?string $transactionId = null,
    ): Order {
        $providerId = (string) random_int(100000, 999999);
        $order = Order::query()->create([
            'internal_order_number' => 'ECON-'.uniqid(),
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
            'connection_key' => 'shop-a',
            'reference' => $providerId,
            'provider_modified_at' => '2026-09-24T01:00:00Z',
        ]);
        $order->financials()->create([
            'currency' => $currency,
            'provider' => 'woocommerce',
            'provider_reference' => $providerId,
            'provider_order_status' => $providerStatus,
            'provider_modified_at' => '2026-09-24T01:00:00Z',
            'payment_method' => $method,
            'payment_transaction_id' => $transactionId,
            'paid_at' => $paidAt,
            'provider_order_total' => $gross,
            'refund_amount' => $refund,
            'actual_payment_fee' => $actualFee,
            'actual_payment_fee_source' => $feeStatus === 'observed' ? $this->feeSource($method) : null,
            'actual_payment_fee_status' => $feeStatus,
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

    /** @param list<array{0:string,1:mixed}> $meta */
    private function payload(string $method, array $meta): array
    {
        return [
            'id' => random_int(100000, 999999),
            'number' => (string) random_int(100000, 999999),
            'status' => 'processing',
            'created_via' => 'checkout',
            'currency' => 'USD',
            'date_created_gmt' => '2026-09-24T00:30:00',
            'date_modified_gmt' => '2026-09-24T00:31:00',
            'date_paid_gmt' => '2026-09-24T00:31:00',
            'payment_method' => $method,
            'transaction_id' => 'txn-test',
            'total' => '100.00',
            'discount_total' => '0.00',
            'shipping_total' => '0.00',
            'total_tax' => '0.00',
            'shipping' => [
                'first_name' => 'Test',
                'last_name' => 'Customer',
                'address_1' => '1 Test St',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'postcode' => '90001',
                'country' => 'US',
            ],
            'billing' => [],
            'line_items' => [[
                'id' => 123,
                'name' => 'Product',
                'product_id' => 456,
                'variation_id' => 0,
                'sku' => 'SKU-TEST',
                'quantity' => 1,
                'price' => '100.00',
                'subtotal' => '100.00',
                'total' => '100.00',
            ]],
            'refunds' => [],
            'meta_data' => array_map(
                fn (array $row, int $index): array => [
                    'id' => $index + 1,
                    'key' => $row[0],
                    'value' => $row[1],
                ],
                $meta,
                array_keys($meta),
            ),
        ];
    }
}
