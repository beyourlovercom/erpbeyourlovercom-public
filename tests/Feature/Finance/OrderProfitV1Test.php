<?php

namespace Tests\Feature\Finance;

use App\Modules\Orders\Application\Inbound\WooCommerceOrderNormalizer;
use PHPUnit\Framework\TestCase;

final class OrderProfitV1Test extends TestCase
{
    public function test_woocommerce_normalizer_preserves_settlement_components_and_keeps_refunds_separate_from_provider_total(): void
    {
        $normalized = (new WooCommerceOrderNormalizer())->normalize([
            'id' => 40219,
            'number' => '40219',
            'status' => 'processing',
            'date_created_gmt' => '2026-09-01T00:00:00',
            'date_modified_gmt' => '2026-09-02T00:00:00',
            'date_paid_gmt' => '2026-09-01T00:02:00',
            'currency' => 'USD',
            'total' => '108.00',
            'discount_total' => '10.00',
            'shipping_total' => '8.00',
            'total_tax' => '10.00',
            'payment_method' => 'stripe',
            'transaction_id' => 'pi_payment_authority_40219',
            'line_items' => [[
                'subtotal' => '100.00',
                'total' => '90.00',
                'quantity' => 1,
                'name' => 'Item',
            ]],
            'refunds' => [[
                'total' => '-5.00',
                'total_tax' => '-1.00',
            ]],
        ], 'primary');

        self::assertSame('100.00', $normalized->settlementEvidence['merchandise_list_amount']);
        self::assertSame('10.00', $normalized->settlementEvidence['discount_amount']);
        self::assertSame('8.00', $normalized->settlementEvidence['shipping_revenue']);
        self::assertSame('10.00', $normalized->settlementEvidence['tax_collected']);
        self::assertSame('108.00', $normalized->settlementEvidence['provider_order_total']);
        self::assertSame('5.00', $normalized->settlementEvidence['refund_amount']);
        self::assertSame('1.00', $normalized->settlementEvidence['refund_tax']);
        self::assertSame('matched', $normalized->settlementEvidence['reconciliation_status']);
        self::assertNull($normalized->settlementEvidence['reconciliation_exception']);
        self::assertSame('processing', $normalized->settlementEvidence['provider_order_status']);
        self::assertSame('stripe', $normalized->settlementEvidence['payment_method']);
        self::assertSame('pi_payment_authority_40219', $normalized->settlementEvidence['payment_transaction_id']);
    }

    public function test_missing_woocommerce_financial_fields_remain_missing_instead_of_becoming_zero(): void
    {
        $normalized = (new WooCommerceOrderNormalizer())->normalize([
            'id' => 7,
            'number' => '7',
            'status' => 'pending',
            'date_created_gmt' => '2026-09-01T00:00:00',
            'date_modified_gmt' => '2026-09-01T00:00:00',
            'currency' => 'USD',
            'line_items' => [[
                'total' => '12.00',
                'quantity' => 1,
                'name' => 'Item',
            ]],
        ], 'primary');

        self::assertNull($normalized->settlementEvidence['merchandise_list_amount']);
        self::assertNull($normalized->settlementEvidence['discount_amount']);
        self::assertNull($normalized->settlementEvidence['shipping_revenue']);
        self::assertNull($normalized->settlementEvidence['tax_collected']);
        self::assertNull($normalized->settlementEvidence['provider_order_total']);
        self::assertNull($normalized->settlementEvidence['refund_amount']);
        self::assertSame('incomplete', $normalized->settlementEvidence['reconciliation_status']);
    }

    public function test_legacy_woocommerce_refund_dash_placeholder_remains_missing_instead_of_becoming_zero_or_error(): void
    {
        $normalized = (new WooCommerceOrderNormalizer())->normalize([
            'id' => 16193,
            'number' => '16193',
            'status' => 'refunded',
            'date_created_gmt' => '2025-04-13T00:00:00',
            'date_modified_gmt' => '2025-04-14T00:00:00',
            'currency' => 'USD',
            'total' => '100.00',
            'discount_total' => '0.00',
            'shipping_total' => '0.00',
            'total_tax' => '0.00',
            'line_items' => [[
                'subtotal' => '100.00', 'total' => '100.00', 'quantity' => 1, 'name' => 'Item',
            ]],
            'refunds' => [['total' => '-', 'total_tax' => '0.00']],
        ], 'primary');

        self::assertNull($normalized->settlementEvidence['refund_amount']);
        self::assertSame('0.00', $normalized->settlementEvidence['refund_tax']);
        self::assertSame('matched', $normalized->settlementEvidence['reconciliation_status']);
    }

    public function test_woocommerce_settlement_decimal_rounding_is_deterministic_without_float_summing(): void
    {
        $normalized = (new WooCommerceOrderNormalizer())->normalize([
            'id' => 8,
            'number' => '8',
            'status' => 'processing',
            'date_created_gmt' => '2026-09-01T00:00:00',
            'date_modified_gmt' => '2026-09-01T00:00:00',
            'currency' => 'USD',
            'total' => '0.11',
            'discount_total' => '0.00',
            'shipping_total' => '0.00',
            'total_tax' => '0.00',
            'line_items' => [[
                'subtotal' => '0.105',
                'total' => '0.105',
                'quantity' => 1,
                'name' => 'Item',
            ]],
            'refunds' => [],
        ], 'primary');

        self::assertSame('0.11', $normalized->settlementEvidence['merchandise_list_amount']);
        self::assertSame('0.11', $normalized->settlementEvidence['provider_order_total']);
        self::assertSame('matched', $normalized->settlementEvidence['reconciliation_status']);
    }
}
