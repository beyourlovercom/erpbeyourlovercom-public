<?php

namespace Tests\Feature\Inventory;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfileMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PackagingMeasurementEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_can_create_and_submit_only_complete_evidence(): void
    {
        Storage::fake('local');
        $factory = $this->userWith(['inventory.packaging.submit']);
        $reviewer = $this->userWith(['inventory.packaging.review']);
        $product = Product::create(['name'=>'Factory Product','sku'=>'FACT-001']);
        $this->actingAs($factory)->get('/inventory')->assertForbidden();
        $this->actingAs($factory)->get('/products')->assertForbidden();
        $this->actingAs($factory)->post('/inventory/packaging', ['product_id'=>$product->id])->assertRedirect();
        $record = PackagingMeasurementRecord::firstOrFail();
        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/submit", [])->assertSessionHasErrors(['packaging_length_cm','packaging_width_cm','packaging_height_cm','packaged_weight_kg','measured_at']);
        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/media", ['kind'=>'measurement','files'=>[UploadedFile::fake()->image('measurement.jpg')]])->assertRedirect();
        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/media", ['kind'=>'weight','files'=>[UploadedFile::fake()->image('weight.png')]])->assertRedirect();
        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/submit", ['product_id'=>$product->id,'packaging_length_cm'=>'10','packaging_width_cm'=>'8','packaging_height_cm'=>'5','packaged_weight_kg'=>'0.250','measured_at'=>'2026-09-03 10:00:00'])->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('packaging_measurement_records', ['id'=>$record->id,'status'=>'submitted','product_id'=>$product->id,'packaging_method_key'=>null]);
        $this->assertNull(Product::find($product->id)->weight_kg);
        $this->assertSame(1, $reviewer->fresh()->unreadNotifications()->count());
        $this->assertSame('新的包装测量待审核', $reviewer->fresh()->unreadNotifications()->firstOrFail()->data['title']);
        $this->assertSame(0, $factory->fresh()->unreadNotifications()->count());
        Storage::disk('local')->assertExists($record->media()->where('kind','measurement')->firstOrFail()->path);
    }

    public function test_factory_login_lands_on_packaging_workspace_without_dashboard_permission(): void
    {
        $factory = $this->userWith(['inventory.packaging.submit']);
        $factory->forceFill(['email' => 'factory-login@example.test', 'password' => bcrypt('password')])->save();

        $this->post('/login', ['email' => 'factory-login@example.test', 'password' => 'password'])
            ->assertRedirect('/inventory/packaging');
        $this->assertAuthenticatedAs($factory);
    }

    public function test_invalid_media_is_rejected_and_submitter_cannot_read_another_record(): void
    {
        Storage::fake('local');
        $one = $this->userWith(['inventory.packaging.submit']); $two = $this->userWith(['inventory.packaging.submit']);
        $product = Product::create(['name'=>'Private Product','sku'=>'PRIVATE-001']);
        $record = PackagingMeasurementRecord::create(['product_id'=>$product->id,'status'=>'draft','created_by'=>$one->id]);
        $this->actingAs($one)->post("/inventory/packaging/{$record->id}/media", ['kind'=>'packaging','files'=>[UploadedFile::fake()->create('bad.svg', 2, 'image/svg+xml')]])->assertSessionHasErrors('files.0');
        $this->actingAs($two)->get("/inventory/packaging/{$record->id}")->assertForbidden();
    }

    public function test_reviewer_can_approve_but_history_cannot_be_mutated(): void
    {
        $submitter = $this->userWith(['inventory.packaging.submit']); $reviewer = $this->userWith(['inventory.packaging.review']);
        $product = Product::create(['name'=>'Review Product','sku'=>'REVIEW-001']);
        $record = PackagingMeasurementRecord::create(['product_id'=>$product->id,'status'=>'submitted','created_by'=>$submitter->id,'submitted_at'=>now(),'packaging_length_cm'=>1,'packaging_width_cm'=>2,'packaging_height_cm'=>3,'packaged_weight_kg'=>'0.100','measured_at'=>now()]);
        $this->actingAs($reviewer)->post("/inventory/packaging/{$record->id}/review", ['decision'=>'approved'])->assertRedirect();
        $this->assertDatabaseHas('packaging_measurement_records', ['id'=>$record->id,'status'=>'approved','reviewed_by'=>$reviewer->id]);
        $this->assertSame(1, $submitter->fresh()->unreadNotifications()->count());
        $this->assertSame('包装测量已批准', $submitter->fresh()->unreadNotifications()->firstOrFail()->data['title']);
        $this->actingAs($submitter)->patch("/inventory/packaging/{$record->id}", ['product_id'=>$product->id])->assertForbidden();
    }

    public function test_record_detail_exposes_only_its_products_active_packaging_sop(): void
    {
        $factory = $this->userWith(['inventory.packaging.submit']);
        $product = Product::create(['name'=>'Compressed Pillow','sku'=>'SOP-001']);
        $other = Product::create(['name'=>'Other Product','sku'=>'SOP-002']);
        ProductPackagingProfile::create([
            'product_id'=>$product->id,
            'preset_key'=>null,
            'custom_method'=>'气泡袋包裹',
            'custom_materials'=>['气泡袋','胶带','标签'],
            'custom_steps'=>[['position'=>1,'title'=>'准备材料','instruction'=>'准备材料']],
            'custom_tips'=>['接口处加固'],
            'is_active'=>true,
        ]);
        $record = PackagingMeasurementRecord::create(['product_id'=>$product->id,'status'=>'draft','packaging_method_key'=>'custom','created_by'=>$factory->id]);
        $otherRecord = PackagingMeasurementRecord::create(['product_id'=>$other->id,'status'=>'draft','created_by'=>$factory->id]);

        $this->actingAs($factory)->get("/inventory/packaging/{$record->id}")->assertInertia(fn (Assert $page) => $page
            ->where('packagingSop.method', '气泡袋包裹')
            ->has('packagingSop.materials', 3)
            ->has('packagingSop.steps', 1));
        $this->actingAs($factory)->get("/inventory/packaging/{$otherRecord->id}")->assertInertia(fn (Assert $page) => $page
            ->where('packagingSop', null));
    }

    public function test_factory_guide_exposes_presets_and_record_detail_uses_selected_method(): void
    {
        $factory = $this->userWith(['inventory.packaging.submit']);
        $product = Product::create(['name'=>'Guide Product','sku'=>'GUIDE-001']);
        ProductPackagingProfile::create([
            'product_id'=>$product->id,
            'preset_key'=>'bubble_wrap_cut',
            'is_active'=>true,
        ]);

        $this->actingAs($factory)->get("/inventory/packaging/product/{$product->id}/guide")
            ->assertOk()
            ->assertJsonPath('packaging_sop.preset_key', 'bubble_wrap_cut')
            ->assertJsonCount(4, 'presets');

        $record = PackagingMeasurementRecord::create([
            'product_id'=>$product->id,
            'status'=>'draft',
            'packaging_method_key'=>'carton',
            'created_by'=>$factory->id,
        ]);

        $this->actingAs($factory)->get("/inventory/packaging/{$record->id}")->assertInertia(fn (Assert $page) => $page
            ->where('record.packaging_method_key', 'carton')
            ->where('packagingSop.method', '加纸箱')
            ->where('packagingSop.rendered_illustration_url', null));
    }

    public function test_packaging_reference_never_blocks_measurement_submission(): void
    {
        Storage::fake('local');
        $factory = $this->userWith(['inventory.packaging.submit']);
        $product = Product::create(['name'=>'Reference Product','sku'=>'REF-001']);
        $record = PackagingMeasurementRecord::create(['product_id'=>$product->id,'status'=>'draft','created_by'=>$factory->id]);
        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/media", ['kind'=>'measurement','files'=>[UploadedFile::fake()->image('measurement.jpg')]])->assertRedirect();
        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/media", ['kind'=>'weight','files'=>[UploadedFile::fake()->image('weight.jpg')]])->assertRedirect();
        $payload = ['product_id'=>$product->id,'packaging_method_key'=>'custom','packaging_length_cm'=>'10','packaging_width_cm'=>'8','packaging_height_cm'=>'5','packaged_weight_kg'=>'0.250','measured_at'=>'2026-09-04 09:00:00'];

        $this->actingAs($factory)->post("/inventory/packaging/{$record->id}/submit", $payload)
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('packaging_measurement_records', ['id'=>$record->id,'status'=>'submitted','packaging_method_key'=>'custom']);
    }

    public function test_product_operator_can_upload_private_packaging_reference_images_for_factory(): void
    {
        Storage::fake('local');
        $operator = $this->userWith(['products.operate']);
        $factory = $this->userWith(['inventory.packaging.submit']);
        $product = Product::create(['name'=>'Packaging Admin Product','sku'=>'PKG-ADMIN-001']);

        $this->actingAs($operator)->get("/products/{$product->id}/packaging")->assertOk();
        $this->actingAs($factory)->get("/products/{$product->id}/packaging")->assertForbidden();

        $this->actingAs($operator)->put("/products/{$product->id}/packaging", [
            'preset_key'=>'bubble_wrap_cut',
            'method'=>'气泡袋裁剪',
            'scenario'=>'工厂参考',
            'version'=>'v1',
            'units_per_carton'=>12,
        ])->assertRedirect();

        $this->actingAs($operator)->post("/products/{$product->id}/packaging/media", [
            'kind'=>'rendered',
            'files'=>[UploadedFile::fake()->image('hero.jpg')],
        ])->assertRedirect();
        $this->actingAs($operator)->post("/products/{$product->id}/packaging/media", [
            'kind'=>'example',
            'files'=>[UploadedFile::fake()->image('case-1.png'), UploadedFile::fake()->image('case-2.png')],
        ])->assertRedirect();

        $profile = ProductPackagingProfile::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertDatabaseHas('product_packaging_profiles', ['id'=>$profile->id,'preset_key'=>'bubble_wrap_cut','units_per_carton'=>12,'custom_steps'=>null,'custom_tips'=>null]);
        $this->assertDatabaseCount('product_packaging_profile_media', 3);
        $hero = ProductPackagingProfileMedia::query()->where('product_packaging_profile_id',$profile->id)->where('kind','rendered')->firstOrFail();
        Storage::disk('local')->assertExists($hero->path);

        $this->actingAs($factory)->get("/products/packaging/media/{$hero->id}")->assertOk();
        $this->actingAs($factory)->get("/inventory/packaging/product/{$product->id}/guide")
            ->assertOk()
            ->assertJsonPath('packaging_sop.preset_key', 'bubble_wrap_cut')
            ->assertJsonCount(2, 'packaging_sop.examples');
    }

    public function test_global_presets_are_shared_and_product_rows_store_only_overrides(): void
    {
        $factory = $this->userWith(['inventory.packaging.submit']);
        $product = Product::create(['name'=>'Shared Template Product','sku'=>'SHARED-001']);
        ProductPackagingProfile::create(['product_id'=>$product->id,'preset_key'=>'bubble_wrap_cut','is_active'=>true]);

        $this->assertDatabaseCount('product_packaging_profiles', 1);
        $this->assertDatabaseHas('product_packaging_profiles', ['product_id'=>$product->id,'preset_key'=>'bubble_wrap_cut','custom_materials'=>null,'custom_steps'=>null,'custom_tips'=>null]);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('packaging_presets'));

        config()->set('packaging_presets.bubble_wrap_cut.label', '气泡袋裁剪 · 全局更新');
        $this->actingAs($factory)->get("/inventory/packaging/product/{$product->id}/guide")
            ->assertOk()
            ->assertJsonCount(4, 'presets')
            ->assertJsonPath('packaging_sop.method', '气泡袋裁剪 · 全局更新')
            ->assertJsonCount(6, 'packaging_sop.steps');
    }


    public function test_reviewer_can_adopt_approved_measurement_as_current_product_packaging_standard(): void
    {
        $reviewer = $this->userWith(['inventory.packaging.review']);
        $product = Product::create(['name'=>'Standard Product','sku'=>'STANDARD-001']);
        $profile = ProductPackagingProfile::create(['product_id'=>$product->id,'preset_key'=>'bubble_wrap_cut','is_active'=>true]);
        $record = PackagingMeasurementRecord::create([
            'product_id'=>$product->id,'status'=>'approved','packaging_method_key'=>'bubble_wrap_cut',
            'packaging_length_cm'=>'32.00','packaging_width_cm'=>'18.00','packaging_height_cm'=>'12.00',
            'packaged_weight_kg'=>'0.850','measured_at'=>'2026-09-04 10:00:00','created_by'=>$reviewer->id,
            'reviewed_by'=>$reviewer->id,'reviewed_at'=>now(),
        ]);

        $this->actingAs($reviewer)->post("/inventory/packaging/{$record->id}/adopt-standard")->assertRedirect();
        $this->assertDatabaseHas('product_packaging_profiles', [
            'id'=>$profile->id,'product_id'=>$product->id,'current_measurement_record_id'=>$record->id,
        ]);
        $this->actingAs($reviewer)->get("/inventory/packaging/{$record->id}")->assertInertia(
            fn (Assert $page) => $page
                ->where('isCurrentStandard', true)
                ->where('currentStandardRecordId', $record->id)
        );
    }

    private function userWith(array $keys): User
    {
        $user = User::factory()->create(); $role = Role::create(['key'=>'test-'.uniqid(),'label'=>'Test']);
        $role->permissions()->sync(collect($keys)->map(fn($key)=>Permission::firstOrCreate(['key'=>$key],['label'=>$key]))->pluck('id'));
        $user->roles()->attach($role); return $user;
    }
}
