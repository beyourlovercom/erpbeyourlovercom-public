<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolCollaboration;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolFulfillmentBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_formal_collaboration_creates_one_canonical_kol_fulfillment_order(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->grant($user, ['kol.view', 'kol.operate', 'orders.create', 'orders.view']);
        $kol = Kol::create([
            'display_name' => 'Fulfillment Creator', 'status' => 'active', 'relationship_stage' => 'cooperating', 'owner_id' => $user->id,
            'contact_phone' => '+1 555 0100', 'shipping_recipient_name' => 'Creator Name', 'shipping_country' => 'US',
            'shipping_province_state' => 'CA', 'shipping_city' => 'Los Angeles', 'shipping_postal_code' => '90001',
            'shipping_address_line_1' => '123 Creator St',
        ]);
        $product = Product::create(['source_type' => 'manual', 'sku' => 'KOL-SAMPLE-1', 'name' => 'Sample One', 'is_active' => true]);
        $collaboration = KolCollaboration::create(['kol_id' => $kol->id, 'owner_id' => $user->id, 'status' => 'confirmed', 'currency' => 'USD', 'fulfillment_required' => true]);
        $collaboration->products()->create(['product_id' => $product->id, 'sku_snapshot' => $product->sku, 'product_name_snapshot' => $product->name, 'quantity' => 2]);

        $response = $this->actingAs($user)->postJson("/kol/library/{$kol->id}/collaborations/{$collaboration->id}/fulfillment-order");
        $response->assertCreated()->assertJsonPath('created', true)->assertJsonPath('order.order_status', 'pending_shipment');

        $order = Order::query()->firstOrFail();
        $this->assertSame('kol_fulfillment', $order->order_kind->value);
        $this->assertSame('kol', $order->source_type->value);
        $this->assertSame('007erp-'.$order->order_date->format('md').'-k'.$kol->id, $order->erp_order_id);
        $this->assertSame($order->id, $collaboration->fresh()->order_id);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2]);
        $this->assertDatabaseHas('order_addresses', ['order_id' => $order->id, 'recipient_name' => 'Creator Name', 'country' => 'US', 'city' => 'Los Angeles']);
    }

    public function test_bridge_is_idempotent_and_never_creates_second_order(): void
    {
        [$user, $kol, $collaboration] = $this->fixture(true, true);
        $first = $this->actingAs($user)->postJson("/kol/library/{$kol->id}/collaborations/{$collaboration->id}/fulfillment-order");
        $second = $this->actingAs($user)->postJson("/kol/library/{$kol->id}/collaborations/{$collaboration->id}/fulfillment-order");
        $first->assertCreated();
        $second->assertOk()->assertJsonPath('created', false)->assertJsonPath('order.id', $first->json('order.id'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_bridge_requires_fulfillment_flag_and_canonical_product(): void
    {
        [$user, $kol, $notRequired] = $this->fixture(false, true);
        $this->actingAs($user)->postJson("/kol/library/{$kol->id}/collaborations/{$notRequired->id}/fulfillment-order")->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);

        $needsProduct = KolCollaboration::create(['kol_id' => $kol->id, 'owner_id' => $user->id, 'status' => 'confirmed', 'currency' => 'USD', 'fulfillment_required' => true]);
        $this->actingAs($user)->postJson("/kol/library/{$kol->id}/collaborations/{$needsProduct->id}/fulfillment-order")->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_bridge_requires_orders_create_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->grant($user, ['kol.view', 'kol.operate']);
        $kol = Kol::create(['display_name' => 'Scoped Creator', 'status' => 'active', 'relationship_stage' => 'cooperating', 'owner_id' => $user->id]);
        $collaboration = KolCollaboration::create(['kol_id' => $kol->id, 'owner_id' => $user->id, 'status' => 'confirmed', 'currency' => 'USD', 'fulfillment_required' => true]);
        $this->actingAs($user)->postJson("/kol/library/{$kol->id}/collaborations/{$collaboration->id}/fulfillment-order")->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    private function fixture(bool $fulfillmentRequired, bool $withProduct): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->grant($user, ['kol.view', 'kol.operate', 'orders.create', 'orders.view']);
        $kol = Kol::create(['display_name' => 'Fixture Creator', 'status' => 'active', 'relationship_stage' => 'cooperating', 'owner_id' => $user->id]);
        $collaboration = KolCollaboration::create(['kol_id' => $kol->id, 'owner_id' => $user->id, 'status' => 'confirmed', 'currency' => 'USD', 'fulfillment_required' => $fulfillmentRequired]);
        if ($withProduct) {
            $product = Product::create(['source_type' => 'manual', 'sku' => 'FIXTURE-SKU-'.uniqid(), 'name' => 'Fixture Product', 'is_active' => true]);
            $collaboration->products()->create(['product_id' => $product->id, 'sku_snapshot' => $product->sku, 'product_name_snapshot' => $product->name, 'quantity' => 1]);
        }
        return [$user, $kol, $collaboration];
    }

    private function grant(User $user, array $permissions): void
    {
        $role = Role::create(['key' => 'test_kol_fulfillment_'.uniqid(), 'label' => 'Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);
    }
}
