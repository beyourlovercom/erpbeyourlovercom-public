<?php

namespace Tests\Feature;

use App\Modules\Orders\Application\OrderIdentityService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WooOrderIdentityRepairCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_command_previews_then_applies_exact_date_bound_identity_correction(): void
    {
        app(OrderIdentityService::class)->updatePrefix('007', null);
        $order = Order::create([
            'internal_order_number' => 'WEB-TEST-40414',
            'erp_order_id' => '007-0910-40414',
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => '40414',
            'order_date' => '2026-09-10',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'shop-a',
            'reference' => '40414',
        ]);

        $this->artisan('orders:repair-woo-identities --date=2026-09-10 --json')
            ->assertSuccessful();
        $this->assertSame('007-0910-40414', $order->fresh()->erp_order_id);

        $this->artisan('orders:repair-woo-identities --date=2026-09-10 --apply --expect-change=1 --json')
            ->assertSuccessful();

        $this->assertSame('007-0910-c40414', $order->fresh()->erp_order_id);
        $this->assertDatabaseHas('audit_records', [
            'action' => 'order.erp_identity_repaired',
            'entity_id' => (string) $order->id,
            'reason' => 'owner_rule_007_mmdd_c_woo_number',
        ]);
    }

    public function test_apply_requires_exact_expected_change_count(): void
    {
        app(OrderIdentityService::class)->updatePrefix('007', null);
        $order = Order::create([
            'internal_order_number' => 'WEB-TEST-40415',
            'erp_order_id' => '007-0910-c6108',
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => '40415',
            'order_date' => '2026-09-10',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'shop-a',
            'reference' => '40415',
        ]);

        $this->artisan('orders:repair-woo-identities --date=2026-09-10 --apply --expect-change=2 --json')
            ->assertFailed();
        $this->assertSame('007-0910-c6108', $order->fresh()->erp_order_id);
    }

    public function test_range_scope_is_inclusive_and_does_not_touch_orders_outside_the_range(): void
    {
        app(OrderIdentityService::class)->updatePrefix('007', null);

        $inRangeA = $this->wooOrder('40401', '2026-09-08', '007-0908-c6001');
        $inRangeB = $this->wooOrder('40402', '2026-09-09', '007-0909-c6002');
        $outside = $this->wooOrder('40403', '2026-09-10', '007-0910-c6003');

        $this->artisan('orders:repair-woo-identities --from-date=2026-09-08 --to-date=2026-09-09 --apply --expect-change=2 --json')
            ->assertSuccessful();

        $this->assertSame('007-0908-c40401', $inRangeA->fresh()->erp_order_id);
        $this->assertSame('007-0909-c40402', $inRangeB->fresh()->erp_order_id);
        $this->assertSame('007-0910-c6003', $outside->fresh()->erp_order_id);
    }

    public function test_range_scope_rejects_mixed_partial_invalid_and_reversed_dates(): void
    {
        $this->artisan('orders:repair-woo-identities --date=2026-09-10 --from-date=2026-09-01 --to-date=2026-09-10 --json')
            ->assertFailed();
        $this->artisan('orders:repair-woo-identities --from-date=2026-09-01 --json')
            ->assertFailed();
        $this->artisan('orders:repair-woo-identities --from-date=2026-09-10 --to-date=2026-09-01 --json')
            ->assertFailed();
        $this->artisan('orders:repair-woo-identities --date=2026-02-30 --json')
            ->assertFailed();
    }

    public function test_collision_blocks_the_entire_apply_without_mutating_candidates(): void
    {
        app(OrderIdentityService::class)->updatePrefix('007', null);
        $candidate = $this->wooOrder('40404', '2026-09-08', '007-0908-c6004');

        Order::create([
            'internal_order_number' => 'MANUAL-COLLISION-40404',
            'erp_order_id' => '007-0908-c40404',
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'external_order_number' => 'MANUAL-40404',
            'order_date' => '2026-09-08',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);

        $this->artisan('orders:repair-woo-identities --from-date=2026-09-08 --to-date=2026-09-08 --apply --expect-change=0 --json')
            ->assertFailed();

        $this->assertSame('007-0908-c6004', $candidate->fresh()->erp_order_id);
    }

    private function wooOrder(string $number, string $date, string $erpOrderId): Order
    {
        $order = Order::create([
            'internal_order_number' => 'WEB-RANGE-'.$number,
            'erp_order_id' => $erpOrderId,
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => $number,
            'order_date' => $date,
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'shop-range',
            'reference' => $number,
        ]);

        return $order;
    }
}
