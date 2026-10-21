<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\AffiliateWp\AffiliateBindingService;
use App\Modules\Kol\Application\Reconciliation\KolReconciliationService;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateReferral;
use App\Modules\Kol\Infrastructure\Models\KolSocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class KolReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        ini_set('sys_temp_dir', '/tmp');
        parent::setUp();
    }

    public function test_exact_preview_classifies_rows_without_creating_kols(): void
    {
        $user = $this->manager();
        $kol = Kol::create(['display_name' => 'Exact KOL', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $user->id, 'contact_email' => 'exact@example.com']);
        KolAffiliateAccount::create(['connection_key' => 'test.example.com', 'affiliate_id' => 44, 'email' => 'exact@example.com', 'provider_status' => 'active', 'origin' => 'external_sync', 'sync_status' => 'synced', 'reconciliation_status' => 'present', 'provisioning_status' => 'not_requested']);
        $file = UploadedFile::createFromBase(new \Symfony\Component\HttpFoundation\File\UploadedFile(base_path('tests/Fixtures/kol_reconciliation.csv'), 'kol.csv', 'text/csv', null, true), true);
        $batch = $this->app->make(KolReconciliationService::class)->preview($file, 'test.example.com', $user);
        self::assertSame(2, $batch->total_rows);
        self::assertSame(1, $batch->distinct_source_identities);
        $exact = $batch->rows->first(fn ($row) => $row->raw_source['email'] === 'exact@example.com');
        $nameOnly = $batch->rows->first(fn ($row) => $row->raw_source['name'] === 'Name Only');
        self::assertNotNull($exact);
        self::assertSame('exact@example.com', $exact->normalized_identity['email']);
        self::assertSame($kol->id, $exact->matched_kol_id);
        self::assertSame('ready_for_binding', $exact->reconciliation_status);
        self::assertSame('unresolved', $nameOnly->reconciliation_status);
        self::assertSame(1, Kol::whereKey($kol->id)->count());
    }

    public function test_existing_bind_reresolves_local_referrals_without_provider_facts_changing(): void
    {
        $user = $this->manager();
        $kol = Kol::create(['display_name' => 'Referral KOL', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $user->id]);
        $account = KolAffiliateAccount::create(['connection_key' => 'test.example.com', 'affiliate_id' => 55, 'email' => 'ref@example.com', 'provider_status' => 'pending', 'origin' => 'external_sync', 'sync_status' => 'synced', 'reconciliation_status' => 'present', 'provisioning_status' => 'not_requested']);
        $referral = KolAffiliateReferral::create(['connection_key' => 'test.example.com', 'referral_id' => 9, 'affiliate_id' => 55, 'order_reference' => 'WC-9', 'commission_amount' => '12.500000', 'currency' => 'USD', 'provider_status' => 'unpaid', 'context' => 'affiliate', 'referral_date' => now(), 'payout_id' => 7, 'affiliate_resolution_status' => 'unbound', 'order_resolution_status' => 'resolved', 'reconciliation_status' => 'present', 'sync_status' => 'synced']);
        $this->app->make(AffiliateBindingService::class)->bind('test.example.com', $account->id, $kol->id, $user);
        $fresh = $referral->fresh();
        self::assertSame($kol->id, $fresh->kol_id);
        self::assertSame('bound', $fresh->affiliate_resolution_status);
        self::assertSame('12.500000', $fresh->commission_amount);
        self::assertSame(7, $fresh->payout_id);
        self::assertSame('unpaid', $fresh->provider_status);
    }

    public function test_reconciliation_routes_require_manage_permission_server_side(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->get('/kol/affiliatewp/reconciliation')->assertForbidden();
        $this->actingAs($user)->post('/kol/affiliatewp/reconciliation/preview', [
            'file' => $this->uploadedText('rows.csv', "email\nuser@example.com\n"),
        ])->assertForbidden();
    }

    public function test_tencent_aliases_and_separate_affiliate_resolution_statuses_are_exact(): void
    {
        $user = $this->manager();
        $kol = Kol::create(['display_name' => 'Tencent KOL', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $user->id, 'contact_email' => 'kol@example.com']);
        KolSocialAccount::create(['kol_id' => $kol->id, 'platform' => 'Instagram', 'handle' => 'creator', 'profile_url' => 'https://Example.com/Profile/', 'is_active' => true]);
        $unique = KolAffiliateAccount::create(['connection_key' => 'test.example.com', 'affiliate_id' => 101, 'email' => 'kol@example.com', 'provider_status' => 'active', 'origin' => 'external_sync', 'sync_status' => 'synced', 'reconciliation_status' => 'present', 'provisioning_status' => 'not_requested']);
        $other = Kol::create(['display_name' => 'Other KOL', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $user->id, 'contact_email' => 'other@example.com']);
        KolAffiliateAccount::create(['connection_key' => 'test.example.com', 'affiliate_id' => 102, 'email' => 'duplicate@example.com', 'provider_status' => 'active', 'origin' => 'external_sync', 'sync_status' => 'synced', 'reconciliation_status' => 'present', 'provisioning_status' => 'not_requested']);
        KolAffiliateAccount::create(['connection_key' => 'test.example.com', 'affiliate_id' => 103, 'wordpress_user_email' => 'duplicate@example.com', 'provider_status' => 'active', 'origin' => 'external_sync', 'sync_status' => 'synced', 'reconciliation_status' => 'present', 'provisioning_status' => 'not_requested']);
        $csv = "Contact Email,Influencer`s Link/URL\nkol@example.com,https://example.com/Profile/#evidence\n";
        $batch = $this->preview($csv, 'rows.csv', $user);
        self::assertSame('ready_for_binding', $batch->rows->first()->reconciliation_status);
        self::assertSame($unique->id, $batch->rows->first()->matched_affiliate_account_id);

        $batch = $this->preview("email,Influencer's Link/URL\nkol@example.com,https://example.com/Profile/\n", 'aliases.csv', $user);
        self::assertSame('ready_for_binding', $batch->rows->first()->reconciliation_status);

        $batch = $this->preview("email\nduplicate@example.com\n", 'conflict.csv', $user);
        self::assertSame('conflict', $batch->rows->first()->reconciliation_status);
        self::assertSame('unresolved', $batch->rows->first()->kol_status);
        self::assertSame('conflict', $batch->rows->first()->affiliate_status);
        self::assertNull($batch->rows->first()->matched_affiliate_account_id);
        self::assertNotSame($kol->id, $other->id);
    }

    public function test_frozen_reconciliation_vocabulary_keeps_affiliate_candidate_visible(): void
    {
        $user = $this->manager();
        $kol = Kol::create(['display_name' => 'URL KOL', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $user->id, 'contact_email' => 'url@example.com']);
        KolSocialAccount::create(['kol_id' => $kol->id, 'platform' => 'TikTok', 'handle' => 'urlcreator', 'profile_url' => 'https://Example.COM:8443/CreatorPath/?utm=ABC', 'is_active' => true]);
        $account = KolAffiliateAccount::create(['connection_key' => 'test.example.com', 'affiliate_id' => 201, 'email' => 'affiliate-only@example.com', 'provider_status' => 'active', 'origin' => 'external_sync', 'sync_status' => 'synced', 'reconciliation_status' => 'present', 'provisioning_status' => 'not_requested']);
        $rows = "profile_url\nhttps://EXAMPLE.com:8443/CreatorPath/?utm=ABC#fragment\n";
        $batch = $this->preview($rows, 'matched-only.csv', $user);
        self::assertSame('matched_kol_only', $batch->rows->first()->reconciliation_status);
        self::assertSame('matched', $batch->rows->first()->kol_status);
        self::assertSame('KOL 已匹配，但没有可用源邮箱进行 Affiliate 精确核对。', $batch->rows->first()->message);
        self::assertSame('https://example.com:8443/CreatorPath?utm=ABC', $batch->rows->first()->normalized_identity['social']['profile_url']);

        $batch = $this->preview("profile_url\nexample.com/CreatorPath/?utm=ABC\n", 'invalid-url.csv', $user);
        self::assertSame('unresolved', $batch->rows->first()->reconciliation_status);
        self::assertNull($batch->rows->first()->normalized_identity['social']['profile_url'] ?? null);

        $batch = $this->preview("email\nurl@example.com\n", 'no-affiliate.csv', $user);
        self::assertSame('no_affiliate', $batch->rows->first()->reconciliation_status);
        self::assertSame('matched', $batch->rows->first()->kol_status);

        $account->update(['kol_id' => $kol->id]);
        $batch = $this->preview("email,profile_url\naffiliate-only@example.com,https://EXAMPLE.com:8443/CreatorPath/?utm=ABC\n", 'already-bound.csv', $user);
        self::assertSame('already_bound', $batch->rows->first()->reconciliation_status);

        $batch = $this->preview("email\naffiliate-only@example.com\n", 'affiliate-only.csv', $user);
        self::assertSame('unresolved', $batch->rows->first()->reconciliation_status);
        self::assertSame($account->id, $batch->rows->first()->matched_affiliate_account_id);
        self::assertSame([$account->id], $batch->rows->first()->exact_match_evidence['affiliate_email']);

        $batch = $this->preview("email\nmissing-affiliate@example.com\n", 'missing-affiliate-no-kol.csv', $user);
        self::assertSame('no_affiliate', $batch->rows->first()->reconciliation_status);
    }

    public function test_standard_relationship_resolved_shared_strings_xlsx_previews_and_malformed_xlsx_is_rejected(): void
    {
        $user = $this->manager();
        $kol = Kol::create(['display_name' => 'XLSX KOL', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $user->id, 'contact_email' => 'xlsx@example.com']);
        $file = $this->xlsxWithSharedStrings([
            'Contact Email', 'Influencer`s Link/URL',
            'xlsx@example.com', 'https://example.test/Profile/',
        ]);
        $batch = $this->app->make(KolReconciliationService::class)->preview($file, 'test.example.com', $user);
        self::assertSame('no_affiliate', $batch->rows->first()->reconciliation_status);
        self::assertSame($kol->id, $batch->rows->first()->matched_kol_id);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->app->make(KolReconciliationService::class)->preview($this->uploadedText('bad.xlsx', 'not a zip'), 'test.example.com', $user);
    }

    public function test_xlsx_over_ten_mib_with_incompressible_media_previews_without_media_identity_data(): void
    {
        $user = $this->manager();
        $beforeKols = Kol::count();
        $beforeBatches = \App\Modules\Kol\Infrastructure\Models\KolReconciliationBatch::count();
        $file = $this->xlsxWithMedia(11 * 1024 * 1024);

        self::assertGreaterThan(10 * 1024 * 1024, $file->getSize());
        self::assertLessThan(64 * 1024 * 1024, $file->getSize());
        $batch = $this->app->make(KolReconciliationService::class)->preview($file, 'test.example.com', $user);

        self::assertSame(1, $batch->total_rows);
        self::assertSame('no_affiliate', $batch->rows->first()->reconciliation_status);
        self::assertSame($beforeKols, Kol::count());
        self::assertSame($beforeBatches + 1, \App\Modules\Kol\Infrastructure\Models\KolReconciliationBatch::count());
        self::assertDatabaseCount('kol_affiliate_accounts', 0);
        self::assertDatabaseCount('kol_affiliate_referrals', 0);
    }

    public function test_http_reconciliation_route_accepts_xlsx_over_ten_mib_within_sixty_four_mib_limit(): void
    {
        $user = $this->manager();
        $file = $this->xlsxWithMedia(11 * 1024 * 1024);
        $this->actingAs($user)->post('/kol/affiliatewp/reconciliation/preview', ['file' => $file])
            ->assertOk()->assertJsonPath('batch.total_rows', 1);
    }

    public function test_xlsx_rejects_single_zip_entry_over_sixteen_mib(): void
    {
        $user = $this->manager();
        $file = $this->xlsxWithMedia(16 * 1024 * 1024 + 1);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->app->make(KolReconciliationService::class)->preview($file, 'test.example.com', $user);
    }

    public function test_file_over_sixty_four_mib_is_rejected_before_xlsx_parsing(): void
    {
        $user = $this->manager();
        $file = $this->sparseUpload('oversized.xlsx', 64 * 1024 * 1024 + 1);

        try {
            $this->app->make(KolReconciliationService::class)->preview($file, 'test.example.com', $user);
            self::fail('Expected the 64 MiB file limit to reject the upload.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            self::assertStringContainsString('64 MiB', $exception->getMessage());
        }
    }

    private function preview(string $contents, string $name, User $user): \App\Modules\Kol\Infrastructure\Models\KolReconciliationBatch
    {
        return $this->app->make(KolReconciliationService::class)->preview($this->uploadedText($name, $contents), 'test.example.com', $user);
    }

    private function uploadedText(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kol-upload-');
        file_put_contents($path, $contents);
        return UploadedFile::createFromBase(new \Symfony\Component\HttpFoundation\File\UploadedFile($path, $name, 'text/csv', null, true), true);
    }

    private function xlsxWithSharedStrings(array $values): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kol-xlsx-');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="4" r:id="rId7"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/data.xml"/></Relationships>');
        $shared = implode('', array_map(fn ($value, $i) => '<si><t>'.htmlspecialchars($value, ENT_XML1).'</t></si>', $values, array_keys($values)));
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$shared.'</sst>');
        $zip->addFromString('xl/worksheets/data.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row></sheetData></worksheet>');
        $zip->close();
        return UploadedFile::createFromBase(new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'rows.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true), true);
    }

    private function xlsxWithMedia(int $mediaBytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kol-media-');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/data.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/data.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Contact Email</t></is></c><c r="B1" t="inlineStr"><is><t>Influencer`s Link/URL</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>media-test@example.com</t></is></c><c r="B2" t="inlineStr"><is><t>https://example.test/media-test</t></is></c></row></sheetData></worksheet>');
        $zip->addFromString('xl/media/incompressible.bin', random_bytes($mediaBytes));
        $zip->close();
        return UploadedFile::createFromBase(new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'media.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true), true);
    }

    private function sparseUpload(string $name, int $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kol-sparse-');
        $handle = fopen($path, 'wb');
        ftruncate($handle, $bytes);
        fclose($handle);
        return UploadedFile::createFromBase(new \Symfony\Component\HttpFoundation\File\UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true), true);
    }

    private function manager(): User
    {
        $role = Role::create(['key' => 'test_reconcile_'.uniqid(), 'label' => 'Test']);
        foreach (['kol.view', 'kol.affiliates.manage'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
