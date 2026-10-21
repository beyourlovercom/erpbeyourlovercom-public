<?php

namespace Tests\Feature\Logistics;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\ShipmentCompletionSemantics;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReplacementFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_replacement_fulfillment_preserves_original_shipment_truth_and_creates_r1(): void
    {
        $user = $this->userWith(['logistics.operate', 'orders.view']);
        [$root, $warehouse] = $this->shippedRootOrder();
        $sourceItem = $root->items()->firstOrFail();
        $originalShipmentId = $root->shipment()->value('id');
        $originalTracking = $root->shipment()->value('tracking_number');

        $this->actingAs($user)->postJson('/logistics/orders/'.$root->id.'/replacement-fulfillments', [
            'reason' => 'damaged',
            'warehouse_id' => $warehouse->id,
            'items' => [['order_item_id' => $sourceItem->id, 'quantity' => 1]],
            'note' => 'Customer reported physical damage.',
        ])->assertCreated()->assertJsonPath('suffix', 'R1');

        $replacement = Order::query()->where('fulfillment_parent_id', $root->id)->where('fulfillment_suffix', 'R1')->firstOrFail();
        $this->assertSame('replacement', $replacement->fulfillment_role);
        $this->assertSame('damaged', $replacement->fulfillment_reason);
        $this->assertSame('0.00', $replacement->items()->firstOrFail()->line_revenue);
        $this->assertSame('pending', $replacement->shipment()->value('shipment_status'));
        $this->assertSame($warehouse->id, $replacement->shipment()->value('warehouse_id'));

        $root->refresh();
        $this->assertSame($originalShipmentId, $root->shipment()->value('id'));
        $this->assertSame($originalTracking, $root->shipment()->value('tracking_number'));
        $this->assertSame('shipped', $root->shipment()->value('shipment_status'));

        $aggregate = $this->app->make(ShipmentCompletionSemantics::class)->aggregateForOrder($root);
        $this->assertSame(ShipmentCompletionSemantics::Completed, $aggregate, 'pending replacement must not regress the commercial order original fulfillment state');
    }

    public function test_multiple_replacements_increment_r_suffix_without_overwriting_previous_replacement(): void
    {
        $user = $this->userWith(['logistics.operate', 'orders.view']);
        [$root, $warehouse] = $this->shippedRootOrder();
        $sourceItem = $root->items()->firstOrFail();
        $payload = [
            'reason' => 'lost',
            'warehouse_id' => $warehouse->id,
            'items' => [['order_item_id' => $sourceItem->id, 'quantity' => 1]],
        ];

        $this->actingAs($user)->postJson('/logistics/orders/'.$root->id.'/replacement-fulfillments', $payload)->assertCreated()->assertJsonPath('suffix', 'R1');
        $this->actingAs($user)->postJson('/logistics/orders/'.$root->id.'/replacement-fulfillments', $payload)->assertCreated()->assertJsonPath('suffix', 'R2');

        $this->assertSame(['R1', 'R2'], $root->replacementFulfillments()->orderBy('id')->pluck('fulfillment_suffix')->all());
    }

    public function test_replacement_is_blocked_before_original_logistics_has_started(): void
    {
        $user = $this->userWith(['logistics.operate', 'orders.view']);
        [$root, $warehouse] = $this->shippedRootOrder();
        $root->shipment()->update(['shipment_status' => 'pending', 'tracking_number' => null, 'shipped_at' => null]);
        $root->forceFill(['order_status' => 'pending_shipment'])->save();
        $sourceItem = $root->items()->firstOrFail();

        $this->actingAs($user)->postJson('/logistics/orders/'.$root->id.'/replacement-fulfillments', [
            'reason' => 'lost',
            'warehouse_id' => $warehouse->id,
            'items' => [['order_item_id' => $sourceItem->id, 'quantity' => 1]],
        ])->assertStatus(409);

        $this->assertSame(0, $root->replacementFulfillments()->count());
    }

    private function shippedRootOrder(): array
    {
        $product = Product::query()->create(['sku' => 'REPL-SKU', 'name' => 'Replacement SKU', 'product_type' => 'simple']);
        $warehouse = Warehouse::query()->create(['slug' => 'repl-la', 'code' => 'REPL-LA', 'name' => 'Replacement LA', 'country_code' => 'US', 'is_active' => true]);
        $root = Order::query()->create([
            'internal_order_number' => 'REPL-ROOT-'.uniqid(),
            'erp_order_id' => '007-0919-cREPL'.random_int(100, 999),
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'external_order_number' => 'REPL-BIZ-'.uniqid(),
            'order_date' => '2026-09-19',
            'order_status' => 'shipped',
            'currency' => 'USD',
        ]);
        $root->address()->create([
            'recipient_name' => 'Replacement Customer', 'phone' => '+1 555 0100', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Los Angeles', 'address_line_1' => '100 Replacement St', 'postal_code' => '90001',
        ]);
        $root->items()->create([
            'product_id' => $product->id, 'product_summary' => $product->name, 'sku' => $product->sku,
            'quantity' => 2, 'unit_price' => '29.99', 'line_revenue' => '59.98',
        ]);
        $root->shipment()->create([
            'shipment_status' => 'shipped', 'warehouse_id' => $warehouse->id, 'warehouse_selection_source' => 'manual',
            'carrier' => 'USPS', 'service' => 'Ground', 'tracking_number' => 'ORIGINAL-TRACKING', 'shipped_at' => now(),
        ]);

        return [$root->fresh(['items', 'address', 'shipment']), $warehouse];
    }

    private function userWith(array $permissions): User
    {
        $role = Role::query()->create(['key' => 'replacement-'.uniqid(), 'label' => 'Replacement Test']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
