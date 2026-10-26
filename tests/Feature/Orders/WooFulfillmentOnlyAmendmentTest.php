<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Application\Inbound\NormalizedInboundOrder;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WooFulfillmentOnlyAmendmentTest extends TestCase
{
    use RefreshDatabase;

    private function products(): array
    {
        return [
            Product::create(['sku' => 'WOO-R9-ORIGINAL', 'name' => 'Original red',
                'source_type' => 'woocommerce','is_active' => true,'product_type' => 'simple']),
            Product::create(['sku' => 'WOO-R9-UPGRADE', 'name' => 'Upgrade blue',
                'source_type' => 'manual','is_active' => true,'product_type' => 'simple']),
            Product::create(['sku' => 'WOO-R9-GIFT', 'name' => 'Agreed gift',
                'source_type' => 'manual','is_active' => true,'product_type' => 'simple']),
        ];
    }

    private function payload(string $price = '89.00', string $note = 'Website sale'): NormalizedInboundOrder
    {
        return new NormalizedInboundOrder(
            provider: 'woocommerce',
            connectionKey: 'shop-r9.test',
            reference: '90112',
            externalOrderNumber: '90112',
            orderDate: '2026-10-26',
            providerModifiedAt: '2026-10-26T07:00:00Z',
            currency: 'USD',
            orderStatus: 'pending_shipment',
            orderNote: $note,
            address: [
                'recipient_name' => 'Customer UAT','phone' => '123456789',
                'country' => 'US','province_state' => 'CA','city' => 'LA',
                'address_line_1' => '100 Test Street','address_line_2' => '',
                'postal_code' => '90001',
            ],
            items: [[
                'woocommerce_line_item_id' => 112,
                'sku' => 'WOO-R9-ORIGINAL',
                'product_summary' => 'Original red',
                'quantity' => 1,
                'unit_price' => $price,
            ]],
        );
    }

    public function test_agreed_woo_sku_and_gift_survive_reimport_while_sale_revenue_is_untouched(): void
    {
        [$original, $upgrade, $gift] = $this->products();
        $orders = app(OrderService::class);
        $first = $orders->upsertInbound($this->payload(), null)['order'];
        $item = $first->items()->firstOrFail();
        $originalId = $item->id;
        $first->financials()->create([
            'currency' => 'USD', 'amount_paid' => '89.00',
            'merchandise_list_amount' => '89.00', 'provider_order_status' => 'processing',
            'paid_at' => now(), 'revenue_provenance' => 'provider_import',
        ]);
        $adjusted = $orders->update($first,
            ['amendment_reason' => 'Customer accepted blue model and a gift'],
            [
                ['id' => $originalId, 'product_id' => $upgrade->id,
                    'quantity' => 2, 'unit_price' => '89.00'],
                ['id' => null, 'product_id' => $gift->id, 'quantity' => 1,
                    'unit_price' => '0.00'],
            ], null);
        $this->assertSame('WOO-R9-UPGRADE', $adjusted->items()->orderBy('id')->first()->sku);
        $this->assertDatabaseHas('order_items', [
            'id' => $originalId, 'sku' => 'WOO-R9-UPGRADE',
            'quantity' => 2, 'woocommerce_line_item_id' => 112,
            'line_revenue' => '89.00',
        ]);
        $this->assertDatabaseHas('order_fulfillment_amendments', [
            'order_id' => $first->id, 'reason' => 'Customer accepted blue model and a gift',
        ]);
        $snapshot = json_decode(DB::table('order_fulfillment_amendments')->where('order_id', $first->id)->value('original_sale_items'), true);
        $this->assertSame('WOO-R9-ORIGINAL', $snapshot[0]['sku']);
        $this->assertSame(1, $snapshot[0]['quantity']);

        $orders->upsertInbound($this->payload('99.00', 'Website source updated'), null);
        $this->assertSame(1, Order::count());
        $this->assertCount(2, $first->fresh()->items);
        $this->assertDatabaseHas('order_items', [
            'id' => $originalId, 'sku' => 'WOO-R9-UPGRADE',
            'quantity' => 2, 'line_revenue' => '89.00',
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $first->id, 'sku' => 'WOO-R9-GIFT',
            'quantity' => 1, 'unit_price' => '0.00',
        ]);
        $this->assertDatabaseHas('order_financials', [
            'order_id' => $first->id, 'amount_paid' => '89.00',
        ]);
        $this->assertSame('Website source updated', $first->fresh()->order_note);
        $this->assertNotNull(DB::table('order_fulfillment_amendments')->where('order_id', $first->id)->value('latest_woo_inbound_items'));
    }

    public function test_woo_transaction_price_cannot_be_altered_during_fulfillment_adjustment(): void
    {
        [$original, $upgrade, $gift] = $this->products();
        $order = app(OrderService::class)->upsertInbound($this->payload(), null)['order'];
        $item = $order->items()->firstOrFail();
        try {
            app(OrderService::class)->update($order,
                ['amendment_reason' => 'Customer agreed price increase'],
                [['id' => $item->id, 'product_id' => $upgrade->id,
                    'quantity' => 1, 'unit_price' => '150.00']], null);
            $this->fail('Woo price must never be changed by local fulfillment-only amendment');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.unit_price', $e->errors());
        }
        $this->assertSame('WOO-R9-ORIGINAL', $order->items()->firstOrFail()->sku);
        $this->assertDatabaseCount('order_fulfillment_amendments', 0);
    }

    public function test_woo_inbound_without_local_amendment_keeps_original_refresh_behavior(): void
    {
        $this->products();
        $service = app(OrderService::class);
        $order = $service->upsertInbound($this->payload(), null)['order'];
        $oldItem = $order->items()->firstOrFail()->id;
        $service->upsertInbound($this->payload('99.00'), null);
        $this->assertSame('99.00', $order->items()->firstOrFail()->unit_price);
        $this->assertNotSame($oldItem, $order->items()->firstOrFail()->id);
        $this->assertDatabaseCount('order_fulfillment_amendments', 0);
    }
}

