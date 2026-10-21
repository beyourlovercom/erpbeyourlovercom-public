<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\CostMaterializationService;
use App\Modules\Finance\Application\Contracts\FxManualRateRepository;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Application\ConfirmedProductCostSourceImportService;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductCostSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ConfirmedProductCostSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_cli_apply_requires_explicit_single_process_gate(): void
    {
        $product = $this->product('PROD-GATE-1');
        $file = tempnam(sys_get_temp_dir(), 'confirmed-product-cost-');
        self::assertNotFalse($file);
        file_put_contents($file, "erp_product_id,erp_sku,source_variant_key,unit_cost,currency\n{$product->id},{$product->sku},LONG-1,12.34,CNY\n");

        $previousEnv = $this->app['env'];
        $this->app['env'] = 'production';
        putenv('ERP_ACCOUNTING_CONFIRMED_PRODUCT_COST_PRODUCTION_APPLY_ENABLED');
        try {
            $args = ['--file' => $file, '--effective-from' => '2026-07-01', '--source-reference' => 'source.xlsx', '--apply' => true];
            $this->artisan('finance:import-confirmed-product-costs', $args)->assertFailed();
            self::assertSame(0, ProductCostSource::query()->count());

            putenv('ERP_ACCOUNTING_CONFIRMED_PRODUCT_COST_PRODUCTION_APPLY_ENABLED=true');
            $this->artisan('finance:import-confirmed-product-costs', $args)->assertSuccessful();
            self::assertSame(1, ProductCostSource::query()->count());
        } finally {
            putenv('ERP_ACCOUNTING_CONFIRMED_PRODUCT_COST_PRODUCTION_APPLY_ENABLED');
            $this->app['env'] = $previousEnv;
            @unlink($file);
        }
    }

    public function test_import_plan_is_dry_run_and_same_cost_variants_can_be_applied_idempotently(): void
    {
        $product = $this->product('READY-1');
        $rows = [
            $this->row($product, 'LONG-A', '117.600000000000', 'CNY'),
            $this->row($product, 'LONG-B', '117.6', 'CNY'),
        ];
        $service = $this->app->make(ConfirmedProductCostSourceImportService::class);
        $plan = $service->plan($rows, '成本货本007组 7月进销存.xlsx', '2026-07-01', '2026-07-31');
        self::assertSame(2, $plan['create']);
        self::assertSame(0, $plan['blocked']);
        self::assertSame(0, ProductCostSource::query()->count());

        $applied = $service->apply($plan);
        self::assertSame(2, $applied['created']);
        self::assertSame(2, ProductCostSource::query()->count());

        $again = $service->plan($rows, '成本货本007组 7月进销存.xlsx', '2026-07-01', '2026-07-31');
        self::assertSame(0, $again['create']);
        self::assertSame(2, $again['noop']);
        self::assertSame(0, $again['blocked']);
    }

    public function test_different_cost_variants_for_one_product_period_fail_closed(): void
    {
        $product = $this->product('AMB-1');
        $service = $this->app->make(ConfirmedProductCostSourceImportService::class);
        $plan = $service->plan([
            $this->row($product, 'LONG-A', '10.00', 'CNY'),
            $this->row($product, 'LONG-B', '11.00', 'CNY'),
        ], 'source.xlsx', '2026-07-01');

        self::assertSame(2, $plan['blocked']);
        self::assertSame('multiple_confirmed_costs_for_product_period', $plan['rows'][0]['blocker']);
        self::assertSame('multiple_confirmed_costs_for_product_period', $plan['rows'][1]['blocker']);
        self::assertSame(0, ProductCostSource::query()->count());
    }

    public function test_same_currency_confirmed_source_materializes_confirmed_and_is_noop_on_repeat(): void
    {
        $product = $this->product('CNY-READY');
        $this->source($product, 'LONG-1', '12.345000000000', 'CNY');
        $order = $this->order('CNY', '2026-07-15');
        $order->items()->create(['product_id' => $product->id, 'product_summary' => 'x', 'sku' => $product->sku, 'quantity' => 2, 'unit_price' => '50.00', 'line_revenue' => '100.00']);

        $service = $this->app->make(CostMaterializationService::class);
        $first = $service->apply($order);
        $entry = $order->costEntries()->where('cost_type', 'product_cost')->firstOrFail();
        self::assertSame('24.69', $entry->amount);
        self::assertSame('CNY', $entry->currency);
        self::assertSame('confirmed_manual', $entry->provenance);
        self::assertSame('confirmed', $entry->status);
        self::assertCount(1, array_filter($first['applied'], fn (array $row): bool => ($row['cost_type'] ?? null) === 'product_cost'));

        $second = $service->apply($order->fresh());
        self::assertCount(1, array_filter($second['noop'], fn (array $row): bool => ($row['cost_type'] ?? null) === 'product_cost'));
    }

    public function test_cny_confirmed_source_materializes_to_usd_with_frozen_posting_fx(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $product = $this->product('USD-FX');
        $this->source($product, 'LONG-1', '71.000000000000', 'CNY');
        $order = $this->order('USD', '2026-07-15');
        $order->items()->create(['product_id' => $product->id, 'product_summary' => 'x', 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => '50.00', 'line_revenue' => '50.00']);

        $service = $this->app->make(CostMaterializationService::class);
        $candidate = $this->candidate($service->preview($order), 'product_cost');
        self::assertSame('apply', $candidate['materialization_status']);
        self::assertSame('10.00', $candidate['amount']);
        self::assertSame('USD', $candidate['currency']);
        self::assertSame('CNY', $candidate['source_currency']);
        self::assertSame('71.00', $candidate['source_amount']);
        self::assertSame('0.14084507', $candidate['posting_fx']['source_to_usd_rate']);

        $service->apply($order);
        $entry = $order->costEntries()->where('cost_type', 'product_cost')->firstOrFail();
        self::assertSame('10.00', $entry->amount);
        self::assertSame('0.14084507', $entry->exchange_rate);
        self::assertSame('10.00', $entry->base_amount);
        self::assertSame('USD', $entry->base_currency);
        self::assertStringContainsString('settings_fx_posting:CNY(fixed)->USD(ecb)', $entry->exchange_rate_source);
        self::assertSame('2026-09-07', $entry->exchange_rate_date->toDateString());
    }

    public function test_cny_source_to_eur_freezes_transaction_fx_once_and_preserves_existing_snapshot(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $product = $this->product('EUR-FX');
        $this->source($product, 'LONG-1', '78.100000000000', 'CNY');
        $order = $this->order('EUR', '2026-07-15');
        $order->financials()->create(['currency' => 'EUR', 'revenue_provenance' => 'provider_import', 'completeness_status' => 'INCOMPLETE']);
        $order->items()->create(['product_id' => $product->id, 'product_summary' => 'x', 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => '50.00', 'line_revenue' => '50.00']);

        $service = $this->app->make(CostMaterializationService::class);
        $service->apply($order);
        $entry = $order->costEntries()->where('cost_type', 'product_cost')->firstOrFail();
        $financials = $order->financials()->firstOrFail()->fresh();
        self::assertSame('10.00', $entry->amount);
        self::assertSame('EUR', $entry->currency);
        self::assertSame('11.00', $entry->base_amount);
        self::assertSame('1.10000000', $financials->fx_rate);
        self::assertSame('2026-09-07', $financials->fx_rate_date->toDateString());
        self::assertStringContainsString('settings_fx_posting:EUR(ecb)->USD(ecb)', $financials->fx_rate_source);

        $second = $service->apply($order->fresh());
        self::assertCount(1, array_filter($second['noop'], fn (array $row): bool => ($row['cost_type'] ?? null) === 'product_cost'));
        self::assertSame('1.10000000', $order->financials()->firstOrFail()->fresh()->fx_rate);

        $existing = $this->order('EUR', '2026-07-15');
        $existing->financials()->create([
            'currency' => 'EUR', 'revenue_provenance' => 'provider_import', 'completeness_status' => 'INCOMPLETE',
            'fx_rate' => '1.25000000', 'fx_rate_date' => '2026-07-01', 'fx_rate_source' => 'transaction_original',
        ]);
        $existing->items()->create(['product_id' => $product->id, 'product_summary' => 'x', 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => '50.00', 'line_revenue' => '50.00']);
        $service->apply($existing);
        self::assertSame('8.80', $existing->costEntries()->where('cost_type', 'product_cost')->firstOrFail()->amount);
        self::assertSame('1.25000000', $existing->financials()->firstOrFail()->fresh()->fx_rate);
        self::assertSame('transaction_original', $existing->financials()->firstOrFail()->fresh()->fx_rate_source);
    }

    public function test_bridge_uses_manual_settings_rate_without_ecb_availability(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response('unavailable', 503)]);
        $this->app->make(FxManualRateRepository::class)->replace(['USD' => 7.10], ['USD' => false], null);
        $product = $this->product('USD-MANUAL-FX');
        $this->source($product, 'LONG-1', '71.000000000000', 'CNY');
        $order = $this->order('USD', '2026-07-15');
        $order->items()->create(['product_id' => $product->id, 'product_summary' => 'x', 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => '50.00', 'line_revenue' => '50.00']);

        $this->app->make(CostMaterializationService::class)->apply($order);
        $entry = $order->costEntries()->where('cost_type', 'product_cost')->firstOrFail();
        self::assertSame('10.00', $entry->amount);
        self::assertSame('0.14084507', $entry->exchange_rate);
        self::assertStringContainsString('USD(manual)', $entry->exchange_rate_source);
    }

    public function test_latest_effective_confirmed_cost_version_wins_for_later_orders(): void
    {
        $product = $this->product('VERSION-1');
        $this->source($product, 'JULY-LONG', '10.000000000000', 'CNY', '2026-07-01');
        $this->source($product, 'AUG-LONG', '12.000000000000', 'CNY', '2026-08-01');
        $order = $this->order('CNY', '2026-08-15');
        $order->items()->create(['product_id' => $product->id, 'product_summary' => 'x', 'sku' => $product->sku, 'quantity' => 2, 'unit_price' => '20.00', 'line_revenue' => '40.00']);

        $candidate = $this->candidate($this->app->make(CostMaterializationService::class)->preview($order), 'product_cost');
        self::assertSame('24.00', $candidate['amount']);
        self::assertSame('confirmed', $candidate['status']);
        self::assertSame(['AUG-LONG'], $candidate['source_keys']);
    }

    public function test_import_blocks_conflict_with_existing_confirmed_source_in_same_period(): void
    {
        $product = $this->product('PERIOD-CONFLICT');
        $this->source($product, 'LONG-OLD', '10.000000000000', 'CNY', '2026-07-01');
        $service = $this->app->make(ConfirmedProductCostSourceImportService::class);
        $plan = $service->plan([$this->row($product, 'LONG-NEW', '11.00', 'CNY')], 'new-source.xlsx', '2026-07-01');

        self::assertSame(1, $plan['blocked']);
        self::assertSame('existing_period_cost_conflict', $plan['rows'][0]['blocker']);
    }

    public function test_source_effective_date_is_respected_and_standard_reference_fallback_is_unchanged(): void
    {
        $product = $this->product('DATE-1', ['standard_purchase_cost' => '3.0000', 'cost_currency' => 'USD']);
        $this->source($product, 'LONG-1', '20.000000000000', 'CNY', '2026-07-01');
        $order = $this->order('USD', '2026-06-30');
        $order->items()->create(['product_id' => $product->id, 'product_summary' => 'x', 'sku' => $product->sku, 'quantity' => 2, 'unit_price' => '10.00', 'line_revenue' => '20.00']);

        $candidate = $this->candidate($this->app->make(CostMaterializationService::class)->preview($order), 'product_cost');
        self::assertSame('estimated_reference', $candidate['provenance']);
        self::assertSame('estimated', $candidate['status']);
        self::assertSame('6.00', $candidate['amount']);
    }

    private function product(string $sku, array $extra = []): Product
    {
        return Product::query()->create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => $sku, 'name' => $sku, ...$extra]);
    }

    private function row(Product $product, string $key, string $cost, string $currency): array
    {
        return ['erp_product_id' => (string) $product->id, 'erp_sku' => $product->sku, 'source_variant_key' => $key, 'unit_cost' => $cost, 'currency' => $currency];
    }

    private function source(Product $product, string $key, string $cost, string $currency, string $effective = '2026-07-01'): ProductCostSource
    {
        return ProductCostSource::query()->create([
            'product_id' => $product->id, 'source_key' => $key, 'unit_cost' => $cost, 'currency' => $currency,
            'provenance' => 'confirmed_source_file', 'status' => 'confirmed', 'source_type' => 'inventory_cost_book',
            'source_reference' => 'source.xlsx', 'effective_from' => $effective, 'source_at' => '2026-07-31 00:00:00',
            'confirmed_at' => '2026-09-08 00:00:00', 'source_fingerprint' => hash('sha256', $product->id.'|'.$key.'|'.$effective),
        ]);
    }

    private function order(string $currency, string $date): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'PCS-'.strtoupper(uniqid()), 'source_type' => 'manual', 'order_kind' => 'customer',
            'order_date' => $date, 'order_status' => 'pending_shipment', 'currency' => $currency,
        ]);
    }


    private function ecbXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
  <Cube><Cube time="2026-09-07"><Cube currency="USD" rate="1.10"/><Cube currency="EUR" rate="1.00"/><Cube currency="CNY" rate="7.81"/></Cube></Cube>
</gesmes:Envelope>
XML;
    }

    private function candidate(array $payload, string $type): array
    {
        foreach ($payload['candidates'] as $candidate) {
            if (($candidate['cost_type'] ?? null) === $type) {
                return $candidate;
            }
        }
        self::fail('Candidate not found: '.$type);
    }
}
