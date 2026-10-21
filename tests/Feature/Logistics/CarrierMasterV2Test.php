<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Logistics\Application\CarrierRoutingPolicy;
use App\Modules\Logistics\Application\ShipmentService;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CarrierMasterV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_carriers_have_bilingual_names_services_and_one_default(): void
    {
        $carrier = Carrier::query()->where('name', 'USPS')->with(['services', 'defaultService'])->firstOrFail();

        self::assertSame('USPS', $carrier->name_en);
        self::assertSame('美国邮政', $carrier->name_zh);
        self::assertSame('USPS / 美国邮政', $carrier->displayName());
        self::assertSame(3, $carrier->services->count());
        self::assertSame('USPS Priority Mail', $carrier->defaultService?->service_value);
    }

    public function test_catalog_defaults_are_carrier_specific_and_dhl_can_use_domestic_context(): void
    {
        $catalog = app(CarrierServiceCatalog::class);

        self::assertSame('USPS Priority Mail', $catalog->defaultService('USPS')?->service_value);
        self::assertSame('SD经济挂号-带电', $catalog->defaultService('4PX')?->service_value);
        self::assertSame('DHL PAKET International', $catalog->defaultService('DHL', 'FR')?->service_value);
        self::assertSame('DHL PAKET DOMESTIC', $catalog->defaultService('DHL', 'DE')?->service_value);
        self::assertSame('Standard', $catalog->defaultService('Weship')?->service_value);
    }

    public function test_catalog_resolves_only_active_services_and_falls_back_to_default_when_blank(): void
    {
        $catalog = app(CarrierServiceCatalog::class);
        $carrier = Carrier::query()->where('name', 'UPS')->with('services')->firstOrFail();
        $ground = $carrier->services->firstWhere('service_key', 'ground');
        $air = $carrier->services->firstWhere('service_key', 'next_day_air');

        self::assertSame($ground->id, $catalog->resolve('UPS', null)?->id);
        self::assertSame($air->id, $catalog->resolve('UPS', 'UPS Next Day Air')?->id);

        $air->forceFill(['is_active' => false])->save();
        self::assertNull($catalog->resolve('UPS', 'UPS Next Day Air'));
    }
    public function test_blank_service_uses_carrier_default_and_dhl_uses_domestic_default_for_germany(): void
    {
        $user = $this->operator();
        $order = Order::query()->create([
            'internal_order_number' => 'CARRIER-V2-'.uniqid(),
            'erp_order_id' => '007-CARRIER-V2-'.random_int(100, 999),
            'source_type' => 'manual', 'order_kind' => 'customer', 'order_date' => '2026-09-20',
            'order_status' => 'pending_shipment', 'currency' => 'USD',
        ]);
        $order->address()->create(['recipient_name' => 'Carrier V2', 'country' => 'US']);

        app(ShipmentService::class)->saveDraft($order, ['carrier' => 'USPS', 'service' => null], $user);
        $order->refresh();
        self::assertSame('USPS Priority Mail', $order->shipment?->service);
        self::assertNotNull($order->shipment?->carrier_service_id);

        $order->address()->update(['country' => 'DE']);
        app(ShipmentService::class)->saveDraft($order->fresh(), ['carrier' => 'DHL', 'service' => null], $user);
        $order->refresh();
        self::assertSame('DHL PAKET DOMESTIC', $order->shipment?->service);
        self::assertSame('paket_domestic', $order->shipment?->carrierService?->service_key);
    }

    public function test_warehouse_routing_is_fully_automatic_once_fulfillment_warehouse_is_known(): void
    {
        $routing = app(CarrierRoutingPolicy::class);

        foreach (['US-LA', 'US-NY', 'US-SM'] as $code) {
            $route = $routing->warehouseRoute('JP', $code);
            self::assertSame('USPS', $route['carrier']);
            self::assertSame('USPS Priority Mail', $route['service']);
        }

        self::assertSame('DHL PAKET DOMESTIC', $routing->warehouseRoute('DE', 'DE')['service']);
        self::assertSame('DHL PAKET International', $routing->warehouseRoute('FR', 'DE')['service']);
        self::assertSame('Canada Post', $routing->warehouseRoute('US', 'CA')['carrier']);
        self::assertSame('Canada Post Expedited Parcel', $routing->warehouseRoute('US', 'CA')['service']);
        self::assertSame('Weship', $routing->warehouseRoute('MX', 'FACTORY')['carrier']);
        self::assertSame('Standard', $routing->warehouseRoute('MX', 'FACTORY')['service']);
        self::assertSame('POSTNL international', $routing->warehouseRoute('AR', 'FACTORY')['carrier']);
        self::assertSame('POSTNL International Tracked', $routing->warehouseRoute('AR', 'FACTORY')['service']);
        self::assertSame('KingTrans', $routing->warehouseRoute('TH', 'FACTORY')['carrier']);
        self::assertSame('Standard', $routing->warehouseRoute('TH', 'FACTORY')['service']);
        self::assertSame('4PX', $routing->warehouseRoute('AU', 'FACTORY')['carrier']);
        self::assertSame('SD经济挂号-带电', $routing->warehouseRoute('AU', 'FACTORY')['service']);
    }

    public function test_warehouse_only_draft_auto_assigns_carrier_and_service_server_side(): void
    {
        $user = $this->operator();
        $warehouse = Warehouse::query()->where('code', 'US-LA')->firstOrFail();
        $order = Order::query()->create([
            'internal_order_number' => 'AUTO-ROUTE-'.uniqid(),
            'erp_order_id' => '007-AUTO-ROUTE-'.random_int(100, 999),
            'source_type' => 'manual', 'order_kind' => 'customer', 'order_date' => '2026-09-20',
            'order_status' => 'pending_shipment', 'currency' => 'USD',
        ]);
        $order->address()->create(['recipient_name' => 'Auto Route', 'country' => 'US']);

        app(ShipmentService::class)->saveDraft($order, [
            'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'recommended',
        ], $user);

        $shipment = $order->fresh()->shipment;
        self::assertSame('USPS', $shipment?->carrier);
        self::assertSame('USPS Priority Mail', $shipment?->service);
        self::assertSame('automatic', $shipment?->carrier_selection_source);
        self::assertNotNull($shipment?->carrier_service_id);
    }

    public function test_operator_can_edit_bilingual_carrier_master_services_and_default(): void
    {
        $user = $this->operator();
        $carrier = Carrier::query()->where('name', '4PX')->with('services')->firstOrFail();
        $services = $carrier->services->sortBy('sort_order')->values();
        $newDefault = $services[1];

        $payload = [
            'name_zh' => '速达物流',
            'name_en' => '4PX',
            'is_active' => true,
            'default_service_id' => $newDefault->id,
            'services' => $services->map(fn ($service): array => [
                'id' => $service->id,
                'service_key' => $service->service_key,
                'service_value' => $service->service_value,
                'name_en' => $service->name_en,
                'name_zh' => $service->id === $newDefault->id ? 'SD标准挂号-带电（默认）' : $service->name_zh,
                'is_active' => true,
                'sort_order' => $service->sort_order,
            ])->all(),
        ];

        $this->actingAs($user)->patch('/logistics/carriers/4PX', $payload)->assertRedirect();

        $carrier->refresh();
        self::assertSame('4PX', $carrier->name, 'canonical carrier key must stay immutable');
        self::assertSame('速达物流', $carrier->name_zh);
        self::assertSame($newDefault->id, $carrier->default_service_id);
        self::assertSame('SD标准挂号-带电（默认）', $newDefault->fresh()->name_zh);
    }

    public function test_operator_can_add_a_service_and_make_it_default_in_one_save(): void
    {
        $user = $this->operator();
        $carrier = Carrier::query()->where('name', 'UPS')->with('services')->firstOrFail();
        $services = $carrier->services->sortBy('sort_order')->map(fn ($service): array => [
            'id' => $service->id,
            'service_key' => $service->service_key,
            'service_value' => $service->service_value,
            'name_en' => $service->name_en,
            'name_zh' => $service->name_zh,
            'is_active' => true,
            'sort_order' => $service->sort_order,
        ])->values()->all();
        $services[] = [
            'id' => null,
            'service_key' => 'surepost',
            'service_value' => 'UPS SurePost',
            'name_en' => 'UPS SurePost',
            'name_zh' => '经济派送',
            'is_active' => true,
            'sort_order' => 40,
        ];

        $this->actingAs($user)->patch('/logistics/carriers/UPS', [
            'name_zh' => $carrier->name_zh,
            'name_en' => $carrier->name_en,
            'is_active' => true,
            'default_service_id' => null,
            'default_service_key' => 'surepost',
            'services' => $services,
        ])->assertRedirect();

        $carrier->refresh();
        self::assertSame('surepost', $carrier->defaultService?->service_key);
        self::assertSame('UPS SurePost', $carrier->defaultService?->service_value);
    }

    private function operator(): User
    {
        $role = Role::query()->create(['key' => 'carrier-master-v2-'.uniqid(), 'label' => 'Carrier Master V2']);
        foreach (['logistics.view', 'logistics.operate'] as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

}
