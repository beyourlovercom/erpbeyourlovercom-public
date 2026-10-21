<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Application\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'test-role-'.uniqid(), 'label' => 'Test Role']);
        foreach ($permissions as $perm) {
            $role->permissions()->firstOrCreate(['key' => $perm], ['label' => $perm]);
        }
        $user->roles()->attach($role);

        return $user;
    }

    // ── Authorization regression: view-only cannot mutate ─────────────────────

    public function test_view_only_cannot_access_create_page(): void
    {
        $user = $this->userWith(['products.view']);
        $this->actingAs($user)->get('/products/create')->assertForbidden();
    }

    public function test_view_only_cannot_store_product(): void
    {
        $user = $this->userWith(['products.view']);
        $this->actingAs($user)->post('/products', ['sku' => 'DENY-001', 'name' => 'Denied'])->assertForbidden();
        $this->assertDatabaseMissing('products', ['sku' => 'DENY-001']);
    }

    public function test_view_only_cannot_access_edit_page(): void
    {
        $product = Product::create(['source_type' => 'manual', 'woocommerce_product_id' => null, 'sku' => 'VIEW-EDIT', 'name' => 'View Edit', 'product_type' => 'simple']);

        $user = $this->userWith(['products.view']);
        $this->actingAs($user)->get("/products/{$product->id}/edit")->assertForbidden();
    }

    public function test_view_only_cannot_update_product(): void
    {
        $product = Product::create(['source_type' => 'manual', 'woocommerce_product_id' => null, 'sku' => 'VIEW-UPD', 'name' => 'Original', 'product_type' => 'simple']);

        $user = $this->userWith(['products.view']);
        $this->actingAs($user)->patch("/products/{$product->id}", ['name' => 'Mutated'])->assertForbidden();

        $product->refresh();
        $this->assertSame('Original', $product->name);
    }

    public function test_view_only_cannot_delete_product(): void
    {
        $product = Product::create(['source_type' => 'manual', 'woocommerce_product_id' => null, 'sku' => 'VIEW-DEL', 'name' => 'Keep Me', 'product_type' => 'simple']);

        $user = $this->userWith(['products.view']);
        $this->actingAs($user)->delete("/products/{$product->id}")->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_view_only_cannot_access_import_page(): void
    {
        $user = $this->userWith(['products.view']);
        $this->actingAs($user)->get('/products/import')->assertForbidden();
    }

    public function test_view_only_cannot_import_csv(): void
    {
        $user = $this->userWith(['products.view']);
        $this->actingAs($user)->post('/products/import', [])->assertForbidden();
    }

    public function test_view_only_can_access_product_list(): void
    {
        $user = $this->userWith(['products.view']);
        $this->actingAs($user)->get('/products/list')->assertOk();
    }

    public function test_view_only_can_access_sku_search(): void
    {
        $user = $this->userWith(['products.view']);
        $this->actingAs($user)->get('/products/sku-search?q=test')->assertOk();
    }

    public function test_manual_order_creator_can_search_skus_without_full_product_access(): void
    {
        $user = $this->userWith(['orders.create']);
        Product::create(['source_type' => 'manual', 'sku' => 'KOL-LOOKUP-001', 'name' => 'KOL Lookup', 'product_type' => 'simple']);

        $this->actingAs($user)->getJson('/products/sku-search?q=KOL-LOOKUP')
            ->assertOk()
            ->assertJsonFragment(['sku' => 'KOL-LOOKUP-001']);
        $this->actingAs($user)->get('/products/list')->assertForbidden();
        $this->actingAs($user)->get('/products/create')->assertForbidden();
    }

    public function test_users_without_product_or_order_create_permission_cannot_query_skus(): void
    {
        $user = $this->userWith(['dashboard.view']);
        $this->actingAs($user)->getJson('/products/sku-search?q=KOL')->assertForbidden();
    }

    public function test_product_view_and_sync_permission_does_not_allow_woocommerce_product_mutation(): void
    {
        $user = $this->userWith(['products.view', 'sync.operate']);
        Product::create(['source_type' => 'manual', 'sku' => 'NO-PRODUCT-SYNC', 'name' => 'Not Syncable', 'product_type' => 'simple']);
        $product = Product::query()->where('sku', 'NO-PRODUCT-SYNC')->firstOrFail();

        $this->actingAs($user)->post("/products/{$product->id}/sync-woocommerce")->assertForbidden();
    }

    public function test_empty_sku_search_returns_default_sku_ascending_options(): void
    {
        $user = $this->userWith(['products.view']);
        Product::create(['woocommerce_product_id' => 9101, 'sku' => 'B200', 'name' => 'Beta', 'product_type' => 'simple']);
        Product::create(['woocommerce_product_id' => 9102, 'sku' => 'A100', 'name' => 'Alpha', 'product_type' => 'simple']);
        Product::create(['woocommerce_product_id' => 9103, 'sku' => null, 'name' => 'No SKU', 'product_type' => 'simple']);

        $response = $this->actingAs($user)->getJson('/products/sku-search');

        $response->assertOk();
        $this->assertSame(['A100', 'B200'], array_column($response->json(), 'sku'));
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_create_page_is_accessible(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($user)->get('/products/create')->assertOk();
    }

    public function test_manual_create_stores_product_with_source_type_manual(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $response = $this->actingAs($user)->post('/products', [
            'sku' => 'MAN-001',
            'name' => 'Manual Product',
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('products', [
            'sku' => 'MAN-001',
            'name' => 'Manual Product',
            'source_type' => 'manual',
        ]);
        $product = Product::where('sku', 'MAN-001')->firstOrFail();
        $this->assertNull($product->woocommerce_product_id);
    }

    public function test_create_requires_sku(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $response = $this->actingAs($user)->post('/products', [
            'name' => 'Product Without SKU',
        ]);
        $response->assertSessionHasErrors(['sku']);
    }

    public function test_create_requires_name(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $response = $this->actingAs($user)->post('/products', [
            'sku' => 'NO-NAME-SKU',
        ]);
        $response->assertSessionHasErrors(['name']);
    }

    public function test_create_sku_must_be_unique(): void
    {
        Product::create(['sku' => 'DUP-SKU', 'name' => 'Existing', 'product_type' => 'simple', 'source_type' => 'manual', 'woocommerce_product_id' => null]);

        $user = $this->userWith(['products.view', 'products.operate']);
        $response = $this->actingAs($user)->post('/products', [
            'sku' => 'DUP-SKU',
            'name' => 'Duplicate SKU',
        ]);
        $response->assertSessionHasErrors(['sku']);
    }

    // ── Edit ─────────────────────────────────────────────────────────────────

    public function test_edit_page_is_accessible(): void
    {
        $product = Product::create(['woocommerce_product_id' => 1001, 'sku' => 'EDIT-SKU', 'name' => 'Edit Me', 'product_type' => 'simple', 'source_type' => 'woocommerce']);

        $user = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($user)->get("/products/{$product->id}/edit")->assertOk();
    }

    public function test_edit_updates_product_fields(): void
    {
        $product = Product::create(['woocommerce_product_id' => 1002, 'sku' => 'UPD-SKU', 'name' => 'Original', 'product_type' => 'simple', 'source_type' => 'woocommerce']);

        $user = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($user)->patch("/products/{$product->id}", [
            'sku' => 'UPD-SKU',
            'name' => 'Updated Name',
            'regular_price' => '99.00',
        ])->assertRedirect();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Updated Name', 'regular_price' => '99.00']);
    }

    public function test_edit_cannot_change_source_type_via_update(): void
    {
        $product = Product::create(['woocommerce_product_id' => 1003, 'sku' => 'SRC-SKU', 'name' => 'Woo Product', 'product_type' => 'simple', 'source_type' => 'woocommerce']);

        $user = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($user)->patch("/products/{$product->id}", [
            'name' => 'Updated',
            'source_type' => 'manual',
        ])->assertRedirect();

        $product->refresh();
        $this->assertSame('woocommerce', $product->source_type);
    }

    public function test_edit_sku_unique_ignores_self(): void
    {
        $product = Product::create(['source_type' => 'manual', 'woocommerce_product_id' => null, 'sku' => 'SELF-SKU', 'name' => 'Self', 'product_type' => 'simple']);

        $user = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($user)->patch("/products/{$product->id}", [
            'sku' => 'SELF-SKU',
            'name' => 'Self Updated',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_delete_removes_product(): void
    {
        $product = Product::create(['source_type' => 'manual', 'woocommerce_product_id' => null, 'sku' => 'DEL-001', 'name' => 'Delete Me', 'product_type' => 'simple']);

        $user = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($user)->delete("/products/{$product->id}")->assertRedirect('/products/list');

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_delete_does_not_delete_historical_order_item_snapshot(): void
    {
        $product = Product::create(['woocommerce_product_id' => 5001, 'sku' => 'HIST-DEL', 'name' => 'Historical Product', 'product_type' => 'simple', 'source_type' => 'woocommerce']);

        $order = Order::create([
            'external_order_number' => 'HIST-DEL-001',
            'internal_order_number' => 'MAN-20260824-HISTD01',
            'order_date' => now()->toDateString(),
            'order_kind' => 'customer',
            'source_type' => 'manual',
            'order_status' => 'pending_shipment',
            'owner_id' => null,
        ]);
        $item = $order->items()->create([
            'product_summary' => 'Historical Product',
            'sku' => 'HIST-DEL',
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => '50.00',
            'line_revenue' => '100.00',
        ]);

        $user = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($user)->delete("/products/{$product->id}")->assertRedirect();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);

        $item->refresh();
        $this->assertNull($item->product_id, 'FK should be null after product delete (nullOnDelete)');
        $this->assertSame('Historical Product', $item->product_summary, 'Historical snapshot must remain intact');
        $this->assertSame('HIST-DEL', $item->sku, 'Historical SKU snapshot must remain intact');
    }

    // ── source_type semantics ─────────────────────────────────────────────────

    public function test_imported_woo_product_has_source_type_woocommerce(): void
    {
        $product = Product::create([
            'woocommerce_product_id' => 9999,
            'sku' => 'WOO-SKU',
            'name' => 'Woo Product',
            'product_type' => 'simple',
            'source_type' => 'woocommerce',
        ]);
        $this->assertSame('woocommerce', $product->source_type);
        $this->assertNotNull($product->woocommerce_product_id);
    }

    public function test_manual_product_has_null_woocommerce_product_id(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($user)->post('/products', ['sku' => 'NULL-WOO', 'name' => 'Manual']);

        $product = Product::where('sku', 'NULL-WOO')->firstOrFail();
        $this->assertNull($product->woocommerce_product_id);
        $this->assertSame('manual', $product->source_type);
    }

    // ── CSV Import UI ─────────────────────────────────────────────────────────

    public function test_import_page_is_accessible(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($user)->get('/products/import')->assertOk();
    }

    public function test_import_store_requires_csv_file(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($user)->post('/products/import', [])->assertSessionHasErrors(['csv_file']);
    }

    public function test_import_template_uses_fixed_filename_and_standard_header(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);

        $response = $this->actingAs($user)->get('/products/import/template');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="product-import-csv-template.csv"');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->getContent());
        $this->assertStringContainsString('"ID","Type","SKU","GTIN, UPC, EAN, or ISBN","Name","Weight (kg)","Sale price","Regular price","Published","Parent"', $response->getContent());
    }

    public function test_import_store_previews_then_confirms_csv_without_mutating_during_preview(): void
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) mkdir($directory, 0777, true);
        $csv = tempnam($directory, 'woo_ui_test_').'.csv';
        $handle = fopen($csv, 'w');
        fputcsv($handle, ['ID', 'Type', 'SKU', 'GTIN, UPC, EAN, or ISBN', 'Name', 'Weight (kg)', 'Sale price', 'Regular price', 'Published']);
        fputcsv($handle, [2001, 'simple', 'UI-SKU', '', 'UI Import Product', '', 59, 89, 1]);
        fclose($handle);

        $user = $this->userWith(['products.view', 'products.operate']);
        $preview = $this->actingAs($user)->post('/products/import', [
            'csv_file' => new \Illuminate\Http\UploadedFile($csv, 'products.csv', 'text/csv', null, true),
        ]);

        $preview->assertOk();
        $this->assertDatabaseMissing('products', ['sku' => 'UI-SKU']);
        $preview->assertInertia(fn ($page) => $page->where('result.candidates', 1)->where('result.canConfirm', true)->has('previewToken'));

        unlink($csv);
    }

    public function test_confirm_request_cannot_bypass_a_conflicted_preview_token(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $token = 'conflicted-preview-token';
        $candidate = [
            'woocommerce_product_id' => 2999,
            'source_type' => 'woocommerce',
            'product_type' => 'simple',
            'sku' => 'SHOULD-NOT-IMPORT',
            'gtin' => null,
            'name' => 'Blocked Candidate',
            'sale_price' => 10.0,
            'regular_price' => 12.0,
            'is_active' => true,
            'weight_kg' => null,
        ];

        $response = $this->actingAs($user)
            ->withSession([
                "products.import.{$token}" => [
                    'user_id' => (string) $user->id,
                    'candidates' => [$candidate],
                    'can_confirm' => false,
                    'result' => ['identityConflicts' => 1, 'invalid' => 0, 'errors' => ['conflict'], 'canConfirm' => false],
                ],
            ])
            ->post('/products/import', ['action' => 'confirm', 'preview_token' => $token]);

        $response->assertOk()->assertInertia(fn ($page) => $page->where('result.canConfirm', false));
        $this->assertDatabaseMissing('products', ['sku' => 'SHOULD-NOT-IMPORT']);
    }

    // ── Woo CSV ownership boundary ────────────────────────────────────────────

    public function test_reimport_preserves_erp_maintained_accounting_and_logistics_fields(): void
    {
        $product = Product::create([
            'woocommerce_product_id' => 3001,
            'source_type' => 'woocommerce',
            'product_type' => 'simple',
            'sku' => 'WOO-ACCT',
            'name' => 'Original Name',
            'unit' => 'pcs',
            'is_active' => true,
            'weight_kg' => null,
            'sale_price' => '100.00',
            'regular_price' => '120.00',
            'shipping_weight_kg' => '0.5000',
            'length_cm' => '10.00',
            'width_cm' => '5.00',
            'height_cm' => '3.00',
            'country_of_origin' => 'CN',
            'hs_code' => '8471300000',
            'standard_production_cost' => '25.0000',
            'standard_packaging_cost' => '3.5000',
            'standard_purchase_cost' => null,
            'cost_currency' => 'CNY',
            'cost_note' => 'Set by ERP',
            'notes' => 'Internal note',
        ]);

        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) mkdir($directory, 0777, true);
        $csv = tempnam($directory, 'woo_reimport_').'.csv';
        $handle = fopen($csv, 'w');
        fputcsv($handle, ['ID', 'Type', 'SKU', 'GTIN, UPC, EAN, or ISBN', 'Name', 'Weight (kg)', 'Sale price', 'Regular price', 'Published']);
        fputcsv($handle, [3001, 'simple', 'WOO-ACCT', '', 'Updated Name From Woo', '0.8', 110, 130, 1]);
        fclose($handle);

        $preview = app(ProductService::class)->preview($csv);
        app(ProductService::class)->confirm($preview['candidates']);

        unlink($csv);

        $product->refresh();

        $this->assertSame('Updated Name From Woo', $product->name, 'CSV name should update');
        $this->assertSame('110.00', $product->sale_price, 'CSV sale_price should update');
        $this->assertSame('130.00', $product->regular_price, 'CSV regular_price should update');

        $this->assertSame('pcs', $product->unit, 'ERP unit must not be erased by CSV');
        $this->assertTrue($product->is_active, 'ERP is_active must not be erased by CSV');
        $this->assertSame('0.5000', $product->shipping_weight_kg, 'ERP shipping_weight_kg must not be erased by CSV');
        $this->assertSame('10.00', $product->length_cm, 'ERP length_cm must not be erased by CSV');
        $this->assertSame('5.00', $product->width_cm, 'ERP width_cm must not be erased by CSV');
        $this->assertSame('3.00', $product->height_cm, 'ERP height_cm must not be erased by CSV');
        $this->assertSame('CN', $product->country_of_origin, 'ERP country_of_origin must not be erased by CSV');
        $this->assertSame('8471300000', $product->hs_code, 'ERP hs_code must not be erased by CSV');
        $this->assertSame('25.0000', $product->standard_production_cost, 'ERP standard_production_cost must not be erased by CSV');
        $this->assertSame('3.5000', $product->standard_packaging_cost, 'ERP standard_packaging_cost must not be erased by CSV');
        $this->assertNull($product->standard_purchase_cost, 'ERP standard_purchase_cost null must not be changed');
        $this->assertSame('CNY', $product->cost_currency, 'ERP cost_currency must not be erased by CSV');
        $this->assertSame('Set by ERP', $product->cost_note, 'ERP cost_note must not be erased by CSV');
        $this->assertSame('Internal note', $product->notes, 'ERP notes must not be erased by CSV');
    }

    // ── Accounting/logistics fields ───────────────────────────────────────────

    public function test_create_with_accounting_fields_persists_correctly(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $this->actingAs($user)->post('/products', [
            'sku' => 'ACCT-001',
            'name' => 'Accounting Product',
            'unit' => 'pcs',
            'is_active' => true,
            'shipping_weight_kg' => '0.35',
            'country_of_origin' => 'CN',
            'hs_code' => '1234567890',
            'standard_production_cost' => '18.5',
            'standard_packaging_cost' => '2.0',
            'cost_currency' => 'CNY',
            'cost_note' => 'Verified Q1',
        ])->assertRedirect();

        $this->assertDatabaseHas('products', [
            'sku' => 'ACCT-001',
            'unit' => 'pcs',
            'country_of_origin' => 'CN',
            'hs_code' => '1234567890',
            'cost_currency' => 'CNY',
        ]);
        $product = Product::where('sku', 'ACCT-001')->firstOrFail();
        $this->assertTrue($product->is_active);
        $this->assertSame('0.3500', $product->shipping_weight_kg);
        $this->assertSame('18.5000', $product->standard_production_cost);
        $this->assertSame('2.0000', $product->standard_packaging_cost);
        $this->assertSame('Verified Q1', $product->cost_note);
    }
}
