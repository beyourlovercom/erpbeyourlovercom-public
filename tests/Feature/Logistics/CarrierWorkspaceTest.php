<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CarrierWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_carrier_index_uses_master_data_and_shipment_history_only_for_statistics(): void
    {
        $user = $this->userWith(['logistics.view']);
        $this->shipment($user, 'DHL', 'DHL-1', 2.500, 18.50, 'USD', 'delivered');
        $this->shipment($user, 'UAT Carrier', 'UAT-1', 1.000, 5.00, 'USD', null);

        $this->assertSame(12, Carrier::query()->count());

        $this->actingAs($user)->get('/logistics/carriers')->assertInertia(
            fn (Assert $page) => $page
                ->component('Logistics/Carriers/Index')
                ->has('carriers', 12)
                ->where('carriers.0.name', '4PX')
                ->where('carriers.0.display_name', '4PX / 速达')
                ->where('carriers.0.shipment_count', 0)
                ->where('carriers.1.name', 'DHL')
                ->where('carriers.1.shipment_count', 1)
                ->where('carriers.8.name', 'POSTNL international')
                ->where('carriers.8.display_name', 'POSTNL / 佰事达')
                ->where('carriers.9.name', 'Weship')
                ->where('carriers.9.display_name', 'Weship / 纬狮')
                ->where('carriers.10.name', 'KingTrans')
                ->where('carriers.10.display_name', 'KingTrans / 唐歌')
                ->where('canOperate', false)
        );

        $this->actingAs($user)->get('/logistics/carriers/DHL')->assertInertia(
            fn (Assert $page) => $page
                ->component('Logistics/Carriers/Show')
                ->where('carrier', 'DHL')
                ->where('carrierDisplayName', 'DHL / 敦豪')
                ->where('carrierMaster.name_en', 'DHL')
                ->where('carrierMaster.name_zh', '敦豪')
                ->has('carrierMaster.services', 3)
                ->where('canEditCarrier', false)
                ->where('summary.shipment_count', 1)
                ->where('summary.delivered_count', 1)
                ->where('shipments.total', 1)
                ->where('shipments.data.0.erp_order_id', '007-UAT-DHL-1')
                ->where('shipments.data.0.order_url', fn ($value) => is_string($value) && str_starts_with($value, '/customers/orders/'))
                ->missing('shipments.data.0.external_order_number')
                ->missing('shipments.data.0.internal_order_number')
        );

        $this->actingAs($user)->get('/logistics/carriers/4PX')->assertInertia(
            fn (Assert $page) => $page
                ->where('carrier', '4PX')
                ->where('summary.shipment_count', 0)
                ->where('shipments.total', 0)
        );
    }

    public function test_historical_logistics_shipments_do_not_pollute_carrier_workspace(): void
    {
        $user = $this->userWith(['logistics.view']);
        $shipment = $this->shipment($user, 'DHL', 'DHL-HIST', 1.000, 9.00, 'USD', 'delivered');
        $shipment->order->externalReferences()->create([
            'provider' => 'historical_logistics',
            'connection_key' => 'historical:test',
            'reference' => 'KOL-HIST-CARRIER',
        ]);

        $this->actingAs($user)->get('/logistics/carriers/DHL')->assertInertia(fn (Assert $page) => $page
            ->where('summary.shipment_count', 0)
            ->where('shipments.total', 0));
    }

    public function test_operator_can_add_carrier_and_it_immediately_becomes_a_fulfillment_option(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);

        $this->actingAs($user)->post('/logistics/carriers', ['name' => 'UniUni'])
            ->assertRedirect('/logistics/carriers');

        $this->assertDatabaseHas('logistics_carriers', ['name' => 'UniUni', 'is_active' => 1]);

        // The /logistics entry now redirects to Wait Stock. Verify the
        // canonical carrier catalog instead of expecting the legacy homepage.
        $this->assertContains(
            'UniUni',
            array_column(app(CarrierServiceCatalog::class)->options(), 'value'),
        );
    }

    public function test_billing_extension_keeps_cost_and_raw_evidence_without_copying_shipment_identity(): void
    {
        $user = $this->userWith(['logistics.view']);
        $shipment = $this->shipment($user, 'DHL', 'DHL-EVIDENCE', 4.125, 33.75, 'USD', 'exception');

        $this->assertDatabaseHas('shipment_carrier_billing', [
            'shipment_id' => $shipment->id,
            'billing_document_number' => 'BILL-DHL-EVIDENCE',
            'data_source' => 'carrier_bill',
        ]);
        $this->assertFalse(Schema::hasColumn('shipment_carrier_billing', 'order_id'));
        $this->assertFalse(Schema::hasColumn('shipment_carrier_billing', 'tracking_number'));
        $this->assertFalse(Schema::hasColumn('shipment_carrier_billing', 'shipment_status'));

        $this->actingAs($user)->get('/logistics/carriers/DHL')->assertInertia(
            fn (Assert $page) => $page
                ->where('shipments.data.0.erp_order_id', fn ($value): bool => is_string($value) && str_starts_with($value, '007-'))
                ->where('shipments.data.0.tracking_number', 'DHL-EVIDENCE')
                ->where('shipments.data.0.billing_document_number', 'BILL-DHL-EVIDENCE')
                ->where('shipments.data.0.raw_evidence.discount_amount', 3.25)
        );
    }

    public function test_carrier_workspace_requires_logistics_view_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->get('/logistics/carriers')->assertForbidden();
        $this->actingAs($user)->get('/logistics/carriers/DHL')->assertForbidden();
        $this->actingAs($user)->post('/logistics/carriers', ['name' => 'Blocked Carrier'])->assertForbidden();
    }

    private function shipment(User $user, string $carrier, string $tracking, float $weight, float $cost, string $currency, ?string $deliveryStatus): Shipment
    {
        $warehouse = Warehouse::query()->where('is_active', true)->orderBy('priority')->firstOrFail();
        $order = Order::query()->create([
            'internal_order_number' => 'ERP-'.$tracking,
            'erp_order_id' => '007-0908-c'.substr(md5($tracking), 0, 8),
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'external_order_number' => 'ORDER-'.$tracking,
            'erp_order_id' => '007-UAT-'.$tracking,
            'order_date' => '2026-08-28',
            'owner_id' => $user->id,
            'order_status' => 'shipped',
            'currency' => $currency,
        ]);
        $order->address()->create(['recipient_name' => 'Receiver '.$tracking, 'country' => 'US']);
        $order->items()->create(['product_summary' => 'Carrier Test Product', 'sku' => 'SKU-'.$tracking, 'quantity' => 1, 'unit_price' => '10.00', 'line_revenue' => '10.00']);

        $shipment = $order->shipment()->create([
            'warehouse_id' => $warehouse->id,
            'shipment_status' => 'shipped',
            'carrier' => $carrier,
            'service' => 'Express',
            'tracking_number' => $tracking,
            'shipped_at' => now(),
            'handled_by' => $user->id,
        ]);
        $shipment->billingEvidence()->create([
            'chargeable_weight_kg' => $weight,
            'actual_cost' => $cost,
            'currency' => $currency,
            'billing_document_number' => 'BILL-'.$tracking,
            'delivery_status' => $deliveryStatus,
            'data_source' => 'carrier_bill',
            'raw_evidence' => ['discount_amount' => 3.25, 'provider_field' => 'preserved'],
        ]);

        return $shipment;
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'carrier-'.uniqid(), 'label' => 'Carrier Workspace']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
