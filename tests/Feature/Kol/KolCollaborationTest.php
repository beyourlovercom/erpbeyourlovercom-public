<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolOpportunity;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolCollaborationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_update_formal_collaboration_with_canonical_product(): void
    {
        $admin = User::factory()->create();
        $this->grant($admin, ['kol.view','kol.operate','kol.views.manage']);
        $kol = Kol::create(['display_name'=>'Creator A','status'=>'active','relationship_stage'=>'negotiating','owner_id'=>$admin->id]);
        $product = Product::create(['source_type'=>'manual','sku'=>'COLLAB-SKU-1','name'=>'Collab Product','is_active'=>true]);

        $response = $this->actingAs($admin)->postJson("/kol/library/{$kol->id}/collaborations", [
            'post_url'=>'https://instagram.com/p/test-post','cooperation_type'=>'免费发帖','status'=>'confirmed','agreement_amount'=>100,'currency'=>'USD','agreed_at'=>'2026-08-28','fulfillment_required'=>true,
            'products'=>[['product_id'=>$product->id,'sku'=>$product->sku,'quantity'=>2]],
        ]);
        $response->assertCreated()->assertJsonPath('collaboration.products.0.product_id', $product->id)->assertJsonPath('collaboration.post_url', 'https://instagram.com/p/test-post');
        $id = $response->json('collaboration.id');
        $this->assertDatabaseHas('kol_collaborations',['id'=>$id,'kol_id'=>$kol->id,'status'=>'confirmed']);
        $this->assertDatabaseHas('kol_collaboration_products',['collaboration_id'=>$id,'product_id'=>$product->id,'quantity'=>2]);

        $this->actingAs($admin)->patchJson("/kol/library/{$kol->id}/collaborations/{$id}", [
            'post_url'=>'https://instagram.com/p/test-post-updated','cooperation_type'=>'免费发帖','status'=>'in_progress','agreement_amount'=>100,'currency'=>'USD','agreed_at'=>'2026-08-28','fulfillment_required'=>true,
            'products'=>[['product_id'=>$product->id,'sku'=>$product->sku,'quantity'=>3]],
        ])->assertOk()->assertJsonPath('collaboration.status','in_progress');
        $this->assertDatabaseHas('kol_collaboration_products',['collaboration_id'=>$id,'quantity'=>3]);
    }

    public function test_explicit_opportunity_conversion_creates_one_collaboration_and_closes_crm_opportunity(): void
    {
        $admin = User::factory()->create();
        $this->grant($admin, ['kol.view','kol.operate','kol.views.manage']);
        $kol = Kol::create(['display_name'=>'Creator B','status'=>'active','relationship_stage'=>'negotiating','owner_id'=>$admin->id]);
        Product::create(['source_type'=>'manual','sku'=>'CONVERT-SKU','name'=>'Convert Product','is_active'=>true]);
        $opp = KolOpportunity::create(['kol_id'=>$kol->id,'title'=>'Confirmed Deal','cooperation_type'=>'付费发帖','target_products'=>['CONVERT-SKU'],'stage'=>'communicating','owner_id'=>$admin->id,'estimated_cost'=>250,'currency'=>'USD','status'=>'active']);

        $first = $this->actingAs($admin)->postJson("/kol/library/{$kol->id}/opportunities/{$opp->id}/convert-to-collaboration");
        $first->assertCreated()->assertJsonPath('collaboration.opportunity_id',$opp->id);
        $second = $this->actingAs($admin)->postJson("/kol/library/{$kol->id}/opportunities/{$opp->id}/convert-to-collaboration");
        $second->assertOk()->assertJsonPath('already_exists',true);
        $this->assertDatabaseCount('kol_collaborations',1);
        $this->assertDatabaseHas('kol_opportunities',['id'=>$opp->id,'stage'=>'confirmed','status'=>'completed']);
    }

    public function test_collaboration_index_respects_specialist_kol_owner_scope(): void
    {
        $ownerA = User::factory()->create(['is_active' => true]);
        $ownerB = User::factory()->create(['is_active' => true]);
        $this->grant($ownerA, ['kol.view', 'kol.operate']);
        $this->grant($ownerB, ['kol.view', 'kol.operate']);
        $kolA = Kol::create(['display_name' => 'Visible Creator', 'status' => 'active', 'relationship_stage' => 'cooperating', 'owner_id' => $ownerA->id]);
        $kolB = Kol::create(['display_name' => 'Hidden Creator', 'status' => 'active', 'relationship_stage' => 'cooperating', 'owner_id' => $ownerB->id]);
        \App\Modules\Kol\Infrastructure\Models\KolCollaboration::create(['kol_id' => $kolA->id, 'owner_id' => $ownerA->id, 'title' => 'Visible Collaboration', 'status' => 'confirmed', 'currency' => 'USD']);
        \App\Modules\Kol\Infrastructure\Models\KolCollaboration::create(['kol_id' => $kolB->id, 'owner_id' => $ownerB->id, 'title' => 'Hidden Collaboration', 'status' => 'confirmed', 'currency' => 'USD']);

        $response = $this->actingAs($ownerA)->get('/kol/collaborations');
        $response->assertOk();
        $props = $response->original->getData()['page']['props'];
        $this->assertCount(1, $props['collaborations']);
        $this->assertSame('Visible Collaboration', $props['collaborations'][0]['title']);
    }

    public function test_product_id_and_sku_must_match_canonical_product(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $this->grant($admin, ['kol.view', 'kol.operate', 'kol.views.manage']);
        $kol = Kol::create(['display_name' => 'SKU Guard', 'status' => 'active', 'relationship_stage' => 'cooperating', 'owner_id' => $admin->id]);
        $product = Product::create(['source_type' => 'manual', 'sku' => 'REAL-SKU', 'name' => 'Real Product', 'is_active' => true]);

        $response = $this->actingAs($admin)->postJson("/kol/library/{$kol->id}/collaborations", [
            'title' => 'Mismatch', 'status' => 'draft', 'currency' => 'USD', 'fulfillment_required' => false,
            'products' => [['product_id' => $product->id, 'sku' => 'WRONG-SKU', 'quantity' => 1]],
        ]);
        $response->assertStatus(422);
        $this->assertDatabaseCount('kol_collaborations', 0);
    }

    public function test_opportunity_conversion_rejects_opportunity_from_another_kol(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $this->grant($admin, ['kol.view', 'kol.operate', 'kol.views.manage']);
        $kolA = Kol::create(['display_name' => 'Creator A', 'status' => 'active', 'relationship_stage' => 'negotiating', 'owner_id' => $admin->id]);
        $kolB = Kol::create(['display_name' => 'Creator B', 'status' => 'active', 'relationship_stage' => 'negotiating', 'owner_id' => $admin->id]);
        $opp = KolOpportunity::create(['kol_id' => $kolB->id, 'title' => 'Other KOL Deal', 'stage' => 'communicating', 'owner_id' => $admin->id, 'currency' => 'USD', 'status' => 'active']);

        $this->actingAs($admin)->postJson("/kol/library/{$kolA->id}/opportunities/{$opp->id}/convert-to-collaboration")->assertNotFound();
        $this->assertDatabaseCount('kol_collaborations', 0);
    }

    public function test_specialist_cannot_mutate_another_owners_collaboration(): void
    {
        $owner = User::factory()->create(); $specialist = User::factory()->create();
        $this->grant($owner,['kol.view','kol.operate','kol.views.manage']); $this->grant($specialist,['kol.view','kol.operate']);
        $kol = Kol::create(['display_name'=>'Owner KOL','status'=>'active','relationship_stage'=>'cooperating','owner_id'=>$owner->id]);
        $product = Product::create(['source_type'=>'manual','sku'=>'LOCK-SKU','name'=>'Locked','is_active'=>true]);
        $this->actingAs($specialist)->postJson("/kol/library/{$kol->id}/collaborations", ['title'=>'Blocked','status'=>'draft','currency'=>'USD','fulfillment_required'=>false,'products'=>[['product_id'=>$product->id,'sku'=>$product->sku,'quantity'=>1]]])->assertForbidden();
    }

    public function test_collaboration_can_exist_without_post_url_and_missing_url_filter_finds_it(): void
    {
        $admin = User::factory()->create();
        $this->grant($admin, ['kol.view','kol.operate','kol.views.manage']);
        $kol = Kol::create(['display_name'=>'Follow Up Creator','status'=>'active','relationship_stage'=>'cooperating','owner_id'=>$admin->id]);
        \App\Modules\Kol\Infrastructure\Models\KolCollaboration::create(['kol_id'=>$kol->id,'owner_id'=>$admin->id,'status'=>'confirmed','currency'=>'USD']);

        $response = $this->actingAs($admin)->get('/kol/collaborations?post_url_status=missing');
        $response->assertOk();
        $props = $response->original->getData()['page']['props'];
        $this->assertCount(1, $props['collaborations']);
        $this->assertNull($props['collaborations'][0]['post_url']);
    }

    private function grant(User $user, array $permissions): void
    {
        $role = Role::create(['key' => 'test_kol_collab_'.uniqid(), 'label' => 'Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);
    }

}
