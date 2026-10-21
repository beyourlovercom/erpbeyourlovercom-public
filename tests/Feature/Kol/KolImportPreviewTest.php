<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\ImportPreview\KolImportPreviewService;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationBatch;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationRow;
use App\Modules\Kol\Infrastructure\Models\KolSocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_order_rows_collapse_to_one_candidate_without_canonical_mutation(): void
    {
        $user = $this->manager('Emma');
        $batch = $this->batch();
        $this->row($batch, 2, ['Contact Email'=>'creator@example.com','Influencer`s Link/URL'=>'https://www.instagram.com/Creator/','Followers'=>'57.7K','Belongs to'=>'Emma','Input Time'=>'46000','Full name'=>'Ship One','国 Country'=>'USA','City'=>'Austin','单号 Order No.'=>'K1','SKU'=>'A']);
        $this->row($batch, 3, ['Contact Email'=>'creator@example.com','Influencer`s Link/URL'=>'https://x.com/CreatorX','Belongs to'=>'Emma','Input Time'=>'46100','Full name'=>'Ship Two','国 Country'=>'United States','City'=>'Dallas','单号 Order No.'=>'K2','SKU'=>'B']);
        $before = Kol::count();
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $user);
        self::assertSame(1, $preview->candidate_count);
        self::assertSame(0, $preview->unresolved_source_rows);
        self::assertSame($before, Kol::count());
        $candidate = $preview->candidates->first();
        self::assertSame(2, $candidate->source_row_count);
        self::assertSame('Creator', $candidate->proposed_kol['display_name']);
        self::assertSame('Ship Two', $candidate->proposed_kol['shipping_recipient_name']);
        self::assertArrayNotHasKey('legal_name', $candidate->proposed_kol);
        self::assertSame('US', $candidate->proposed_kol['country_code']);
        self::assertSame($user->id, $candidate->matched_owner_id);
        self::assertCount(2, $candidate->proposed_social_accounts);
        self::assertSame(2, $candidate->history_summary['source_order_count']);
        $workspace = $this->app->make(KolImportPreviewService::class)->workspace();
        self::assertCount(1, $workspace[0]['import_preview']['candidates']);
    }

    public function test_multi_url_followers_are_not_guessed_and_no_identity_rows_are_unresolved(): void
    {
        $user = $this->manager(); $batch = $this->batch();
        $this->row($batch, 2, ['Contact Email'=>'multi@example.com','Influencer`s Link/URL'=>"https://instagram.com/multi/\nhttps://x.com/multi",'Followers'=>'100K']);
        $this->row($batch, 3, ['Full name'=>'No Identity','收信 Shipping Info'=>'Name: Nobody']);
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $user);
        self::assertSame(1, $preview->candidate_count);
        self::assertSame(1, $preview->unresolved_source_rows);
        $socials = $preview->candidates->first()->proposed_social_accounts;
        self::assertCount(2, $socials);
        self::assertNull($socials[0]['followers']);
        self::assertNull($socials[1]['followers']);
    }

    public function test_existing_exact_kol_blocks_new_candidate_and_cross_kol_exact_evidence_conflicts(): void
    {
        $user = $this->manager();
        $existing = Kol::create(['display_name'=>'Existing','status'=>'active','relationship_stage'=>'prospect','contact_email'=>'existing@example.com']);
        $batch = $this->batch(); $this->row($batch, 2, ['Contact Email'=>'existing@example.com']);
        $preview = $this->app->make(KolImportPreviewService::class)->generate($batch->id, $user);
        self::assertSame('existing_kol', $preview->candidates->first()->preview_status);
        self::assertSame($existing->id, $preview->candidates->first()->matched_existing_kol_id);

        $other = Kol::create(['display_name'=>'Other','status'=>'active','relationship_stage'=>'prospect','contact_email'=>'other@example.com']);
        KolSocialAccount::create(['kol_id'=>$other->id,'platform'=>'instagram','profile_url'=>'https://instagram.com/other','is_active'=>true]);
        $batch2 = $this->batch('second.xlsx');
        $this->row($batch2, 2, ['Contact Email'=>'existing@example.com','Influencer`s Link/URL'=>'https://instagram.com/other/']);
        $preview2 = $this->app->make(KolImportPreviewService::class)->generate($batch2->id, $user);
        self::assertSame('conflict', $preview2->candidates->first()->preview_status);
        self::assertNull($preview2->candidates->first()->matched_existing_kol_id);
    }

    public function test_preview_routes_require_operate_and_affiliate_manage_permissions(): void
    {
        $none = User::factory()->create(['is_active'=>true]);
        $this->actingAs($none)->get('/kol/import-preview')->assertForbidden();
        $affiliateOnly = $this->userWith(['kol.view','kol.affiliates.manage']);
        $this->actingAs($affiliateOnly)->get('/kol/import-preview')->assertForbidden();
        $manager = $this->manager();
        $this->actingAs($manager)->get('/kol/import-preview')->assertOk()->assertInertia(fn ($page) => $page->component('Kol/ImportPreview'));
    }

    private function batch(string $filename='0902-网红订单.xlsx'): KolReconciliationBatch
    {
        return KolReconciliationBatch::create(['source_type'=>'xlsx','original_filename'=>$filename,'checksum'=>str_repeat('a',64),'connection_key'=>'test.example.com','status'=>'preview']);
    }

    private function row(KolReconciliationBatch $batch, int $number, array $raw): KolReconciliationRow
    {
        $email = isset($raw['Contact Email']) ? strtolower(trim((string)$raw['Contact Email'])) : null;
        return KolReconciliationRow::create(['batch_id'=>$batch->id,'source_row_number'=>$number,'row_hash'=>hash('sha256',json_encode($raw)),'raw_source'=>$raw,'normalized_identity'=>['email'=>$email,'social'=>[],'key'=>$email?'email:'.$email:null],'source_identity_key'=>$email?'email:'.$email:null,'kol_status'=>'unresolved','affiliate_status'=>'missing','reconciliation_status'=>'unresolved']);
    }

    private function manager(string $name='Manager'): User { return $this->userWith(['kol.view','kol.operate','kol.affiliates.manage'], $name); }
    private function userWith(array $keys, string $name='User'): User
    {
        $role=Role::create(['key'=>'test_import_'.uniqid(),'label'=>'Test']);
        foreach ($keys as $key) $role->permissions()->attach(Permission::firstOrCreate(['key'=>$key],['label'=>$key]));
        $user=User::factory()->create(['is_active'=>true,'name'=>$name]); $user->roles()->attach($role); return $user;
    }
}
