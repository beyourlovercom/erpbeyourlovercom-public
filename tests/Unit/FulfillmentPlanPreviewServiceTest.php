<?php

namespace Tests\Unit;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Logistics\Application\CarrierRoutingPolicy;
use App\Modules\Logistics\Application\FulfillmentPlanPreviewService;
use App\Modules\Logistics\Application\UsFulfillmentZoneResolver;
use App\Modules\Logistics\Application\WarehouseRoutingPolicy;
use Tests\TestCase;

final class FulfillmentPlanPreviewServiceTest extends TestCase
{
    public function test_military_address_blocks_when_no_single_us_warehouse_can_fulfill_without_factory(): void
    {
        $warehouses = [
            $this->warehouse(1, 'US-LA'),
            $this->warehouse(2, 'US-NY'),
        ];
        $availability = [
            10 => [$this->stock(1, 2), $this->stock(2, 0)],
            20 => [$this->stock(1, 0), $this->stock(2, 3)],
        ];

        $preview = $this->service($warehouses, $availability)->preview('US', [
            ['product_id' => 10, 'quantity' => 1],
            ['product_id' => 20, 'quantity' => 1],
        ], ['city' => 'APO', 'province_state' => 'AE']);

        $this->assertSame('blocked', $preview['status']);
        $this->assertSame('no_fulfillment_plan', $preview['reason_code']);
        $this->assertTrue($preview['constraints']['military_address']);
        $this->assertTrue($preview['constraints']['must_use_us_warehouse']);
        $this->assertFalse($preview['constraints']['factory_allowed']);
        $this->assertSame([], $preview['plans']);
    }

    public function test_ak_hi_includes_us_and_factory_options_and_requires_human_review(): void
    {
        $preview = $this->service(
            [$this->warehouse(1, 'US-LA')],
            [10 => [$this->stock(1, 5)]],
        )->preview('US', [['product_id' => 10, 'quantity' => 1]], ['province_state' => 'HI']);

        $this->assertTrue($preview['constraints']['ak_hi']);
        $this->assertTrue($preview['constraints']['factory_allowed']);
        $this->assertTrue($preview['constraints']['human_review_required']);
        $sourceTypes = collect($preview['plans'])->flatMap(fn (array $plan) => collect($plan['segments'])->pluck('source_type'))->unique()->values()->all();
        $this->assertContains('warehouse', $sourceTypes);
        $this->assertContains('factory', $sourceTypes);
    }

    public function test_cost_evidence_can_compare_best_split_and_best_unsplit_without_auto_executing_split(): void
    {
        $service = $this->service([
            $this->warehouse(1, 'US-LA'),
            $this->warehouse(2, 'US-NY'),
            $this->warehouse(7, 'US-SM'),
        ], [
            10 => [$this->stock(1, 0), $this->stock(2, 5), $this->stock(7, 5)],
            20 => [$this->stock(1, 0), $this->stock(2, 5), $this->stock(7, 5)],
        ]);
        $items = [['product_id' => 10, 'quantity' => 1], ['product_id' => 20, 'quantity' => 1]];
        $first = $service->preview('US', $items, ['province_state' => 'CA', 'postal_code' => '90001']);
        $single = collect($first['plans'])->first(fn (array $plan): bool => ! $plan['is_split'] && $plan['segments'][0]['source_code'] === 'US-SM');
        $split = collect($first['plans'])->first(fn (array $plan): bool => $plan['is_split'] && collect($plan['segments'])->pluck('source_code')->sort()->values()->all() === ['FACTORY', 'US-SM']);
        $this->assertNotNull($single);
        $this->assertNotNull($split);

        $second = $service->preview('US', $items, ['province_state' => 'CA', 'postal_code' => '90001'], [
            'plan_costs' => [
                $single['plan_key'] => ['amount' => 17.40, 'currency' => 'USD', 'source' => 'usps-quote'],
                $split['plan_key'] => ['amount' => 11.80, 'currency' => 'USD', 'source' => 'split-usps-quotes'],
            ],
        ]);

        $this->assertSame('USD', $second['comparison']['currency']);
        $this->assertSame($split['plan_key'], $second['comparison']['lowest_cost_plan_key']);
        $this->assertSame($single['plan_key'], $second['comparison']['best_single_plan_key']);
        $this->assertSame($split['plan_key'], $second['comparison']['best_split_plan_key']);
        $this->assertSame(5.6, $second['comparison']['split_saving']);
        $splitPlan = collect($second['plans'])->firstWhere('plan_key', $split['plan_key']);
        $this->assertSame('preview_only_multi_shipment_schema_required', $splitPlan['execution_status']);
    }

