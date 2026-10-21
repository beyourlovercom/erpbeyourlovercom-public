<?php

namespace Tests\Feature\Logistics;

use App\Models\User;
use App\Modules\Logistics\Application\AutomaticAllocationService;
use App\Modules\Logistics\Application\FulfillmentAllocationSubmissionService;
use App\Modules\Logistics\Application\FulfillmentPaymentGate;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Orders\Application\Inbound\WooCommerceOrderNormalizer;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Application\PaymentAuthorityService;
use App\Modules\Orders\Application\PaymentAuthorityState;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class PaymentAuthorityGateV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_woo_pending_without_paid_evidence_blocks_automatic_allocation_before_inventory_work(): void
    {
        $order = $this->wooOrder();
        $this->financials($order, 'pending', null);

        $result = app(AutomaticAllocationService::class)->preview($order);

        $this->assertSame('PAYMENT_NOT_AUTHORIZED', $result['state']);
        $this->assertSame('payment_pending', $result['reason_code']);
        $this->assertFalse($result['can_apply']);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_paid_on_hold_order_is_blocked_even_when_paid_at_exists(): void
    {
        $order = $this->wooOrder();
        $this->financials($order, 'on-hold', now(), 'HOLD-'.uniqid());

        $this->assertSame(PaymentAuthorityState::Hold, app(PaymentAuthorityService::class)->state($order->fresh()));
        $this->assertFalse(app(FulfillmentPaymentGate::class)->evaluate($order->fresh())['allowed']);
    }

    public function test_failed_cancelled_and_refunded_states_are_blocked(): void
    {
        foreach (['failed', 'cancelled', 'refunded'] as $status) {
            $order = $this->wooOrder();
            $this->financials($order, $status, now(), strtoupper($status).'-'.uniqid());

            $decision = app(FulfillmentPaymentGate::class)->evaluate($order->fresh());
            $this->assertFalse($decision['allowed'], $status);
            $this->assertSame('payment_'.$status, $decision['reason_code'], $status);
        }
    }

    public function test_processing_and_completed_with_paid_at_are_authorized(): void
    {
        foreach (['processing', 'completed'] as $status) {
            $order = $this->wooOrder();
            $this->financials($order, $status, now(), strtoupper($status).'-'.uniqid());

            $this->assertSame(PaymentAuthorityState::Paid, app(PaymentAuthorityService::class)->state($order->fresh()));
            $this->assertTrue(app(FulfillmentPaymentGate::class)->evaluate($order->fresh())['allowed']);
        }
    }

    public function test_batch_payment_snapshot_preserves_paid_pending_and_manual_authority_states(): void
    {
        $paid = $this->wooOrder();
        $this->financials($paid, 'processing', now(), 'BATCH-PAID-'.uniqid());
        $pending = $this->wooOrder();
        $this->financials($pending, 'pending', null);
        $manual = $this->manualOrder();

        $decisions = app(FulfillmentPaymentGate::class)->prepare([$paid, $pending, $manual]);

        $this->assertTrue($decisions[$paid->id]['allowed']);
        $this->assertSame('paid', $decisions[$paid->id]['state']);
        $this->assertFalse($decisions[$pending->id]['allowed']);
        $this->assertSame('payment_pending', $decisions[$pending->id]['reason_code']);
        $this->assertTrue($decisions[$manual->id]['allowed']);
        $this->assertSame('not_required', $decisions[$manual->id]['state']);
    }

    public function test_woo_order_missing_financial_evidence_is_unconfirmed_and_blocked(): void
    {
        $order = $this->wooOrder();

        $this->assertSame(PaymentAuthorityState::Unconfirmed, app(PaymentAuthorityService::class)->state($order));
        $this->assertSame('payment_unconfirmed', app(FulfillmentPaymentGate::class)->evaluate($order)['reason_code']);
    }

    public function test_non_woo_manual_customer_order_does_not_require_payment_gate(): void
    {
        $order = $this->manualOrder();

        $this->assertSame(PaymentAuthorityState::NotRequired, app(PaymentAuthorityService::class)->state($order));
        $this->assertTrue(app(FulfillmentPaymentGate::class)->evaluate($order)['allowed']);
    }

    public function test_manual_fulfillment_submission_is_blocked_before_materialization_for_unpaid_woo_order(): void
    {
        $order = $this->wooOrder();
        $this->financials($order, 'pending', null);
        $user = User::factory()->create(['is_active' => true]);

        try {
            app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [], null, $user);
            self::fail('Expected unpaid Woo order allocation to be blocked.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
            self::assertSame('订单尚未确认付款，已阻止履约。', $exception->getMessage());
        }

        $this->assertDatabaseCount('fulfillment_allocations', 0);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_shared_shipment_service_cannot_bypass_payment_gate_for_draft_or_ship(): void
    {
        $order = $this->wooOrder();
        $this->financials($order, 'pending', null);
        $shipments = app(ShipmentService::class);

        foreach (['draft', 'ship'] as $operation) {
            try {
                if ($operation === 'draft') {
                    $shipments->saveDraft($order->fresh(), [], null);
                } else {
                    $shipments->markShipped($order->fresh(), ['shipped_at' => now()->toIso8601String()], null);
                }
                self::fail("Expected {$operation} to be blocked.");
            } catch (HttpException $exception) {
                self::assertSame(409, $exception->getStatusCode());
                self::assertSame('订单尚未确认付款，已阻止履约。', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_woocommerce_inbound_persists_transaction_identity_and_reimport_is_idempotent(): void
    {
        $product = Product::query()->create([
            'woocommerce_product_id' => 991991,
            'sku' => 'PAY-GATE-991991',
            'name' => 'Payment Gate Product',
            'product_type' => 'simple',
            'source_type' => 'woocommerce',
            'is_active' => true,
        ]);
        $payload = $this->wooPayload($product->woocommerce_product_id, $product->sku, 'TXN-'.uniqid());

        $normalizer = app(WooCommerceOrderNormalizer::class);
        $orders = app(OrderService::class);
        $normalized = $normalizer->normalize($payload, 'shop-a');

        $first = $orders->upsertInbound($normalized, null);
        $second = $orders->upsertInbound($normalized, null);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame(1, Order::query()->where('external_order_number', $payload['number'])->count());
        $financials = $first['order']->fresh('financials')->financials;
        $this->assertSame($payload['transaction_id'], $financials?->payment_transaction_id);
        $this->assertSame('woocommerce_payments', $financials?->payment_method);
        $this->assertNotNull($financials?->paid_at);
    }

    public function test_duplicate_non_null_payment_method_and_transaction_identity_is_rejected(): void
    {
        $transactionId = 'DUP-'.uniqid();
        $first = $this->wooOrder();
        $second = $this->wooOrder();
        $this->financials($first, 'processing', now(), $transactionId);

        $this->expectException(QueryException::class);
        $this->financials($second, 'processing', now(), $transactionId);
    }

    private function wooOrder(): Order
    {
        $order = Order::query()->create([
            'internal_order_number' => 'PAY-'.uniqid(),
            'erp_order_id' => '007-0923-c'.random_int(100000, 999999),
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => (string) random_int(40000, 99999),
            'order_date' => '2026-09-23',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'shop-a',
            'reference' => (string) random_int(40000, 99999),
            'provider_modified_at' => now(),
        ]);

        return $order->fresh(['externalReferences', 'financials']);
    }

    private function manualOrder(): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'MAN-'.uniqid(),
            'erp_order_id' => 'MAN-'.uniqid(),
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'external_order_number' => 'EXT-'.uniqid(),
            'order_date' => '2026-09-23',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
    }

    private function financials(Order $order, string $status, $paidAt, ?string $transactionId = null): void
    {
        $order->financials()->create([
            'currency' => 'USD',
            'provider' => 'woocommerce',
            'provider_reference' => (string) $order->external_order_number,
            'provider_order_status' => $status,
            'provider_modified_at' => now(),
            'payment_method' => 'woocommerce_payments',
            'payment_transaction_id' => $transactionId,
            'paid_at' => $paidAt,
            'provider_order_total' => '49.00',
            'revenue_provenance' => 'provider_import',
            'completeness_status' => 'INCOMPLETE',
        ]);
    }

    /** @return array<string,mixed> */
    private function wooPayload(int $productId, string $sku, string $transactionId): array
    {
        return [
            'id' => 912345,
            'number' => '412345',
            'status' => 'processing',
            'created_via' => 'checkout',
            'currency' => 'USD',
            'date_created_gmt' => '2026-09-23T01:00:00',
            'date_modified_gmt' => '2026-09-23T01:02:00',
            'date_paid_gmt' => '2026-09-23T01:01:00',
            'payment_method' => 'woocommerce_payments',
            'transaction_id' => $transactionId,
            'total' => '49.00',
            'discount_total' => '0.00',
            'shipping_total' => '0.00',
            'total_tax' => '0.00',
            'shipping' => [
                'first_name' => 'Payment',
                'last_name' => 'Gate',
                'address_1' => '1 Test St',
                'address_2' => '',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'postcode' => '90001',
                'country' => 'US',
            ],
            'billing' => ['phone' => '+10000000000'],
            'line_items' => [[
                'id' => 912346,
                'name' => 'Payment Gate Product',
                'product_id' => $productId,
                'variation_id' => 0,
                'sku' => $sku,
                'quantity' => 1,
                'price' => '49.00',
                'subtotal' => '49.00',
                'total' => '49.00',
            ]],
            'refunds' => [],
        ];
    }
}
