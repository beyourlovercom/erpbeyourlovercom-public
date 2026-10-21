<?php

namespace Tests\Feature;

use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Logistics\Application\FulfillmentPlanCostEstimator;
use App\Modules\Products\Application\ProductShippingWeightStandardReader;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductWeightEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FulfillmentActualWeightEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_4px_factory_cost_uses_adopted_shipping_weight_without_packaging_dimensions(): void
    {
        $product = Product::create([
            'source_type' => 'manual',
            'sku' => 'SCAN-WEIGHT-001',
            'name' => 'Scan Weight Product',
            'is_active' => true,
        ]);
        $evidence = ProductWeightEvidence::create([
            'product_id' => $product->id,
            'weight_kind' => 'shipping_reference_weight',
            'observed_weight_kg' => 0.5000,
            'raw_sku' => $product->sku,
            'source_type' => 'logistics_scan',
            'source_reference' => 'TRACK-001',
            'status' => 'adopted',
            'reviewed_at' => now(),
        ]);
        $product->forceFill([
            'shipping_weight_kg' => 0.5000,
            'shipping_weight_evidence_id' => $evidence->id,
        ])->save();

        app(FxReferenceSettingsService::class)->save(['EUR' => 8.0], ['EUR' => false], null);

        $result = app(FulfillmentPlanCostEstimator::class)->estimate('DE', [[
            'source_type' => 'factory',
            'source_code' => 'FACTORY',
            'carrier' => '4PX',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]]);

        $this->assertSame('ready', $result['status']);
        $this->assertSame('approved-shipping-reference-weight', $result['provenance']);
        $this->assertSame(0.5, $result['segments'][0]['packaging']['actual_weight_kg']);
        $this->assertNull($result['segments'][0]['packaging']['volume_cm3']);
        $this->assertSame('actual_weight_only', $result['segments'][0]['routing']['components'][0]['billing_basis']);
        $this->assertSame(84.5, $result['segments'][0]['routing']['components'][0]['amount']);
        $this->assertSame(108.5, $result['amount']);
        $this->assertDatabaseCount('product_packaging_profiles', 0);
    }

    public function test_4px_factory_cost_fails_closed_when_weight_is_not_evidence_backed(): void
    {
        $product = Product::create([
            'source_type' => 'manual',
            'sku' => 'UNBACKED-WEIGHT-001',
            'name' => 'Unbacked Weight Product',
            'shipping_weight_kg' => 0.5000,
            'is_active' => true,
        ]);

        $reader = app(ProductShippingWeightStandardReader::class)->forProductIds([$product->id]);
        $this->assertArrayNotHasKey($product->id, $reader);

        $result = app(FulfillmentPlanCostEstimator::class)->estimate('DE', [[
            'source_type' => 'factory',
            'source_code' => 'FACTORY',
            'carrier' => '4PX',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]]);

        $this->assertSame('missing_evidence', $result['status']);
        $this->assertSame('missing_shipping_reference_weight', $result['reason_code']);
    }
}
