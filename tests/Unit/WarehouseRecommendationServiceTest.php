<?php

namespace Tests\Unit;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Logistics\Application\UspsZoneResolver;
use App\Modules\Logistics\Application\UsFulfillmentZoneResolver;
use App\Modules\Logistics\Application\WarehouseRecommendationService;
use App\Modules\Logistics\Application\WarehouseRoutingPolicy;
use Tests\TestCase;

final class WarehouseRecommendationServiceTest extends TestCase
{
    public function test_generic_service_region_recommendation_still_works(): void
    {
        $service = $this->service(
            [$this->warehouse(2, 'W-GB', true, [['country_code' => 'GB', 'priority' => 1]])],
            [10 => [$this->stock(2, 8)]],
        );
        $result = $service->recommend('gb', [['product_id' => 10, 'quantity' => 3]]);
        $this->assertSame('recommended', $result['status']);
        $this->assertSame(2, $result['warehouse']['id']);
    }

    public function test_generic_route_blocks_without_service_regions(): void
    {
        $result = $this->service([$this->warehouse(2, 'W-GB', true, [])], [])
            ->recommend('GB', [['product_id' => 10, 'quantity' => 1]]);
        $this->assertSame('service_regions_unconfigured', $result['reason_code']);
    }

    public function test_us_zone_four_or_less_prefers_us_ny_when_all_have_stock(): void
    {
        $warehouses = [
            $this->warehouse(1, 'US-LA', true, []),
            $this->warehouse(2, 'US-NY', true, []),
            $this->warehouse(7, 'US-SM', true, []),
        ];
        $availability = [10 => [$this->stock(1, 10), $this->stock(2, 10), $this->stock(7, 10)]];
        $result = $this->service($warehouses, $availability)
            ->recommend('US', [['product_id' => 10, 'quantity' => 1]], '10001');

        $this->assertSame('US-NY', $result['warehouse']['code']);
        $this->assertSame('recommended_physical_zone', $result['reason_code']);
        $this->assertSame('east', $result['routing_context']['physical_zone']['zone']);
        $this->assertSame('美东区', $result['routing_context']['physical_zone']['zone_label']);
    }

    public function test_us_zone_over_four_prefers_us_sj_then_us_la_then_us_ny(): void
    {
        $warehouses = [
            $this->warehouse(1, 'US-LA', true, []),
            $this->warehouse(2, 'US-NY', true, []),
            $this->warehouse(7, 'US-SM', true, []),
        ];
        $availability = [10 => [$this->stock(1, 10), $this->stock(2, 10), $this->stock(7, 10)]];
        $result = $this->service($warehouses, $availability)
            ->recommend('US', [['product_id' => 10, 'quantity' => 1]], '90001');

        $this->assertSame('US-SM', $result['warehouse']['code']);
        $this->assertSame('recommended_physical_zone', $result['reason_code']);
        $this->assertSame('west', $result['routing_context']['physical_zone']['zone']);
        $this->assertSame(['US-SM', 'US-LA'], $result['routing_context']['warehouse_priority']);
    }

    public function test_us_falls_to_us_la_when_us_sj_cannot_cover_whole_order(): void
    {
        $warehouses = [
            $this->warehouse(1, 'US-LA', true, []),
            $this->warehouse(2, 'US-NY', true, []),
            $this->warehouse(7, 'US-SM', true, []),
        ];
        $availability = [10 => [$this->stock(1, 10), $this->stock(2, 10), $this->stock(7, 0)]];
        $result = $this->service($warehouses, $availability)
            ->recommend('US', [['product_id' => 10, 'quantity' => 1]], '90001');
        $this->assertSame('US-LA', $result['warehouse']['code']);
        $this->assertSame('west', $result['routing_context']['physical_zone']['zone']);
    }

    public function test_us_zip_is_primary_signal_when_state_is_missing(): void
    {
        $warehouses = [
            $this->warehouse(2, 'US-NY', true, []),
            $this->warehouse(7, 'US-SM', true, []),
        ];
        $availability = [10 => [$this->stock(2, 10), $this->stock(7, 10)]];
        $result = $this->service($warehouses, $availability)
            ->recommend('US', [['product_id' => 10, 'quantity' => 1]], '37601');

        $this->assertSame('US-NY', $result['warehouse']['code']);
        $this->assertSame('east', $result['routing_context']['physical_zone']['zone']);
        $this->assertNull($result['routing_context']['physical_zone']['state']);
    }

