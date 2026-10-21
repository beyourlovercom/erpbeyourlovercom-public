<?php

namespace Tests\Unit;

use App\Modules\Logistics\Application\CarrierRoutingPolicy;
use App\Modules\Logistics\Application\WarehouseRoutingPolicy;
use Tests\TestCase;

final class WarehouseRoutingPolicyTest extends TestCase
{
    public function test_default_policy_keeps_de_automatic_and_us_assisted_only(): void
    {
        $policy = app(WarehouseRoutingPolicy::class);

        $this->assertSame(['DE'], $policy->automaticCountries());
        $this->assertTrue($policy->automaticEnabled('de'));
        $this->assertSame('fixed', $policy->strategy('DE'));
        $this->assertSame(['DE'], $policy->warehouseCodes('DE'));
        $this->assertSame('DE', $policy->fixedWarehouseCode('DE'));

        $this->assertFalse($policy->automaticEnabled('CA'));
        $this->assertSame('fixed', $policy->strategy('CA'));
        $this->assertSame(['CA'], $policy->warehouseCodes('CA'));
        $this->assertSame('CA', $policy->fixedWarehouseCode('CA'));

        $this->assertFalse($policy->automaticEnabled('US'));
        $this->assertSame('physical_zone_then_priority', $policy->strategy('us'));
        $this->assertSame(['US-NY', 'US-SM', 'US-LA'], $policy->warehouseCodes('US'));
        $this->assertSame([], $policy->fallbackPriority('US'));
        $this->assertSame(['US-SM', 'US-LA'], config('warehouse_routing.countries.US.rules.physical_zone.west.warehouse_priority'));
        $this->assertSame(['US-NY'], config('warehouse_routing.countries.US.rules.physical_zone.east.warehouse_priority'));
        $this->assertNull($policy->fixedWarehouseCode('US'));
    }

    public function test_warehouse_aliases_normalize_to_three_canonical_us_codes(): void
    {
        $policy = app(WarehouseRoutingPolicy::class);
        $this->assertSame('US-NY', $policy->canonicalWarehouseCode('US-NJ'));
        $this->assertSame('US-NY', $policy->canonicalWarehouseCode('美东仓'));
        $this->assertSame('US-LA', $policy->canonicalWarehouseCode('MorePlus'));
        $this->assertSame('US-SM', $policy->canonicalWarehouseCode('US-SJ'));
        $this->assertSame('US-SM', $policy->canonicalWarehouseCode('SMGJ'));
        $this->assertSame('US-SM', $policy->canonicalWarehouseCode('世纪仓'));
        $this->assertSame('US-SM', $policy->canonicalWarehouseCode('世迈国际仓'));
        $this->assertSame('CA', $policy->canonicalWarehouseCode('CA02'));
        $this->assertSame('CA', $policy->canonicalWarehouseCode('加拿大仓'));
    }

    public function test_canada_carrier_policy_is_canada_post_only(): void
    {
        $ca = config('carrier_routing.countries.CA');
        $this->assertTrue($ca['automatic']);
        $this->assertSame('fixed_by_warehouse', $ca['strategy']);
        $this->assertSame('Canada Post', $ca['warehouses']['CA']['carrier']);
        $this->assertSame([], $ca['manual_overrides']);
        $this->assertFalse($ca['auto_rank_carriers']);
    }


    public function test_germany_three_tier_policy_is_explicit_and_non_de_countries_stay_assisted_only(): void
    {
        $policy = app(WarehouseRoutingPolicy::class);

        foreach (['CZ', 'PL', 'SK', 'HU', 'SI', 'AT', 'NL', 'BE'] as $country) {
            $this->assertFalse($policy->automaticEnabled($country));
            $this->assertSame('de_preferred', $policy->strategy($country));
            $this->assertSame(['DE'], $policy->warehouseCodes($country));
        }

        foreach (['FR', 'ES', 'IT', 'PT', 'GR', 'EE'] as $country) {
            $this->assertFalse($policy->automaticEnabled($country));
            $this->assertSame('de_cost_gate', $policy->strategy($country));
            $this->assertSame(['DE'], $policy->warehouseCodes($country));
            $this->assertSame(1.0, (float) $policy->deCostGate($country)['per_order_handling_cost_eur']);
        }

        foreach (['DK', 'FI', 'SE'] as $country) {
            $this->assertFalse($policy->automaticEnabled($country));
            $this->assertSame('blocked', $policy->strategy($country));
            $this->assertNotNull($policy->blockedReason($country));
        }
    }

