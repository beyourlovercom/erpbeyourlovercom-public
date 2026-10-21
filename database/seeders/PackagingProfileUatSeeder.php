<?php

namespace Database\Seeders;

use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use Illuminate\Database\Seeder;

final class PackagingProfileUatSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 500, 'Packaging profile UAT seed is local/testing only.');
        $product = Product::query()->firstOrCreate(['sku'=>'PKG-UAT-001'], ['name'=>'压缩枕头 / Compressed Pillow','is_active'=>true]);
        $product->forceFill(['name'=>'压缩枕头 / Compressed Pillow','is_active'=>true])->save();
        ProductPackagingProfile::query()->updateOrCreate(
            ['product_id'=>$product->id],
            ['preset_key'=>'bubble_wrap_cut','custom_method'=>null,'scenario'=>'中国工厂打包 / 仓库出库','version'=>'UAT-v3','custom_materials'=>null,'custom_steps'=>null,'custom_tips'=>null,'is_active'=>true],
        );
    }
}