    public function test_us_zip_and_state_conflict_requires_human_review(): void
    {
        $warehouses = [
            $this->warehouse(2, 'US-NY', true, []),
            $this->warehouse(7, 'US-SM', true, []),
        ];
        $availability = [10 => [$this->stock(2, 10), $this->stock(7, 10)]];
        $result = $this->service($warehouses, $availability)->recommend(
            'US', [['product_id' => 10, 'quantity' => 1]], '90001', [], ['province_state' => 'NY']
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('us_physical_zone_unresolved', $result['reason_code']);
        $this->assertSame('state_zip_zone_conflict', $result['routing_context']['physical_zone']['status']);
        $this->assertNull($result['warehouse']);
    }

    public function test_us_does_not_cross_physical_zone_when_local_zone_stock_cannot_cover_whole_order(): void
    {
        $warehouses = [
            $this->warehouse(1, 'US-LA', true, []),
            $this->warehouse(2, 'US-NY', true, []),
            $this->warehouse(7, 'US-SM', true, []),
        ];
        $availability = [
            10 => [$this->stock(1, 5), $this->stock(2, 0), $this->stock(7, 5)],
            20 => [$this->stock(1, 0), $this->stock(2, 5), $this->stock(7, 5)],
        ];
        $result = $this->service($warehouses, $availability)->recommend('US', [
            ['product_id' => 10, 'quantity' => 1],
            ['product_id' => 20, 'quantity' => 1],
        ], '10001');
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('insufficient_stock_in_physical_zone', $result['reason_code']);
        $this->assertNull($result['warehouse']);
        $this->assertSame('east', $result['routing_context']['physical_zone']['zone']);
    }

    public function test_us_military_address_keeps_local_us_recommendation_and_forbids_factory(): void
    {
        $warehouses = [$this->warehouse(2, 'US-NY', true, [])];
        $availability = [10 => [$this->stock(2, 10)]];
        $result = $this->service($warehouses, $availability)->recommend(
            'US',
            [['product_id' => 10, 'quantity' => 1]],
            '09012',
            [],
            ['city' => 'APO', 'province_state' => 'AE'],
        );

        $this->assertSame('recommended', $result['status']);
        $this->assertSame('recommended_military_us_local', $result['reason_code']);
        $this->assertTrue($result['routing_context']['military_address']);
        $this->assertFalse($result['routing_context']['factory_allowed']);
    }

    public function test_us_ak_hi_blocks_one_click_us_recommendation_for_cost_review(): void
    {
        $warehouses = [$this->warehouse(1, 'US-LA', true, [])];
        $availability = [10 => [$this->stock(1, 10)]];
        $result = $this->service($warehouses, $availability)->recommend(
            'US',
            [['product_id' => 10, 'quantity' => 1]],
            '96815',
            [],
            ['province_state' => 'HI', 'city' => 'Honolulu'],
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('us_ak_hi_cost_review_required', $result['reason_code']);
        $this->assertTrue($result['routing_context']['factory_allowed']);
        $this->assertTrue($result['routing_context']['human_review_required']);
    }

    public function test_canada_fixed_policy_only_recommends_ca_even_when_other_warehouse_has_ca_region(): void
    {
        $warehouses = [
            $this->warehouse(4, 'CA', true, [['country_code' => 'CA', 'priority' => 2]], 40),
            $this->warehouse(1, 'US-LA', true, [['country_code' => 'CA', 'priority' => 1]], 10),
        ];
        $availability = [10 => [$this->stock(4, 10), $this->stock(1, 10)]];
        $result = $this->service($warehouses, $availability)
            ->recommend('CA', [['product_id' => 10, 'quantity' => 1]], 'M5V 2T6');

        $this->assertSame('recommended_fixed_warehouse', $result['reason_code']);
        $this->assertSame('CA', $result['warehouse']['code']);
        $this->assertSame(4, $result['warehouse']['id']);
    }

    public function test_canada_fixed_policy_blocks_instead_of_falling_back_to_us_when_ca_stock_is_short(): void
    {
        $warehouses = [
            $this->warehouse(4, 'CA', true, [['country_code' => 'CA', 'priority' => 1]], 40),
            $this->warehouse(1, 'US-LA', true, [['country_code' => 'CA', 'priority' => 1]], 10),
        ];
        $availability = [10 => [$this->stock(4, 0), $this->stock(1, 10)]];
        $result = $this->service($warehouses, $availability)
            ->recommend('CA', [['product_id' => 10, 'quantity' => 1]]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('insufficient_stock', $result['reason_code']);
        $this->assertNull($result['warehouse']);
    }

    public function test_de_group_a_prefers_de_when_whole_order_stock_is_available(): void
    {
        $warehouses = [$this->warehouse(3, 'DE', true, [], 30)];
        $availability = [10 => [$this->stock(3, 5)]];
        $result = $this->service($warehouses, $availability)
            ->recommend('CZ', [['product_id' => 10, 'quantity' => 2]]);

        $this->assertSame('recommended', $result['status']);
        $this->assertSame('recommended_de_preferred', $result['reason_code']);
        $this->assertSame('DE', $result['warehouse']['code']);
        $this->assertSame('DHL', $result['routing_context']['carrier']);
    }

    public function test_de_group_b_blocks_without_real_cost_evidence(): void
    {
        $warehouses = [$this->warehouse(3, 'DE', true, [], 30)];
        $availability = [10 => [$this->stock(3, 5)]];
        $result = $this->service($warehouses, $availability)
            ->recommend('FR', [['product_id' => 10, 'quantity' => 1]]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('missing_de_cost_evidence', $result['reason_code']);
        $this->assertSame('china_historical_shipping_cost_eur', $result['routing_context']['missing_field']);
    }

    public function test_de_group_b_recommends_de_only_when_dhl_plus_one_euro_is_strictly_lower(): void
    {
        $warehouses = [$this->warehouse(3, 'DE', true, [], 30)];
        $availability = [10 => [$this->stock(3, 5)]];
        $evidence = [
            'china_historical_shipping_cost_eur' => 11.20,
            'china_historical_cost_source' => 'historical-domestic-bill-001',
            'de_dhl_shipping_cost_eur' => 9.10,
            'de_dhl_quote_source' => 'tianyun-de-dhl-2025-05-05',
        ];
        $result = $this->service($warehouses, $availability)
            ->recommend('FR', [['product_id' => 10, 'quantity' => 1]], null, $evidence);

        $this->assertSame('recommended', $result['status']);
        $this->assertSame('recommended_de_cost_lower', $result['reason_code']);
        $this->assertSame(10.10, $result['routing_context']['de_total_routing_cost_eur']);
        $this->assertSame(11.20, $result['routing_context']['china_historical_shipping_cost_eur']);
        $this->assertFalse($result['routing_context']['storage_cost_in_routing']);
    }

    public function test_de_group_b_does_not_recommend_de_when_total_cost_is_not_lower(): void
    {
        $warehouses = [$this->warehouse(3, 'DE', true, [], 30)];
        $availability = [10 => [$this->stock(3, 5)]];
        $evidence = [
            'china_historical_shipping_cost_eur' => 10.00,
            'china_historical_cost_source' => 'historical-domestic-bill-002',
            'de_dhl_shipping_cost_eur' => 9.00,
            'de_dhl_quote_source' => 'tianyun-de-dhl-2025-05-05',
        ];
        $result = $this->service($warehouses, $availability)
            ->recommend('IT', [['product_id' => 10, 'quantity' => 1]], null, $evidence);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('de_cost_not_lower', $result['reason_code']);
        $this->assertSame(10.0, $result['routing_context']['de_total_routing_cost_eur']);
    }

    public function test_de_group_c_never_routes_to_de_without_owner_policy_change(): void
    {
        $warehouses = [$this->warehouse(3, 'DE', true, [], 30)];
        $availability = [10 => [$this->stock(3, 50)]];
        $result = $this->service($warehouses, $availability)
            ->recommend('SE', [['product_id' => 10, 'quantity' => 1]]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('country_policy_blocked', $result['reason_code']);
        $this->assertNull($result['warehouse']);
    }

    public function test_missing_weight_returns_inventory_candidates_without_selecting_a_winner(): void
    {
        $warehouses = [
            $this->warehouse(1, 'US-LA', true, []),
            $this->warehouse(2, 'US-NY', true, []),
            $this->warehouse(7, 'US-SM', true, []),
        ];
        $availability = [10 => [
            $this->stock(1, 5),
            $this->stock(2, 0),
            $this->stock(7, 5),
        ]];
        $context = [
            'warehouses' => $warehouses,
            'availability' => $availability,
            'measurement_availability' => [
                'status' => 'missing_weight',
                'missing_weight_product_ids' => [10],
            ],
        ];

        $result = $this->service($warehouses, $availability)->recommend(
            'US',
            [['product_id' => 10, 'quantity' => 1]],
            '90001',
            [],
            [],
            $context,
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('missing_product_weight_inventory_only', $result['reason_code']);
        $this->assertNull($result['warehouse']);
        $this->assertSame(2, $result['candidate_count']);
        $this->assertFalse($result['routing_context']['cost_ranking_enabled']);
        $this->assertSame('manual_warehouse_selection', $result['routing_context']['next_action']);
        $this->assertSame(
            ['US-LA', 'US-SM'],
            collect($result['routing_context']['candidate_warehouses'])->pluck('code')->sort()->values()->all(),
        );
    }

    public function test_missing_weight_with_no_whole_order_stock_goes_to_procurement(): void
    {
        $warehouses = [
            $this->warehouse(1, 'US-LA', true, []),
            $this->warehouse(2, 'US-NY', true, []),
        ];
        $availability = [10 => [
            $this->stock(1, 0),
            $this->stock(2, 0),
        ]];
        $context = [
            'warehouses' => $warehouses,
            'availability' => $availability,
            'measurement_availability' => [
                'status' => 'missing_weight',
                'missing_weight_product_ids' => [10],
            ],
        ];

        $result = $this->service($warehouses, $availability)->recommend(
            'US',
            [['product_id' => 10, 'quantity' => 1]],
            '90001',
            [],
            [],
            $context,
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('insufficient_stock', $result['reason_code']);
        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame('procurement', $result['routing_context']['next_action']);
        $this->assertSame([], $result['routing_context']['candidate_warehouses']);
    }

    public function test_unconfirmed_warehouse_is_never_considered(): void
    {
        $result = $this->service([$this->warehouse(2, 'US-NY', false, [])], [10 => [$this->stock(2, 100)]])
            ->recommend('US', [['product_id' => 10, 'quantity' => 1]], '10001');
        $this->assertSame('no_inventory_authority', $result['reason_code']);
    }

    public function test_prepared_context_reuses_batched_reads_for_fifty_recommendations(): void
    {
        $reader = new class implements InventoryAvailabilityReader
        {
            public int $warehouseReads = 0;

            public int $availabilityReads = 0;

            public function activeWarehouses(): array
            {
                $this->warehouseReads++;

                return [[
                    'id' => 2, 'slug' => 'w-gb', 'name' => 'W-GB', 'code' => 'W-GB',
                    'country_code' => 'GB', 'priority' => 1, 'can_auto_allocate' => true,
                    'service_regions' => [['country_code' => 'GB', 'priority' => 1]],
                ]];
            }

            public function forProductIds(array $productIds): array
            {
                $this->availabilityReads++;

                return [10 => [['warehouse_id' => 2, 'quantity_available' => 100]]];
            }
        };
        $service = new WarehouseRecommendationService($reader, app(WarehouseRoutingPolicy::class), app(UspsZoneResolver::class), app(UsFulfillmentZoneResolver::class));
        $context = $service->prepare([10]);

        for ($i = 0; $i < 50; $i++) {
            $this->assertSame('recommended', $service->recommend('GB', [['product_id' => 10, 'quantity' => 1]], null, [], [], $context)['status']);
        }

        $this->assertSame(1, $reader->warehouseReads);
        $this->assertSame(1, $reader->availabilityReads);
    }

    private function service(array $warehouses, array $availability): WarehouseRecommendationService
    {
        return new WarehouseRecommendationService(
            $this->reader($warehouses, $availability),
            app(WarehouseRoutingPolicy::class),
            app(UspsZoneResolver::class),
            app(UsFulfillmentZoneResolver::class),
        );
    }

    private function warehouse(int $id, string $code, bool $auto, array $regions, int $priority = 20): array
    {
        return [
            'id' => $id, 'slug' => strtolower($code), 'name' => $code, 'code' => $code,
            'country_code' => null, 'priority' => $priority, 'can_auto_allocate' => $auto,
            'service_regions' => $regions,
        ];
    }

    private function stock(int $warehouseId, int $available): array
    {
        return [
            'warehouse_id' => $warehouseId, 'warehouse_slug' => 'w-'.$warehouseId,
            'warehouse_name' => 'W'.$warehouseId, 'quantity_on_hand' => $available,
            'quantity_reserved' => 0, 'quantity_available' => $available,
        ];
    }

    private function reader(array $warehouses, array $availability): InventoryAvailabilityReader
    {
        return new class($warehouses, $availability) implements InventoryAvailabilityReader
        {
            public function __construct(private array $warehouses, private array $availability) {}

            public function activeWarehouses(): array
            {
                return $this->warehouses;
            }

            public function forProductIds(array $productIds): array
            {
                return collect($this->availability)->only($productIds)->all();
            }
        };
    }
}
