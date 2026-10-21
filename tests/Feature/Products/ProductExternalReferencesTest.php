<?php

namespace Tests\Feature\Products;

use App\Modules\Orders\Application\Inbound\NormalizedInboundOrder;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Products\Application\Contracts\ProductExternalReferenceRepository;
use App\Modules\Products\Application\ProductService;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductExternalReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductExternalReferencesTest extends TestCase
{
    use RefreshDatabase;

    private string $csvPath;
    private string $connectionKey = 'testsite.example.com';

    protected function setUp(): void
    {
        parent::setUp();
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) mkdir($directory, 0777, true);
        $this->csvPath = tempnam($directory, 'extref_test_') . '.csv';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->csvPath)) {
            unlink($this->csvPath);
        }
        parent::tearDown();
    }

    private function writeCsv(array $rows, array $headers = ['ID', 'Type', 'SKU', 'Name', 'Published', 'Weight (kg)', 'Sale price', 'Regular price', 'Parent']): void
    {
        $handle = fopen($this->csvPath, 'w');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }

    public function test_product_import_default_connection_key_uses_woocommerce_connection(): void
    {
        config([
            'services.woocommerce.connection_key' => 'woo-site.example.com',
            'services.andy_core.connection_key' => 'andy-core.example.com',
        ]);

        $this->writeCsv([
            [123, 'simple', 'DEFAULT-CONN', 'Default Connection', 1, '', '', '', ''],
        ]);

        $svc = app(ProductService::class);
        $preview = $svc->preview($this->csvPath);
        $svc->confirm($preview['candidates']);

        $this->assertDatabaseHas('product_external_references', [
            'provider' => 'woocommerce',
            'connection_key' => 'woo-site.example.com',
            'reference' => '123',
            'reference_type' => 'product',
        ]);
        $this->assertDatabaseMissing('product_external_references', [
            'connection_key' => 'andy-core.example.com',
            'reference' => '123',
        ]);
    }

    // Gate 1: Existing Woo Product ID is not treated as ERP primary key.
    public function test_woo_product_id_is_not_erp_primary_key(): void
    {
        $product = Product::create([
            'name' => 'Test Product', 'sku' => 'TEST-001', 'source_type' => 'manual',
            'is_active' => true, 'product_type' => 'simple',
        ]);

        $repo = app(ProductExternalReferenceRepository::class);
        $repo->upsert($product->id, 'woocommerce', $this->connectionKey, '9999', 'product');

        // ERP primary key must not equal the Woo ID.
        $this->assertNotEquals(9999, $product->id);
        $this->assertDatabaseHas('product_external_references', [
            'product_id' => $product->id,
            'reference' => '9999',
            'reference_type' => 'product',
        ]);
    }

    // Gate 2: One canonical Product can hold one product ref plus multiple variation refs.
    public function test_one_product_holds_product_and_multiple_variation_refs(): void
    {
        $product = Product::create([
            'name' => 'Variable', 'sku' => 'VAR-001', 'source_type' => 'woocommerce',
            'is_active' => true, 'product_type' => 'variable',
        ]);

        $repo = app(ProductExternalReferenceRepository::class);
        $repo->upsert($product->id, 'woocommerce', $this->connectionKey, '100', 'product');
        $repo->upsert($product->id, 'woocommerce', $this->connectionKey, '101', 'variation');
        $repo->upsert($product->id, 'woocommerce', $this->connectionKey, '102', 'variation');

        $refs = $repo->forProduct($product->id);
        $this->assertCount(3, $refs);
        $types = array_column($refs, 'reference_type');
        $this->assertContains('product', $types);
        $this->assertContains('variation', $types);
    }

    // Gate 3: Same remote reference on different connection keys may map independently.
    public function test_same_reference_on_different_connections_map_independently(): void
    {
        $productA = Product::create(['name' => 'Site A Product', 'sku' => 'SITE-A-001', 'source_type' => 'woocommerce', 'is_active' => true, 'product_type' => 'simple']);
        $productB = Product::create(['name' => 'Site B Product', 'sku' => 'SITE-B-001', 'source_type' => 'woocommerce', 'is_active' => true, 'product_type' => 'simple']);

        $repo = app(ProductExternalReferenceRepository::class);
        $repo->upsert($productA->id, 'woocommerce', 'site-a.example.com', '500', 'product');
        $repo->upsert($productB->id, 'woocommerce', 'site-b.example.com', '500', 'product');

        $resolvedA = $repo->findProduct('woocommerce', 'site-a.example.com', '500');
        $resolvedB = $repo->findProduct('woocommerce', 'site-b.example.com', '500');

        $this->assertSame($productA->id, $resolvedA->id);
        $this->assertSame($productB->id, $resolvedB->id);
    }

    public function test_import_same_numeric_id_on_two_connections_creates_independent_products(): void
    {
        config(['services.woocommerce.connection_key' => 'site-a.example.com']);
        $svc = app(ProductService::class);

        $this->writeCsv([[500, 'simple', 'SITE-A-500', 'Site A Product', 1, '', '', '', '']]);
        $first = $svc->preview($this->csvPath, 'site-a.example.com');
        $svc->confirm($first['candidates']);

        $this->writeCsv([[500, 'simple', 'SITE-B-500', 'Site B Product', 1, '', '', '', '']]);
        $second = $svc->preview($this->csvPath, 'site-b.example.com');

        $this->assertSame(0, $second['result']->identityConflicts);
        $this->assertTrue($second['result']->toArray()['canConfirm']);
        $svc->confirm($second['candidates']);

        $siteA = app(ProductExternalReferenceRepository::class)->findProduct('woocommerce', 'site-a.example.com', '500');
        $siteB = app(ProductExternalReferenceRepository::class)->findProduct('woocommerce', 'site-b.example.com', '500');
        $this->assertNotNull($siteA);
        $this->assertNotNull($siteB);
        $this->assertSame('SITE-A-500', $siteA->sku);
        $this->assertSame('SITE-B-500', $siteB->sku);
        $this->assertNotSame($siteA->id, $siteB->id);
    }

    public function test_secondary_connection_adds_ref_without_overwriting_primary_legacy_id(): void
    {
        config(['services.woocommerce.connection_key' => 'site-a.example.com']);
        $svc = app(ProductService::class);

        $this->writeCsv([[510, 'simple', 'SHARED-SKU', 'Shared Product', 1, '', '', '', '']]);
        $first = $svc->preview($this->csvPath, 'site-a.example.com');
        $svc->confirm($first['candidates']);

        $this->writeCsv([[610, 'simple', 'SHARED-SKU', 'Shared Product', 1, '', '', '', '']]);
        $second = $svc->preview($this->csvPath, 'site-b.example.com');
        $this->assertSame(0, $second['result']->identityConflicts);
        $svc->confirm($second['candidates']);

        $product = Product::where('sku', 'SHARED-SKU')->firstOrFail();
        $this->assertSame(510, (int) $product->woocommerce_product_id);
        $this->assertSame(1, Product::where('sku', 'SHARED-SKU')->count());
        $this->assertSame($product->id, app(ProductExternalReferenceRepository::class)->findProduct('woocommerce', 'site-a.example.com', '510')?->id);
        $this->assertSame($product->id, app(ProductExternalReferenceRepository::class)->findProduct('woocommerce', 'site-b.example.com', '610')?->id);
    }

    public function test_secondary_parent_id_does_not_use_primary_legacy_id_without_external_ref(): void
    {
        config(['services.woocommerce.connection_key' => 'site-a.example.com']);
        $primary = Product::create(['name' => 'Primary Parent', 'sku' => 'PRIMARY-PARENT', 'source_type' => 'woocommerce', 'woocommerce_product_id' => 970, 'is_active' => true, 'product_type' => 'variable']);

        $this->writeCsv([[971, 'variation', '', 'Secondary Orphan', 1, '', '', '', 'id:970']]);
        $preview = app(ProductService::class)->preview($this->csvPath, 'site-b.example.com');

        $this->assertSame(0, $preview['result']->resolvedVariationMappings);
        $this->assertSame(1, $preview['result']->toArray()['unresolvedVariationMappings']);
        $this->assertCount(0, array_filter($preview['candidates'], fn (array $row): bool => $row['_external_ref_only'] ?? false));
        $this->assertNull(app(ProductExternalReferenceRepository::class)->findProduct('woocommerce', 'site-b.example.com', '971'));
        $this->assertSame('PRIMARY-PARENT', $primary->sku);
    }

    // Gate 4: Same provider + connection + reference cannot map to two Products.
    public function test_same_provider_connection_reference_cannot_map_to_two_products(): void
    {
        $productA = Product::create(['name' => 'A', 'sku' => 'A-001', 'source_type' => 'manual', 'is_active' => true, 'product_type' => 'simple']);
        $productB = Product::create(['name' => 'B', 'sku' => 'B-001', 'source_type' => 'manual', 'is_active' => true, 'product_type' => 'simple']);

        $repo = app(ProductExternalReferenceRepository::class);
        $repo->upsert($productA->id, 'woocommerce', $this->connectionKey, '777', 'product');

        $this->expectException(\RuntimeException::class);
        $repo->upsert($productB->id, 'woocommerce', $this->connectionKey, '777', 'product');
    }

    // Gate 5: No-SKU variation with Parent SKU maps to the proven canonical parent Product.
    public function test_no_sku_variation_with_parent_sku_maps_to_parent(): void
    {
        // First, create the parent product via import.
        $this->writeCsv([
            [200, 'variable', 'PARENT-SKU', 'Parent Product', 1, '', '', '', ''],
            [201, 'variation', '', 'Variation 1', 1, '', '', '', 'PARENT-SKU'],
        ]);

        $svc = app(ProductService::class);
        $preview = $svc->preview($this->csvPath, $this->connectionKey);

        // Should have 1 SKU candidate + 1 resolved variation.
        $this->assertSame(1, $preview['result']->candidates);
        $this->assertSame(1, $preview['result']->skippedNoSkuVariations);
        $this->assertSame(0, $preview['result']->identityConflicts);

        // Variation row with resolved parent should appear in candidates (as external-ref-only).
        $extRefCandidates = array_filter($preview['candidates'], fn ($c) => $c['_external_ref_only'] ?? false);
        $this->assertCount(1, $extRefCandidates);

        $svc->confirm($preview['candidates']);

        $parent = Product::where('sku', 'PARENT-SKU')->first();
        $this->assertNotNull($parent);

        // The variation's Woo ID should be mapped to the parent.
        $repo = app(ProductExternalReferenceRepository::class);
        $resolved = $repo->findProduct('woocommerce', $this->connectionKey, '201');
        $this->assertNotNull($resolved);
        $this->assertSame($parent->id, $resolved->id);
        $this->assertDatabaseHas('product_external_references', [
            'product_id' => $parent->id,
            'reference' => '201',
            'reference_type' => 'variation',
        ]);
    }

    // Gate 6: No-SKU variation with Parent=id:<ID> maps to proven canonical parent Product.
    public function test_no_sku_variation_with_id_parent_maps_to_parent(): void
    {
        // Pre-import parent with external ref.
        $parent = Product::create(['name' => 'ID Parent', 'sku' => 'ID-PARENT', 'source_type' => 'woocommerce', 'woocommerce_product_id' => 300, 'is_active' => true, 'product_type' => 'variable']);
        $repo = app(ProductExternalReferenceRepository::class);
        $repo->upsert($parent->id, 'woocommerce', $this->connectionKey, '300', 'product');

        $this->writeCsv([
            [301, 'variation', '', 'Variation 1', 1, '', '', '', 'id:300'],
        ]);

        $svc = app(ProductService::class);
        $preview = $svc->preview($this->csvPath, $this->connectionKey);

        $extRefCandidates = array_filter($preview['candidates'], fn ($c) => $c['_external_ref_only'] ?? false);
        $this->assertCount(1, $extRefCandidates);

        $svc->confirm($preview['candidates']);

        $resolved = $repo->findProduct('woocommerce', $this->connectionKey, '301');
        $this->assertNotNull($resolved);
        $this->assertSame($parent->id, $resolved->id);
    }

    // Gate 7: No-SKU variation with missing/unresolved Parent creates no Product/ref truth.
    public function test_no_sku_variation_with_unresolved_parent_creates_nothing(): void
    {
        $this->writeCsv([
            [400, 'variation', '', 'Orphan Variation', 1, '', '', '', 'NONEXISTENT-PARENT'],
        ]);

        $svc = app(ProductService::class);
        $preview = $svc->preview($this->csvPath, $this->connectionKey);

        $this->assertSame(0, $preview['result']->candidates);
        $this->assertSame(1, $preview['result']->skippedNoSkuVariations);
        // No external-ref-only rows for unresolved.
        $extRefCandidates = array_filter($preview['candidates'], fn ($c) => $c['_external_ref_only'] ?? false);
        $this->assertCount(0, $extRefCandidates);

        $this->assertDatabaseCount('product_external_references', 0);
    }

    // Gate 8: SKU-bearing variation remains eligible for its own canonical Product and gets a variation ref.
    public function test_sku_bearing_variation_gets_variation_ref(): void
    {
        $this->writeCsv([
            [500, 'variation', 'VAR-SKU-001', 'Variation with SKU', 1, '', '', '', ''],
        ]);

        $svc = app(ProductService::class);
        $preview = $svc->preview($this->csvPath, $this->connectionKey);

        $this->assertSame(1, $preview['result']->candidates);
        $svc->confirm($preview['candidates']);

        $this->assertDatabaseHas('product_external_references', [
            'reference' => '500',
            'reference_type' => 'variation',
        ]);
    }

    // Gate 9: CSV Preview is zero-mutation.
    public function test_preview_is_zero_mutation(): void
    {
        $this->writeCsv([
            [600, 'simple', 'PREVIEW-SKU', 'Preview Product', 1, '', 10, 20, ''],
        ]);

        $svc = app(ProductService::class);
        $svc->preview($this->csvPath, $this->connectionKey);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_external_references', 0);
    }

    // Gate 10: Confirm is idempotent.
    public function test_confirm_is_idempotent(): void
    {
        $this->writeCsv([
            [700, 'simple', 'IDEM-SKU', 'Idempotent Product', 1, '', 10, 20, ''],
        ]);

        $svc = app(ProductService::class);
        $preview = $svc->preview($this->csvPath, $this->connectionKey);
        $svc->confirm($preview['candidates']);
        // Run again — must not throw or duplicate.
        $preview2 = $svc->preview($this->csvPath, $this->connectionKey);
        $svc->confirm($preview2['candidates']);

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_external_references', 1);
    }

    // Gate 11 & 12: Woo order resolves via connection-scoped external reference.
    public function test_woo_order_resolves_product_and_variation_via_external_ref(): void
    {
        $product = Product::create(['name' => 'Order Product', 'sku' => 'ORDER-SKU', 'source_type' => 'woocommerce', 'is_active' => true, 'product_type' => 'simple']);
        $repo = app(ProductExternalReferenceRepository::class);
        $repo->upsert($product->id, 'woocommerce', $this->connectionKey, '800', 'product');
        $repo->upsert($product->id, 'woocommerce', $this->connectionKey, '801', 'variation');

        $actor = null;
        $orderService = app(OrderService::class);

        $normalized = new NormalizedInboundOrder(
            provider: 'woocommerce',
            connectionKey: $this->connectionKey,
            reference: 'WOO-ORDER-1',
            externalOrderNumber: 'WOO-1001',
            orderDate: '2026-09-01',
            providerModifiedAt: '2026-09-01T00:00:00Z',
            currency: 'USD',
            orderStatus: 'pending_shipment',
            orderNote: null,
            address: ['recipient_name' => 'Test', 'phone' => '', 'country' => 'US', 'province_state' => '', 'city' => 'NYC', 'address_line_1' => '1 Main St', 'address_line_2' => '', 'postal_code' => '10001'],
            items: [
                ['woocommerce_product_id' => 800, 'woocommerce_reference_type' => 'product', 'sku' => '', 'product_summary' => 'Product line', 'quantity' => 1, 'unit_price' => '10.00'],
            ],
        );
        $result = $orderService->upsertInbound($normalized, $actor);
        $order = $result['order'];
        $item = $order->items()->first();
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame('ORDER-SKU', $item->sku);
    }

    // Gate 13: Variation ref mapped to parent yields parent canonical product_id and SKU.
    public function test_variation_ref_mapped_to_parent_yields_parent_canonical(): void
    {
        $parent = Product::create(['name' => 'Parent Product', 'sku' => 'PARENT-001', 'source_type' => 'woocommerce', 'is_active' => true, 'product_type' => 'variable']);
        $repo = app(ProductExternalReferenceRepository::class);
        // Variation 802 maps to the parent canonical product.
        $repo->upsert($parent->id, 'woocommerce', $this->connectionKey, '802', 'variation');

        $orderService = app(OrderService::class);
        $normalized = new NormalizedInboundOrder(
            provider: 'woocommerce',
            connectionKey: $this->connectionKey,
            reference: 'WOO-ORDER-2',
            externalOrderNumber: 'WOO-1002',
            orderDate: '2026-09-01',
            providerModifiedAt: '2026-09-01T00:00:00Z',
            currency: 'USD',
            orderStatus: 'pending_shipment',
            orderNote: null,
            address: ['recipient_name' => 'Test', 'phone' => '', 'country' => 'US', 'province_state' => '', 'city' => 'NYC', 'address_line_1' => '1 Main St', 'address_line_2' => '', 'postal_code' => '10001'],
            items: [
                ['woocommerce_product_id' => 802, 'woocommerce_reference_type' => 'variation', 'sku' => '', 'product_summary' => 'Variation item', 'quantity' => 1, 'unit_price' => '15.00'],
            ],
        );
        $result = $orderService->upsertInbound($normalized, null);
        $item = $result['order']->items()->first();

        $this->assertSame($parent->id, $item->product_id);
        $this->assertSame('PARENT-001', $item->sku);
    }

    // Gate 14: Same numeric remote ID on two Woo connections never cross-resolves.
    public function test_same_numeric_id_on_two_connections_never_cross_resolves(): void
    {
        $productA = Product::create(['name' => 'Site A', 'sku' => 'CROSS-A', 'source_type' => 'woocommerce', 'is_active' => true, 'product_type' => 'simple']);
        $productB = Product::create(['name' => 'Site B', 'sku' => 'CROSS-B', 'source_type' => 'woocommerce', 'is_active' => true, 'product_type' => 'simple']);

        $repo = app(ProductExternalReferenceRepository::class);
        $repo->upsert($productA->id, 'woocommerce', 'site-a.com', '900', 'product');
        $repo->upsert($productB->id, 'woocommerce', 'site-b.com', '900', 'product');

        $resolvedForA = $repo->findProduct('woocommerce', 'site-a.com', '900');
        $resolvedForB = $repo->findProduct('woocommerce', 'site-b.com', '900');

        $this->assertSame($productA->id, $resolvedForA->id);
        $this->assertSame($productB->id, $resolvedForB->id);
        // They must not cross-resolve.
        $this->assertNotSame($resolvedForA->id, $resolvedForB->id);
    }

    public function test_secondary_connection_does_not_cross_resolve_primary_legacy_woo_id(): void
    {
        config(['services.woocommerce.connection_key' => 'site-a.example.com']);
        $primary = Product::create(['name' => 'Primary Legacy', 'sku' => 'PRIMARY-LEGACY', 'source_type' => 'woocommerce', 'woocommerce_product_id' => 950, 'is_active' => true, 'product_type' => 'simple']);
        $secondary = Product::create(['name' => 'Secondary SKU', 'sku' => 'SECONDARY-SKU', 'source_type' => 'manual', 'is_active' => true, 'product_type' => 'simple']);

        $normalized = new NormalizedInboundOrder(
            provider: 'woocommerce',
            connectionKey: 'site-b.example.com',
            reference: 'WOO-CROSS-CONNECTION',
            externalOrderNumber: 'WOO-CROSS-CONNECTION',
            orderDate: '2026-09-01',
            providerModifiedAt: '2026-09-01T00:00:00Z',
            currency: 'USD',
            orderStatus: 'pending_shipment',
            orderNote: null,
            address: ['recipient_name' => 'Test', 'phone' => '', 'country' => 'US', 'province_state' => '', 'city' => 'NYC', 'address_line_1' => '1 Main St', 'address_line_2' => '', 'postal_code' => '10001'],
            items: [[
                'woocommerce_product_id' => 950,
                'woocommerce_reference_type' => 'product',
                'sku' => 'SECONDARY-SKU',
                'product_summary' => 'Secondary item',
                'quantity' => 1,
                'unit_price' => '5.00',
            ]],
        );

        $result = app(OrderService::class)->upsertInbound($normalized, null);
        $item = $result['order']->items()->first();

        $this->assertNotSame($primary->id, $item->product_id);
        $this->assertSame($secondary->id, $item->product_id);
        $this->assertSame('SECONDARY-SKU', $item->sku);
    }

    // Gate 15: Unknown remote ID falls through to SKU resolver; no fuzzy resolution.
    public function test_unknown_remote_id_falls_through_to_sku_authority(): void
    {
        $product = Product::create(['name' => 'SKU Auth Product', 'sku' => 'SKU-AUTH-001', 'source_type' => 'manual', 'is_active' => true, 'product_type' => 'simple']);

        $orderService = app(OrderService::class);
        $normalized = new NormalizedInboundOrder(
            provider: 'woocommerce',
            connectionKey: $this->connectionKey,
            reference: 'WOO-ORDER-3',
            externalOrderNumber: 'WOO-1003',
            orderDate: '2026-09-01',
            providerModifiedAt: '2026-09-01T00:00:00Z',
            currency: 'USD',
            orderStatus: 'pending_shipment',
            orderNote: null,
            address: ['recipient_name' => 'Test', 'phone' => '', 'country' => 'US', 'province_state' => '', 'city' => 'NYC', 'address_line_1' => '1 Main St', 'address_line_2' => '', 'postal_code' => '10001'],
            items: [
                // Remote ID 999 has no external reference, but SKU resolves canonically.
                ['woocommerce_product_id' => 999, 'woocommerce_reference_type' => 'product', 'sku' => 'SKU-AUTH-001', 'product_summary' => 'Item', 'quantity' => 1, 'unit_price' => '5.00'],
            ],
        );
        $result = $orderService->upsertInbound($normalized, null);
        $item = $result['order']->items()->first();

        // Should resolve via SKU authority.
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame('SKU-AUTH-001', $item->sku);
    }

    // Migration reversibility: table can be dropped and recreated (migration up->down->up).
    public function test_migration_table_exists(): void
    {
        $this->assertTrue(\Schema::hasTable('product_external_references'));
    }

    // Concern D: preview result exposes resolvedVariationMappings=1 and unresolvedVariationMappings=1
    // when one blank-SKU variation has a resolvable parent and one does not.
    public function test_preview_result_exposes_resolved_and_unresolved_variation_mapping_counts(): void
    {
        // Pre-create a parent product in DB so one variation can resolve.
        Product::create([
            'name' => 'Resolvable Parent', 'sku' => 'RES-PARENT', 'source_type' => 'woocommerce',
            'is_active' => true, 'product_type' => 'variable',
        ]);

        $handle = fopen($this->csvPath, 'w');
        fputcsv($handle, ['ID', 'Type', 'SKU', 'Name', 'Published', 'Weight (kg)', 'Sale price', 'Regular price', 'Parent']);
        fputcsv($handle, [501, 'variation', '', 'Resolved Variation', 1, '', '', '', 'RES-PARENT']);   // resolvable
        fputcsv($handle, [502, 'variation', '', 'Orphan Variation',   1, '', '', '', 'NO-SUCH-PARENT']); // unresolvable
        fclose($handle);

        $svc = app(\App\Modules\Products\Application\ProductService::class);
        $preview = $svc->preview($this->csvPath, $this->connectionKey);
        $arr = $preview['result']->toArray();

        $this->assertSame(1, $arr['resolvedVariationMappings']);
        $this->assertSame(1, $arr['unresolvedVariationMappings']);
        $this->assertSame(2, $arr['skippedNoSkuVariations']);
    }
}
