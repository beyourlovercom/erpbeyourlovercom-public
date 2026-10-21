<?php

namespace Tests\Feature\Products;

use App\Modules\Products\Application\ProductService;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) mkdir($directory, 0777, true);
        $this->csvPath = tempnam($directory, 'woo_test_') . '.csv';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->csvPath)) {
            unlink($this->csvPath);
        }
        parent::tearDown();
    }

    private function writeCsv(array $rows, array $headers = ['ID', 'Type', 'SKU', 'GTIN, UPC, EAN, or ISBN', 'Name', 'Weight (kg)', 'Sale price', 'Regular price', 'Published']): void
    {
        $handle = fopen($this->csvPath, 'w');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            if (in_array('Published', $headers, true) && count($row) < count($headers)) {
                $row[] = 1;
            }
            fputcsv($handle, $row);
        }
        fclose($handle);
    }

    public function test_utf8_bom_woo_export_header_is_supported(): void
    {
        $handle = fopen($this->csvPath, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['ID', 'Type', 'SKU', 'GTIN, UPC, EAN, or ISBN', 'Name', 'Weight (kg)', 'Sale price', 'Regular price', 'Published']);
        fputcsv($handle, [9991, 'simple', 'BOM-SKU', '', 'BOM Product', '', 10, 12, 1]);
        fclose($handle);

        $preview = app(ProductService::class)->preview($this->csvPath);

        $this->assertSame(1, $preview['result']->totalRows);
        $this->assertSame(1, $preview['result']->candidates);
        $this->assertTrue($preview['result']->toArray()['canConfirm']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_basic_import_inserts_products(): void
    {
        $this->writeCsv([
            [1001, 'simple', 'SKU-A', '', 'Product A', 0.5, 59, 89],
            [1002, 'simple', 'SKU-B', '', 'Product B', '', 79, 109],
        ]);

        $result = app(ProductService::class)->import($this->csvPath);

        $this->assertSame(2, $result->inserted);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(0, $result->invalid);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', ['woocommerce_product_id' => 1001, 'sku' => 'SKU-A', 'name' => 'Product A']);
    }

    public function test_import_is_idempotent(): void
    {
        $this->writeCsv([[1001, 'simple', 'SKU-A', '', 'Product A', 0.5, 59, 89]]);

        $service = app(ProductService::class);
        $first = $service->import($this->csvPath);
        $second = $service->import($this->csvPath);

        $this->assertSame(1, $first->inserted);
        $this->assertSame(0, $second->inserted);
        $this->assertSame(1, $second->skipped);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_import_updates_changed_product(): void
    {
        $this->writeCsv([[1001, 'simple', 'SKU-A', '', 'Product A', 0.5, 59, 89]]);
        $service = app(ProductService::class);
        $service->import($this->csvPath);

        $this->writeCsv([[1001, 'simple', 'SKU-A', '', 'Product A Updated', 0.6, 69, 99]]);
        $result = $service->import($this->csvPath);

        $this->assertSame(1, $result->updated);
        $this->assertDatabaseHas('products', ['woocommerce_product_id' => 1001, 'name' => 'Product A Updated']);
    }

    public function test_standard_bootstrap_requires_published_column(): void
    {
        $this->writeCsv(
            [[1001, 'simple', 'SKU-A', '', 'Product A', 0.5, 59, 89]],
            ['ID', 'Type', 'SKU', 'GTIN, UPC, EAN, or ISBN', 'Name', 'Weight (kg)', 'Sale price', 'Regular price'],
        );

        $result = app(ProductService::class)->preview($this->csvPath)['result'];

        $this->assertSame(1, $result->invalid);
        $this->assertFalse($result->toArray()['canConfirm']);
        $this->assertStringContainsString('Published', implode(' ', $result->errors));
        $this->assertDatabaseCount('products', 0);
    }

    public function test_cli_requires_explicit_confirmation_after_preview(): void
    {
        $this->writeCsv([[1001, 'simple', 'CLI-SKU', '', 'CLI Product', 0.5, 59, 89]]);

        $this->artisan('products:import-woo-csv', ['path' => $this->csvPath])
            ->expectsConfirmation('Confirm import of 1 canonical Product candidates?', 'no')
            ->assertSuccessful();

        $this->assertDatabaseMissing('products', ['sku' => 'CLI-SKU']);
    }

    public function test_blank_sku_rows_are_reconciled_and_variations_are_skipped(): void
    {
        $this->writeCsv([
            [2001, 'simple', '', '', 'No SKU Product', '', 59, 89],
            [2002, 'variation', '', '', 'No SKU Variation', '', 59, 89],
        ]);

        $result = app(ProductService::class)->preview($this->csvPath)['result'];

        $this->assertSame(1, $result->noSkuNonVariationReconciliation);
        $this->assertSame(1, $result->skippedNoSkuVariations);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_nonblank_sku_must_be_unique(): void
    {
        Product::create(['woocommerce_product_id' => 3001, 'sku' => 'DUP-SKU', 'name' => 'First', 'product_type' => 'simple']);

        $this->writeCsv([[3002, 'simple', 'DUP-SKU', '', 'Second', '', 59, 89]]);

        $result = app(ProductService::class)->preview($this->csvPath)['result'];
        $this->assertSame(1, $result->identityConflicts);
        $this->assertFalse($result->toArray()['canConfirm']);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_invalid_rows_counted_and_reported(): void
    {
        $this->writeCsv([
            [0, 'simple', 'SKU-BAD', '', 'Bad ID', '', 59, 89],
            [1003, 'simple', 'SKU-OK', '', 'Good Product', '', 59, 89],
        ]);

        $result = app(ProductService::class)->preview($this->csvPath)['result'];

        $this->assertSame(1, $result->invalid);
        $this->assertSame(1, $result->inserted);
        $this->assertNotEmpty($result->errors);
    }

    public function test_search_skus_excludes_blank_sku_products(): void
    {
        Product::create(['woocommerce_product_id' => 4001, 'sku' => 'FIND-ME', 'name' => 'Findable', 'product_type' => 'simple']);
        Product::create(['woocommerce_product_id' => 4002, 'sku' => null, 'name' => 'FIND-ME No SKU', 'product_type' => 'simple']);

        $results = app(ProductService::class)->searchSkus('FIND');

        $this->assertCount(1, $results);
        $this->assertSame('FIND-ME', $results[0]['sku']);
    }

    public function test_search_returns_prefix_match_first(): void
    {
        Product::create(['woocommerce_product_id' => 5001, 'sku' => 'XABC-P-CONTAINS', 'name' => 'Contains', 'product_type' => 'simple']);
        Product::create(['woocommerce_product_id' => 5002, 'sku' => 'ABC-PREFIX', 'name' => 'Prefix', 'product_type' => 'simple']);
        Product::create(['woocommerce_product_id' => 5003, 'sku' => 'ZZZ-NAME', 'name' => 'ABC-P Name Match', 'product_type' => 'simple']);

        $results = app(ProductService::class)->searchSkus('ABC-P');

        $this->assertSame(['ABC-PREFIX', 'XABC-P-CONTAINS', 'ZZZ-NAME'], array_column($results, 'sku'));
    }

    public function test_empty_search_returns_valid_skus_in_ascending_order(): void
    {
        Product::create(['woocommerce_product_id' => 5101, 'sku' => 'B100', 'name' => 'Beta', 'product_type' => 'simple']);
        Product::create(['woocommerce_product_id' => 5102, 'sku' => 'A200', 'name' => 'Alpha Two', 'product_type' => 'simple']);
        Product::create(['woocommerce_product_id' => 5103, 'sku' => 'A100', 'name' => 'Alpha One', 'product_type' => 'simple']);
        Product::create(['woocommerce_product_id' => 5104, 'sku' => null, 'name' => 'No SKU', 'product_type' => 'simple']);

        $results = app(ProductService::class)->searchSkus('');

        $this->assertSame(['A100', 'A200', 'B100'], array_column($results, 'sku'));
    }

    public function test_import_does_not_delete_unrelated_products(): void
    {
        Product::create(['woocommerce_product_id' => 9999, 'sku' => 'EXISTING', 'name' => 'Keep Me', 'product_type' => 'simple']);

        $this->writeCsv([[6001, 'simple', 'NEW-ONE', '', 'New Product', '', 59, 89]]);
        app(ProductService::class)->import($this->csvPath);

        $this->assertDatabaseHas('products', ['woocommerce_product_id' => 9999, 'sku' => 'EXISTING']);
        $this->assertDatabaseCount('products', 2);
    }

    public function test_preview_does_not_mutate_and_confirm_imports_only_candidates(): void
    {
        $this->writeCsv([
            [7001, 'simple', 'CAN-1', '', 'Candidate', '0.4', 10, 12],
            [7002, 'variation', '', '', 'Variation', '0.2', 5, 6],
            [7003, 'simple', '', '', 'Reconcile', '', 3, 4],
        ], ['ID', 'Type', 'SKU', 'GTIN, UPC, EAN, or ISBN', 'Name', 'Weight (kg)', 'Sale price', 'Regular price', 'Published']);
        $handle = fopen($this->csvPath, 'a');
        fputcsv($handle, [7004, 'simple', 'CAN-2', '', 'Inactive', '', 7, 8, 0]);
        fclose($handle);

        $service = app(ProductService::class);
        $preview = $service->preview($this->csvPath);
        $this->assertSame(4, $preview['result']->totalRows);
        $this->assertSame(2, $preview['result']->candidates);
        $this->assertSame(1, $preview['result']->skippedNoSkuVariations);
        $this->assertSame(1, $preview['result']->noSkuNonVariationReconciliation);
        $this->assertSame(0, Product::count());

        $confirmed = $service->confirm($preview['candidates']);
        $this->assertSame(2, $confirmed->inserted);
        $this->assertDatabaseHas('products', ['sku' => 'CAN-2', 'is_active' => 0]);
    }

    public function test_confirm_preserves_existing_erp_fields_and_fills_null_weight_only(): void
    {
        Product::create(['woocommerce_product_id' => 8001, 'sku' => 'KEEP-1', 'name' => 'Old', 'product_type' => 'simple', 'weight_kg' => null, 'shipping_weight_kg' => 9, 'length_cm' => 8, 'notes' => 'ERP']);
        $this->writeCsv([[8001, 'simple', 'KEEP-1', '', 'New', '0.7', 10, 11]]);
        $preview = app(ProductService::class)->preview($this->csvPath);
        app(ProductService::class)->confirm($preview['candidates']);
        $product = Product::where('sku', 'KEEP-1')->firstOrFail();
        $this->assertSame('0.7000', $product->weight_kg);
        $this->assertSame('9.0000', $product->shipping_weight_kg);
        $this->assertSame('8.00', $product->length_cm);
        $this->assertSame('ERP', $product->notes);
    }
}
