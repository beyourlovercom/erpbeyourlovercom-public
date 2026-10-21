<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductMedia;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

final class ProductGridExportTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(array $permissions = ['products.view']): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'grid-'.uniqid(), 'label' => 'Product Grid']);
        foreach ($permissions as $key) {
            $role->permissions()->firstOrCreate(['key' => $key], ['label' => $key]);
        }
        $user->roles()->attach($role);

        return $user;
    }

    public function test_product_grid_pages_support_20_50_100_and_all_without_losing_search(): void
    {
        $user = $this->viewer();
        for ($i = 0; $i < 115; $i++) {
            Product::create([
                'source_type' => 'manual',
                'product_type' => 'simple',
                'sku' => sprintf('GRID-%03d', $i),
                'name' => sprintf('Grid item %03d', $i),
            ]);
        }

        foreach ([20 => 20, 50 => 50, 100 => 100, 'all' => 115] as $query => $size) {
            $this->actingAs($user)->get('/products/list?per_page='.$query)
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Products/Index')
                    ->has('products.data', $size)
                    ->where('products.total', 115));
        }

        $this->actingAs($user)->get('/products/list?per_page=all&search=GRID-001')
            ->assertInertia(fn ($page) => $page->has('products.data', 1)->where('products.total', 1));

        $this->actingAs($user)->get('/products/list?per_page=1')
            ->assertInertia(fn ($page) => $page->has('products.data', 50));
    }

    public function test_grid_projects_only_real_values_and_preserves_unknown_fields_as_null(): void
    {
        $user = $this->viewer();
        Product::create([
            'source_type' => 'woocommerce', 'product_type' => 'simple',
            'sku' => 'PROJ-001', 'name' => 'Verified Product', 'is_active' => true,
            'regular_price' => 199, 'sale_price' => 109,
            'length_cm' => 12, 'width_cm' => 6, 'height_cm' => 4,
        ]);

        $this->actingAs($user)->get('/products/list?search=PROJ-001')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.data.0.sku', 'PROJ-001')
                ->where('products.data.0.regular_price', '199.00')
                ->where('products.data.0.sale_price', '109.00')
                ->where('products.data.0.dimensions_cm', '12.00×6.00×4.00')
                ->where('products.data.0.units_per_carton', null)
                ->where('products.data.0.available_stock', null)
                ->where('products.data.0.cnf', null)
                ->where('products.data.0.material_name', null)
                ->where('products.data.0.packaging_dimensions', null)
                ->where('products.data.0.brand', null)
            );
    }

    public function test_view_only_role_can_download_real_xlsx_and_formula_like_titles_remain_plain_strings(): void
    {
        $user = $this->viewer();
        Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'SAFE-1', 'name' => '=HYPERLINK("https://invalid.test","unsafe")']);
        Product::create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => 'SAFE-2', 'name' => 'Not selected']);

        $response = $this->actingAs($user)->get('/products/list/export?search=SAFE-1&columns=sku,name,regular_price');
        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $tmp = tempnam(sys_get_temp_dir(), 'products-xlsx-');
        try {
            file_put_contents($tmp, $response->streamedContent());
            $workbook = IOFactory::load($tmp);
            $sheet = $workbook->getActiveSheet();
            $this->assertSame('SKU', $sheet->getCell('A1')->getValue());
            $this->assertSame('产品名称', $sheet->getCell('B1')->getValue());
            $this->assertSame('SAFE-1', $sheet->getCell('A2')->getValue());
            $this->assertSame('=HYPERLINK("https://invalid.test","unsafe")', $sheet->getCell('B2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
            $this->assertSame('—', $sheet->getCell('C2')->getValue());
            $this->assertSame(2, $sheet->getHighestRow());
            $workbook->disconnectWorksheets();
        } finally {
            @unlink($tmp);
        }
    }

    public function test_export_requires_products_view_and_cannot_write_product_data(): void
    {
        $noPermission = $this->viewer(['dashboard.view']);
        $this->actingAs($noPermission)->get('/products/list/export')->assertForbidden();

        $viewer = $this->viewer();
        Product::create(['sku' => 'READ-ONLY-001', 'name' => 'Stay Unchanged', 'source_type' => 'manual', 'product_type' => 'simple']);
        $this->actingAs($viewer)->get('/products/list/export?columns=sku,name')->assertOk();
        $this->assertDatabaseHas('products', ['sku' => 'READ-ONLY-001', 'name' => 'Stay Unchanged']);
    }

    public function test_grid_uses_verified_woo_cdn_when_local_media_file_is_missing(): void
    {
        config(['services.woocommerce.connection_key' => 'beyourlover.com']);
        $viewer = $this->viewer();
        $product = Product::create([
            'source_type' => 'woocommerce', 'product_type' => 'simple',
            'sku' => 'MEDIA-FALLBACK-1', 'name' => 'Media fallback',
        ]);
        $media = ProductMedia::create([
            'product_id' => $product->id,
            'provider' => 'woocommerce',
            'connection_key' => 'beyourlover.com',
            'provider_media_key' => 'media-fallback-1',
            'source_url' => 'https://img.beyourlover.com/products/verified.webp',
            'storage_path' => 'product-media/nonexistent-verified.webp',
            'mime_type' => 'image/webp',
            'size_bytes' => 1234,
            'sha256' => str_repeat('a', 64),
            'is_primary' => true,
        ]);

        $this->actingAs($viewer)->get('/products/list?search=MEDIA-FALLBACK-1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.data.0.image_url', 'https://img.beyourlover.com/products/verified.webp'));

        $media->update(['source_url' => 'https://img.beyourlover.com/products/首图.png']);
        $this->actingAs($viewer)->get('/products/list?search=MEDIA-FALLBACK-1')
            ->assertInertia(fn ($page) => $page
                ->where('products.data.0.image_url', 'https://img.beyourlover.com/products/%E9%A6%96%E5%9B%BE.png'));
    }

    public function test_grid_prefers_existing_local_image_and_rejects_non_brand_remote_image(): void
    {
        config(['services.woocommerce.connection_key' => 'beyourlover.com']);
        Storage::fake('local');
        $viewer = $this->viewer();
        $product = Product::create([
            'source_type' => 'woocommerce', 'product_type' => 'simple',
            'sku' => 'MEDIA-LOCAL-1', 'name' => 'Local media',
        ]);
        Storage::disk('local')->put('products/local-grid.png', 'test-image');
        $media = ProductMedia::create([
            'product_id' => $product->id,
            'provider' => 'woocommerce',
            'connection_key' => 'beyourlover.com',
            'provider_media_key' => 'media-local-1',
            'source_url' => 'https://img.beyourlover.com/products/local.png',
            'storage_path' => 'products/local-grid.png',
            'mime_type' => 'image/png',
            'size_bytes' => 10,
            'sha256' => str_repeat('b', 64),
            'is_primary' => true,
        ]);
        $this->actingAs($viewer)->get('/products/list?search=MEDIA-LOCAL-1')
            ->assertInertia(fn ($page) => $page
                ->where('products.data.0.image_url', route('products.media.show', $media->id)));

        $media->update([
            'storage_path' => 'products/missing.png',
            'source_url' => 'https://untrusted.invalid/pixel.png',
        ]);
        $this->actingAs($viewer)->get('/products/list?search=MEDIA-LOCAL-1')
            ->assertInertia(fn ($page) => $page->where('products.data.0.image_url', null));
    }


}