    public function test_germany_alias_and_dhl_only_carrier_policy_are_frozen(): void
    {
        $policy = app(WarehouseRoutingPolicy::class);
        $this->assertSame('DE', $policy->canonicalWarehouseCode('天运德国'));
        $this->assertSame('DE', $policy->canonicalWarehouseCode('天运仓'));

        foreach (['DE', 'CZ', 'PL', 'SK', 'HU', 'SI', 'AT', 'NL', 'BE', 'FR', 'ES', 'IT', 'PT', 'GR', 'EE'] as $country) {
            $carrier = config("carrier_routing.countries.{$country}");
            $this->assertSame('DHL', $carrier['warehouses']['DE']['carrier']);
            $this->assertSame([], $carrier['manual_overrides']);
            $this->assertFalse($carrier['compare_price']);
        }
    }

    public function test_global_policy_allows_split_preview_but_never_automatic_split(): void
    {
        $policy = app(WarehouseRoutingPolicy::class);
        $this->assertTrue($policy->splitShipmentAllowed());
        $this->assertFalse($policy->automaticSplitShipmentEnabled());
        $this->assertSame('human_review_only', $policy->splitExecutionMode());
        $carrierPolicy = app(CarrierRoutingPolicy::class);
        $this->assertSame('4PX', $carrierPolicy->factoryRoute('DE')['carrier']);
        $this->assertSame('SD经济挂号-带电', $carrierPolicy->factoryRoute('DE')['service']);
        $this->assertTrue($policy->isUsMilitaryDestination(['city' => 'FPO', 'province_state' => 'AP']));
        $this->assertFalse($policy->factoryAllowed(['city' => 'FPO', 'province_state' => 'AP']));
        $this->assertTrue($policy->isUsAkHiDestination(['province_state' => 'AK']));
        $this->assertFalse($policy->isUsAkHiDestination(['province_state' => 'AE', 'city' => 'APO']));
    }

    public function test_factory_carrier_country_overrides_and_bilingual_labels_are_frozen(): void
    {
        $policy = app(CarrierRoutingPolicy::class);

        foreach (['MX', 'CL'] as $country) $this->assertSame('Weship', $policy->factoryRoute($country)['carrier']);
        foreach (['AR', 'UA', 'RU'] as $country) $this->assertSame('POSTNL international', $policy->factoryRoute($country)['carrier']);
        foreach (['TH', 'ID', 'VN'] as $country) $this->assertSame('KingTrans', $policy->factoryRoute($country)['carrier']);

        $this->assertSame('4PX / 速达', $policy->displayName('4PX'));
        $this->assertSame('Weship / 纬狮', $policy->displayName('Weship'));
        $this->assertSame('POSTNL / 佰事达', $policy->displayName('POSTNL international'));
        $this->assertSame('Canada Post / 加拿大邮政', $policy->displayName('Canada Post'));
        $this->assertSame('SF Express / 顺丰速运', $policy->displayName('SF Express'));
        $this->assertSame('Singapore Post / 新加坡邮政', $policy->displayName('Singapore Post'));
        $this->assertSame('JD / 京东快递', $policy->displayName('JD'));
        $this->assertSame('KingTrans / 唐歌', $policy->displayName('KingTrans'));
        $this->assertSame('USPS / 美国邮政', $policy->displayName('USPS'));
        $this->assertSame('FedEx / 联邦', $policy->displayName('FedEx'));
    }

    public function test_runtime_config_contains_no_business_routing_rules(): void
    {
        $runtime = config('inventory_auto_allocation');
        $this->assertArrayNotHasKey('country_allowlist', $runtime);
        $this->assertArrayNotHasKey('warehouse_by_country', $runtime);
    }
}
