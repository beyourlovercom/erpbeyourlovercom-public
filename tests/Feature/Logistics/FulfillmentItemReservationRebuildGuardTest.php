<?php

namespace Tests\Feature\Logistics;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\FulfillmentDerivedOrderService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class FulfillmentItemReservationRebuildGuardTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $reference, ?Order $parent = null): Order
    {
        return Order::create([
            'erp_order_id' => $reference,
            'internal_order_number' => 'TEST-'.$reference,
            'order_kind' => $parent ? 'fulfillment_derived' : 'customer',
            'source_type' => 'manual',
            'order_status' => 'pending_shipment',
            'order_date' => '2026-10-26',
            'currency' => 'USD',
            'fulfillment_parent_id' => $parent?->id,
            'fulfillment_role' => $parent ? 'original' : null,
            'fulfillment_suffix' => $parent ? 'A' : null,
        ]);
    }

    private function reservation(Order $order, string $status): InventoryReservation
    {
        $warehouse = Warehouse::create([
            'slug' => 'reserve-'.$order->id, 'code' => 'RSV-'.$order->id,
            'name' => 'Test reservation warehouse', 'is_active' => true,
        ]);
        return InventoryReservation::create([
            'reservation_key' => 'reservation-'.$order->id,
            'payload_hash' => str_repeat('a', 64),
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => $status,
        ]);
    }

    public function test_parent_active_reservation_prevents_split_materialization_before_any_mutation(): void
    {
        $parent = $this->order('007-1014-c100001');
        $reservation = $this->reservation($parent, 'reserved');
        $actor = User::factory()->create(['is_active' => true]);

        try {
            app(FulfillmentDerivedOrderService::class)->materialize(
                $parent, ['segments' => [['items' => []], ['items' => []]]], $actor
            );
            $this->fail('A reserved parent must not be split into new items');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseHas('inventory_reservations', ['id' => $reservation->id, 'status' => 'reserved']);
        $this->assertDatabaseMissing('orders', ['fulfillment_parent_id' => $parent->id]);
    }

    public function test_child_committed_reservation_blocks_pending_child_deletion(): void
    {
        $parent = $this->order('007-1014-c100002');
        $child = $this->order('007-1014-c100002A', $parent);
        $reservation = $this->reservation($child, 'committed');
        $actor = User::factory()->create(['is_active' => true]);

        try {
            app(FulfillmentDerivedOrderService::class)->materialize(
                $parent, ['segments' => [['items' => []]]], $actor
            );
            $this->fail('A committed child may not be removed by replanning');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseHas('orders', ['id' => $child->id, 'fulfillment_parent_id' => $parent->id]);
        $this->assertDatabaseHas('inventory_reservations', ['id' => $reservation->id, 'status' => 'committed']);
    }
}
