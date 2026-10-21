<?php

namespace Tests\Unit;

use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Logistics\Application\ChinaFactoryRoutingCostCalculator;
use App\Modules\Logistics\Application\GermanyDhlRoutingCostCalculator;
use App\Modules\Logistics\Application\FulfillmentPlanCostEstimator;
use App\Modules\Logistics\Application\FulfillmentPlanPreviewService;
use Tests\TestCase;

final class FulfillmentRoutingCostCalculatorTest extends TestCase
{
    public function test_china_de_uses_frozen_row_and_eu_item_surcharge(): void
    {
        $result = app(ChinaFactoryRoutingCostCalculator::class)->calculate('DE', 0.250, [['product_id' => 10, 'quantity' => 1]]);

        $this->assertSame('ready', $result['status']);
        $this->assertSame(54.75, $result['components'][0]['amount']);
        $this->assertSame('CNY', $result['components'][0]['currency']);
        $this->assertSame('actual_weight_only', $result['components'][0]['billing_basis']);
        $this->assertSame(3.0, $result['components'][1]['amount']);
        $this->assertSame('EUR', $result['components'][1]['currency']);
    }

    public function test_china_fr_uses_frozen_row(): void
    {
        $result = app(ChinaFactoryRoutingCostCalculator::class)->calculate('FR', 0.500, [['product_id' => 10, 'quantity' => 1]]);

        $this->assertSame(86.50, $result['components'][0]['amount']);
        $this->assertSame(3.0, $result['components'][1]['amount']);
    }

    public function test_china_channel_fails_closed_for_ak_hi_and_military_destinations(): void
    {
        $calculator = app(ChinaFactoryRoutingCostCalculator::class);
        foreach ([['province_state' => 'AK'], ['province_state' => 'HI'], ['city' => 'APO', 'province_state' => 'AE'], ['postal_code' => '96701']] as $destination) {
            $result = $calculator->calculate('US', 0.25, [['product_id' => 10, 'quantity' => 1]], $destination);
            $this->assertSame('missing_evidence', $result['status']);
            $this->assertSame('factory_channel_unserved_destination', $result['reason_code']);
        }
    }

    public function test_dhl_domestic_and_international_frozen_examples(): void
    {
        $calculator = app(GermanyDhlRoutingCostCalculator::class);
        $domestic = $calculator->calculate('DE', 0.454);
        $france = $calculator->calculate('FR', 0.454);
        $austria = $calculator->calculate('AT', 9.6);

        $this->assertSame(3.9346, $domestic['components'][0]['amount']);
        $this->assertSame(1.0, $domestic['components'][1]['amount']);
        $this->assertSame(8.555, $france['components'][0]['amount']);
        $this->assertSame(10.26, $austria['components'][0]['amount'] + $austria['components'][1]['amount']);
    }

    public function test_container_wires_fulfillment_plan_cost_estimator(): void
    {
        $service = app(FulfillmentPlanPreviewService::class);
        $property = new \ReflectionProperty(FulfillmentPlanPreviewService::class, 'costEstimator');

        $estimator = $property->getValue($service);
        $this->assertInstanceOf(FulfillmentPlanCostEstimator::class, $estimator);

        $fxProperty = new \ReflectionProperty(FulfillmentPlanCostEstimator::class, 'fx');
        $this->assertInstanceOf(FxReferenceSettingsService::class, $fxProperty->getValue($estimator));
    }

    public function test_unsupported_country_and_weight_fail_closed(): void
    {
        $china = app(ChinaFactoryRoutingCostCalculator::class)->calculate('AU', 0.5, [['product_id' => 10, 'quantity' => 1]]);
        $dhl = app(GermanyDhlRoutingCostCalculator::class)->calculate('DE', 32.0);

        $this->assertSame('missing_evidence', $china['status']);
        $this->assertSame('factory_rate_unavailable', $china['reason_code']);
        $this->assertSame('missing_evidence', $dhl['status']);
        $this->assertSame('dhl_weight_unavailable', $dhl['reason_code']);
    }
}