    public function test_missing_weight_lists_only_whole_order_stocked_warehouses_without_cost_ranking(): void
    {
        $warehouses = [
            $this->warehouse(1, 'US-LA'),
            $this->warehouse(2, 'US-NY'),
            $this->warehouse(7, 'US-SM'),
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

        $preview = $this->service($warehouses, $availability)->preview(
            'US',
            [['product_id' => 10, 'quantity' => 1]],
            ['province_state' => 'CA', 'postal_code' => '90001'],
            [],
            $context,
        );

        $this->assertSame('preview', $preview['status']);
        $this->assertSame('inventory_only_manual_warehouse_selection', $preview['reason_code']);
        $this->assertSame('inventory_only_missing_weight', $preview['fallback_policy']['mode']);
        $this->assertFalse($preview['fallback_policy']['cost_ranking_enabled']);
        $this->assertFalse($preview['fallback_policy']['warehouse_auto_recommendation_enabled']);
        $this->assertSame('manual_warehouse_selection', $preview['fallback_policy']['next_action']);
        $this->assertSame('not_evaluated_missing_weight', $preview['comparison']['status']);
        $this->assertNull($preview['comparison']['lowest_cost_plan_key']);
        $this->assertCount(2, $preview['plans']);
        $this->assertTrue(collect($preview['plans'])->every(fn (array $plan): bool => $plan['plan_type'] === 'single'
            && $plan['cost']['status'] === 'not_evaluated'
            && $plan['segments'][0]['source_type'] === 'warehouse'
        ));
        $this->assertSame(
            ['US-LA', 'US-SM'],
            collect($preview['plans'])->pluck('segments.0.source_code')->sort()->values()->all(),
        );
    }

    public function test_missing_weight_without_whole_order_stock_blocks_to_procurement_instead_of_factory(): void
    {
        $warehouses = [$this->warehouse(1, 'US-LA')];
        $availability = [10 => [$this->stock(1, 0)]];
        $context = [
            'warehouses' => $warehouses,
            'availability' => $availability,
            'measurement_availability' => [
                'status' => 'missing_weight',
                'missing_weight_product_ids' => [10],
            ],
        ];

        $preview = $this->service($warehouses, $availability)->preview(
            'US',
            [['product_id' => 10, 'quantity' => 1]],
            ['province_state' => 'CA', 'postal_code' => '90001'],
            [],
            $context,
        );

        $this->assertSame('blocked', $preview['status']);
        $this->assertSame('insufficient_stock', $preview['reason_code']);
        $this->assertSame([], $preview['plans']);
        $this->assertSame('procurement', $preview['fallback_policy']['next_action']);
    }

    public function test_factory_is_final_fallback_when_no_overseas_warehouse_can_cover_items(): void
    {
        $preview = $this->service(
            [$this->warehouse(3, 'DE')],
            [10 => [$this->stock(3, 0)]],
        )->preview('CZ', [['product_id' => 10, 'quantity' => 1]]);

        $this->assertCount(1, $preview['plans']);
        $plan = $preview['plans'][0];
        $this->assertSame('factory', $plan['plan_type']);
        $this->assertSame('FACTORY', $plan['segments'][0]['source_code']);
        $this->assertSame('4PX', $plan['segments'][0]['carrier']);
        $this->assertSame('SD经济挂号-带电', $plan['segments'][0]['service']);
        $this->assertSame('manual_review_only', $plan['execution_status']);
    }

    public function test_factory_plan_uses_owner_frozen_country_carrier_override(): void
    {
        $preview = $this->service([], [10 => []])->preview('MX', [['product_id' => 10, 'quantity' => 1]]);

        $this->assertSame('preview', $preview['status']);
        $plan = collect($preview['plans'])->firstWhere('plan_type', 'factory');
        $this->assertSame('Weship', $plan['segments'][0]['carrier']);
        $this->assertNull($plan['segments'][0]['service']);

        $preview = $this->service([], [10 => []])->preview('ID', [['product_id' => 10, 'quantity' => 1]]);
        $plan = collect($preview['plans'])->firstWhere('plan_type', 'factory');
        $this->assertSame('KingTrans', $plan['segments'][0]['carrier']);
    }

    public function test_prepared_context_prevents_per_order_inventory_rereads(): void
    {
        $warehouses = [$this->warehouse(2, 'US-NY')];
        $availability = [10 => [$this->stock(2, 100)]];
        $reader = new class($warehouses, $availability) implements InventoryAvailabilityReader
        {
            public int $warehouseReads = 0;

            public int $availabilityReads = 0;

            public function __construct(private array $warehouses, private array $availability) {}

            public function activeWarehouses(): array
            {
                $this->warehouseReads++;

                return $this->warehouses;
            }

            public function forProductIds(array $productIds): array
            {
                $this->availabilityReads++;

                return collect($this->availability)->only($productIds)->all();
            }
        };
        $service = new FulfillmentPlanPreviewService($reader, app(WarehouseRoutingPolicy::class), app(CarrierRoutingPolicy::class), app(UsFulfillmentZoneResolver::class));
        $context = ['warehouses' => $warehouses, 'availability' => $availability];

        for ($i = 0; $i < 50; $i++) {
            $preview = $service->preview('US', [['product_id' => 10, 'quantity' => 1]], ['province_state' => 'NY', 'postal_code' => '10001'], [], $context);
            $this->assertSame('preview', $preview['status']);
        }

        $this->assertSame(0, $reader->warehouseReads);
        $this->assertSame(0, $reader->availabilityReads);
    }

    private function service(array $warehouses, array $availability): FulfillmentPlanPreviewService
    {
        return new FulfillmentPlanPreviewService($this->reader($warehouses, $availability), app(WarehouseRoutingPolicy::class), app(CarrierRoutingPolicy::class), app(UsFulfillmentZoneResolver::class));
    }

    private function warehouse(int $id, string $code): array
    {
        return [
            'id' => $id, 'slug' => strtolower($code), 'name' => $code, 'code' => $code,
            'country_code' => null, 'priority' => 10, 'can_auto_allocate' => true, 'service_regions' => [],
        ];
    }

    private function stock(int $warehouseId, int $available): array
    {
        return [
            'warehouse_id' => $warehouseId, 'warehouse_slug' => 'w-'.$warehouseId, 'warehouse_name' => 'W'.$warehouseId,
            'inventory_state' => 'confirmed', 'can_auto_allocate' => true, 'quantity_on_hand' => $available,
            'quantity_reserved' => 0, 'quantity_available' => $available, 'expected_restock_qty' => 0,
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
