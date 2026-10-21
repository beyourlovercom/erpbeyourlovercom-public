<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\AffiliateWp\AffiliateSnapshotSyncService;
use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\AffiliateWp\ConnectorException;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;
use UnexpectedValueException;

class AffiliateSnapshotSyncTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'test_sync_'.uniqid(), 'label' => 'Test']);
        foreach ($permissions as $key) {
            $perm = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($perm);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function manager(): User
    {
        return $this->userWith(['kol.view', 'kol.affiliates.manage']);
    }

    private function response(array $items, ?string $nextCursor = null): ConnectorResponse
    {
        return ConnectorResponse::fromArray([
            'ok' => true,
            'contract_version' => '1',
            'request_id' => 'req-'.uniqid(),
            'connection_key' => 'test.example.com',
            'data' => ['items' => $items, 'next_cursor' => $nextCursor],
        ]);
    }

    private function gatewayReturning(array $items): AffiliateWpGateway
    {
        $mock = Mockery::mock(AffiliateWpGateway::class);
        $mock->shouldReceive('descriptor')->andReturn(['connection_key' => 'test.example.com']);
        $mock->shouldReceive('snapshot')->with('affiliates', null, null)->once()->andReturn($this->response($items));

        return $mock;
    }

    private function existingAffiliate(int $affiliateId, array $overrides = []): KolAffiliateAccount
    {
        return KolAffiliateAccount::create(array_merge([
            'connection_key' => 'test.example.com',
            'affiliate_id' => $affiliateId,
            'display_name' => 'Existing Affiliate',
            'email' => 'existing@example.com',
            'provider_status' => 'active',
            'origin' => 'external_sync',
            'sync_status' => 'synced',
            'reconciliation_status' => 'present',
            'provisioning_status' => 'not_requested',
        ], $overrides));
    }

    public function test_wordpress_user_login_migration_rolls_back_and_reapplies_cleanly(): void
    {
        $path = database_path('migrations/2026_09_05_130000_add_wordpress_user_login_to_kol_affiliate_accounts.php');
        $migration = require $path;

        self::assertTrue(Schema::hasColumn('kol_affiliate_accounts', 'wordpress_user_login'));
        $migration->down();
        self::assertFalse(Schema::hasColumn('kol_affiliate_accounts', 'wordpress_user_login'));
        $migration->up();
        self::assertTrue(Schema::hasColumn('kol_affiliate_accounts', 'wordpress_user_login'));
    }

    public function test_sync_reads_canonical_items_and_maps_wordpress_user_facts(): void
    {
        $gateway = $this->gatewayReturning([
            ['affiliate_id' => 101, 'user_id' => 5001, 'username' => 'alice-login', 'display_name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active'],
        ]);

        $result = (new AffiliateSnapshotSyncService($gateway))->sync('test.example.com', $this->manager());

        self::assertSame(1, $result['synced']);
        self::assertSame(0, $result['marked_missing']);
        $alice = KolAffiliateAccount::where('affiliate_id', 101)->firstOrFail();
        self::assertSame(5001, $alice->wordpress_user_id);
        self::assertSame('alice-login', $alice->wordpress_user_login);
        self::assertSame('alice@example.com', $alice->wordpress_user_email);
        self::assertSame('alice@example.com', $alice->email);
        self::assertSame('active', $alice->provider_status);
        self::assertSame('present', $alice->reconciliation_status);
    }

    public function test_multi_page_snapshot_is_fully_fetched_before_apply(): void
    {
        $mock = Mockery::mock(AffiliateWpGateway::class);
        $mock->shouldReceive('snapshot')->with('affiliates', null, null)->once()->andReturn($this->response([
            ['affiliate_id' => 201, 'user_id' => 6001, 'display_name' => 'Page One', 'email' => 'one@example.com', 'status' => 'active'],
        ], 'cursor-2'));
        $mock->shouldReceive('snapshot')->with('affiliates', 'cursor-2', null)->once()->andReturn($this->response([
            ['affiliate_id' => 202, 'user_id' => 6002, 'display_name' => 'Page Two', 'email' => 'two@example.com', 'status' => 'pending'],
        ], ''));

        $result = (new AffiliateSnapshotSyncService($mock))->sync('test.example.com', $this->manager());

        self::assertSame(2, $result['synced']);
        self::assertSame([201, 202], KolAffiliateAccount::orderBy('affiliate_id')->pluck('affiliate_id')->all());
    }

    public function test_exact_identity_upsert_updates_without_duplication(): void
    {
        $this->existingAffiliate(301, ['display_name' => 'Old']);
        $gateway = $this->gatewayReturning([
            ['affiliate_id' => 301, 'user_id' => 7001, 'display_name' => 'Updated', 'email' => 'updated@example.com', 'status' => 'active'],
        ]);

        (new AffiliateSnapshotSyncService($gateway))->sync('test.example.com', $this->manager());

        self::assertSame(1, KolAffiliateAccount::where('connection_key', 'test.example.com')->where('affiliate_id', 301)->count());
        self::assertSame('Updated', KolAffiliateAccount::where('affiliate_id', 301)->value('display_name'));
    }

    public function test_sync_preserves_explicit_kol_binding_and_erp_only_fields(): void
    {
        $owner = $this->userWith([]);
        $kol = Kol::create(['display_name' => 'Bound KOL', 'status' => 'active', 'relationship_stage' => 'prospect', 'owner_id' => $owner->id, 'approval_status' => 'approved']);
        $this->existingAffiliate(401, [
            'kol_id' => $kol->id,
            'origin' => 'erp_conversion',
            'provisioning_status' => 'active',
            'provision_idempotency_key' => 'stable-key',
        ]);
        $gateway = $this->gatewayReturning([
            ['affiliate_id' => 401, 'user_id' => 8001, 'username' => 'bound-login', 'display_name' => 'Provider Rename', 'email' => 'bound@example.com', 'status' => 'active'],
        ]);

        (new AffiliateSnapshotSyncService($gateway))->sync('test.example.com', $this->manager());
        $row = KolAffiliateAccount::where('affiliate_id', 401)->firstOrFail();

        self::assertSame($kol->id, $row->kol_id);
        self::assertSame('erp_conversion', $row->origin);
        self::assertSame('active', $row->provisioning_status);
        self::assertSame('bound-login', $row->wordpress_user_login);
        self::assertSame('stable-key', $row->provision_idempotency_key);
    }

    public function test_duplicate_email_or_name_never_auto_binds_or_merges_affiliates(): void
    {
        $gateway = $this->gatewayReturning([
            ['affiliate_id' => 501, 'user_id' => 9001, 'display_name' => 'Same Name', 'email' => 'same@example.com', 'status' => 'active'],
            ['affiliate_id' => 502, 'user_id' => 9002, 'display_name' => 'Same Name', 'email' => 'same@example.com', 'status' => 'pending'],
        ]);

        (new AffiliateSnapshotSyncService($gateway))->sync('test.example.com', $this->manager());

        self::assertSame(2, KolAffiliateAccount::where('email', 'same@example.com')->count());
        self::assertSame(0, KolAffiliateAccount::whereNotNull('kol_id')->count());
    }

    public function test_complete_snapshot_marks_unseen_rows_missing(): void
    {
        $this->existingAffiliate(601);
        $gateway = $this->gatewayReturning([
            ['affiliate_id' => 602, 'user_id' => 9102, 'display_name' => 'Present', 'email' => 'present@example.com', 'status' => 'active'],
        ]);

        $result = (new AffiliateSnapshotSyncService($gateway))->sync('test.example.com', $this->manager());

        self::assertSame(1, $result['marked_missing']);
        self::assertSame('missing_from_latest_snapshot', KolAffiliateAccount::where('affiliate_id', 601)->value('reconciliation_status'));
        self::assertSame('present', KolAffiliateAccount::where('affiliate_id', 602)->value('reconciliation_status'));
    }

    public function test_complete_empty_snapshot_marks_all_known_affiliates_missing(): void
    {
        $this->existingAffiliate(611);
        $this->existingAffiliate(612);

        $result = (new AffiliateSnapshotSyncService($this->gatewayReturning([])))->sync('test.example.com', $this->manager());

        self::assertSame(0, $result['synced']);
        self::assertSame(2, $result['marked_missing']);
        self::assertSame(2, KolAffiliateAccount::where('reconciliation_status', 'missing_from_latest_snapshot')->count());
    }

    public function test_second_page_failure_applies_no_partial_snapshot_changes(): void
    {
        $this->existingAffiliate(701);
        $mock = Mockery::mock(AffiliateWpGateway::class);
        $mock->shouldReceive('snapshot')->with('affiliates', null, null)->once()->andReturn($this->response([
            ['affiliate_id' => 702, 'user_id' => 9202, 'display_name' => 'Would Be New', 'email' => 'new@example.com', 'status' => 'active'],
        ], 'cursor-2'));
        $mock->shouldReceive('snapshot')->with('affiliates', 'cursor-2', null)->once()->andThrow(
            ConnectorException::retryable('provider down', 'PROVIDER_UNAVAILABLE', 503)
        );

        try {
            (new AffiliateSnapshotSyncService($mock))->sync('test.example.com', $this->manager());
            self::fail('Expected provider failure.');
        } catch (ConnectorException $exception) {
            self::assertSame('PROVIDER_UNAVAILABLE', $exception->errorCode);
        }

        self::assertNull(KolAffiliateAccount::where('affiliate_id', 702)->first());
        self::assertSame('present', KolAffiliateAccount::where('affiliate_id', 701)->value('reconciliation_status'));
    }

    public function test_invalid_item_fails_closed_without_mutating_mirror(): void
    {
        $this->existingAffiliate(801);
        $gateway = $this->gatewayReturning([
            ['display_name' => 'Missing ID', 'email' => 'invalid@example.com', 'status' => 'active'],
        ]);

        $this->expectException(UnexpectedValueException::class);
        try {
            (new AffiliateSnapshotSyncService($gateway))->sync('test.example.com', $this->manager());
        } finally {
            self::assertSame(1, KolAffiliateAccount::count());
            self::assertSame('present', KolAffiliateAccount::where('affiliate_id', 801)->value('reconciliation_status'));
        }
    }

    public function test_application_service_enforces_affiliate_management_permission(): void
    {
        $gateway = Mockery::mock(AffiliateWpGateway::class);
        $this->expectException(AuthorizationException::class);
        (new AffiliateSnapshotSyncService($gateway))->sync('test.example.com', $this->userWith(['kol.view']));
    }

    public function test_workspace_projects_real_unbound_affiliate_rows(): void
    {
        $this->existingAffiliate(901, [
            'display_name' => 'Visible External',
            'wordpress_user_id' => 10001,
            'wordpress_user_email' => 'visible@example.com',
        ]);
        $mock = Mockery::mock(AffiliateWpGateway::class);
        $mock->shouldReceive('descriptor')->andReturn(['connection_key' => 'test.example.com']);
        $this->app->instance(AffiliateWpGateway::class, $mock);

        $response = $this->actingAs($this->userWith(['kol.view']))->get('/kol/affiliatewp');
        $response->assertOk();
        $props = $response->original->getData()['page']['props'];

        self::assertCount(1, $props['affiliates']);
        self::assertSame(901, $props['affiliates'][0]['affiliate_id']);
        self::assertSame('Visible External', $props['affiliates'][0]['display_name']);
        self::assertNull($props['affiliates'][0]['kol']);
    }

    public function test_manager_can_trigger_manual_sync_and_specialist_cannot(): void
    {
        $mock = Mockery::mock(AffiliateWpGateway::class);
        $mock->shouldReceive('descriptor')->andReturn(['connection_key' => 'test.example.com']);
        $mock->shouldReceive('snapshot')->with('affiliates', null, null)->once()->andReturn($this->response([]));
        $this->app->instance(AffiliateWpGateway::class, $mock);

        $this->actingAs($this->manager())->post('/kol/affiliatewp/sync')->assertRedirect();

        $specialist = $this->userWith(['kol.view', 'kol.operate']);
        $this->actingAs($specialist)->post('/kol/affiliatewp/sync')->assertForbidden();
    }

    public function test_unauthenticated_cannot_trigger_manual_sync(): void
    {
        $this->post('/kol/affiliatewp/sync')->assertRedirect('/login');
    }
}
