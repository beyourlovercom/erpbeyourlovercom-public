<?php

namespace Tests\Feature\Kol;

use App\Models\User;
use App\Modules\Kol\Application\AffiliateWp\AffiliateAccountPreviewService;
use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;
use UnexpectedValueException;

class AffiliateAccountPreviewTest extends TestCase
{
    use RefreshDatabase;

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

    private function gateway(): \Mockery\MockInterface
    {
        $gateway = Mockery::mock(AffiliateWpGateway::class);
        $gateway->shouldReceive('descriptor')->andReturn(['connection_key' => 'test.example.com']);
        return $gateway;
    }

    private function kol(string $name): Kol
    {
        $owner = User::factory()->create(['is_active' => true]);
        return Kol::create([
            'display_name' => $name,
            'status' => 'active',
            'relationship_stage' => 'prospect',
            'owner_id' => $owner->id,
            'approval_status' => 'approved',
        ]);
    }

    private function account(int $affiliateId, ?Kol $kol = null, array $overrides = []): KolAffiliateAccount
    {
        return KolAffiliateAccount::create(array_merge([
            'connection_key' => 'test.example.com',
            'affiliate_id' => $affiliateId,
            'kol_id' => $kol?->id,
            'wordpress_user_id' => $affiliateId + 1000,
            'wordpress_user_email' => "wp{$affiliateId}@example.com",
            'display_name' => 'Mirror '.$affiliateId,
            'email' => "affiliate{$affiliateId}@example.com",
            'provider_status' => 'active',
            'origin' => 'external_sync',
            'sync_status' => 'synced',
            'reconciliation_status' => 'present',
            'provisioning_status' => 'not_requested',
        ], $overrides));
    }

    private function mirrorSnapshot(): array
    {
        return KolAffiliateAccount::query()->orderBy('id')->get()->map(fn ($row) => $row->getAttributes())->all();
    }

    public function test_full_paged_preview_is_read_only_and_reports_aggregate_capabilities_without_fuzzy_matching(): void
    {
        $boundKol = $this->kol('Bound');
        $unboundKol = $this->kol('No Affiliate');
        $this->account(101, $boundKol, [
            'wordpress_user_id' => 5001,
            'wordpress_user_email' => 'bound-wp@example.com',
            'display_name' => 'Old Bound Name',
            'email' => 'shared@example.com',
        ]);
        $this->account(102, null, [
            'wordpress_user_id' => 5002,
            'wordpress_user_email' => 'second-wp@example.com',
            'display_name' => 'Same Display',
            'email' => 'shared@example.com',
        ]);
        $before = $this->mirrorSnapshot();

        $gateway = $this->gateway();
        $gateway->shouldReceive('snapshot')->with('affiliates', null, null)->once()->andReturn($this->response([
            [
                'affiliate_id' => 101,
                'user_id' => 5001,
                'user_email' => 'bound-wp@example.com',
                'email' => 'shared@example.com',
                'display_name' => 'Provider Bound Name',
                'status' => 'active',
                'user_login' => 'boundlogin',
            ],
        ], 'cursor-2'));
        $gateway->shouldReceive('snapshot')->with('affiliates', 'cursor-2', null)->once()->andReturn($this->response([
            [
                'affiliate_id' => 102,
                'user_id' => 5002,
                'user_email' => 'second-wp@example.com',
                'email' => 'shared@example.com',
                'display_name' => 'Same Display',
                'status' => 'active',
            ],
            [
                'affiliate_id' => 103,
                'user_id' => 5003,
                'email' => 'third@example.com',
                'display_name' => 'Third',
                'username' => 'third-user',
                'status' => 'pending',
            ],
        ], null));

        $result = (new AffiliateAccountPreviewService($gateway))->preview();

        self::assertTrue($result['ok']);
        self::assertSame(2, $result['snapshot_pages']);
        self::assertSame(3, $result['provider_affiliate_count']);
        self::assertSame(3, $result['provider_unique_affiliate_ids']);
        self::assertSame(2, $result['erp_affiliate_mirror_count']);
        self::assertSame(1, $result['existing_bound_count']);
        self::assertSame(2, $result['canonical_kol_count']);
        self::assertSame(1, $result['kols_without_bound_affiliate']);
        self::assertSame(1, $result['metadata_drift_rows']);
        self::assertSame(2, $result['unbound_provider_affiliates']);
        self::assertSame(0, $result['missing_provider_ids_for_existing_bindings']);
        self::assertSame(0, $result['provider_status_drift_rows']);
        self::assertSame(0, $result['bound_wp_user_id_drift_rows']);
        self::assertSame(0, $result['bound_wp_email_drift_rows']);
        self::assertSame(['user_id' => 3, 'explicit_user_email' => 2, 'email' => 3, 'user_login' => 1, 'username' => 1], $result['identity_coverage_all']);
        self::assertSame(1, $result['identity_coverage_bound']['count']);
        self::assertSame(0, $result['collision_counts']['affiliate_id']);
        self::assertSame(['active' => 2, 'pending' => 1], $result['provider_status_counts']);
        self::assertContains('user_login', $result['provider_item_keys']);
        self::assertSame($before, $this->mirrorSnapshot(), 'Preview must not mutate Affiliate mirror rows.');
        self::assertSame($boundKol->id, KolAffiliateAccount::where('affiliate_id', 101)->value('kol_id'));
        self::assertNull(KolAffiliateAccount::where('affiliate_id', 102)->value('kol_id'), 'Shared email/display facts must never fuzzy-bind.');
        self::assertSame($unboundKol->id, Kol::where('display_name', 'No Affiliate')->value('id'));
    }

    public function test_missing_provider_affiliate_for_established_binding_fails_closed_without_mutation(): void
    {
        $bound = $this->kol('Bound');
        $this->account(201, $bound);
        $before = $this->mirrorSnapshot();
        $gateway = $this->gateway();
        $gateway->shouldReceive('snapshot')->once()->andReturn($this->response([]));

        $this->expectException(UnexpectedValueException::class);
        try {
            (new AffiliateAccountPreviewService($gateway))->preview();
        } finally {
            self::assertSame($before, $this->mirrorSnapshot());
        }
    }

    public function test_duplicate_provider_affiliate_id_fails_closed(): void
    {
        $gateway = $this->gateway();
        $gateway->shouldReceive('snapshot')->once()->andReturn($this->response([
            ['affiliate_id' => 301, 'user_id' => 7001, 'email' => 'a@example.com', 'status' => 'active'],
            ['affiliate_id' => 301, 'user_id' => 7002, 'email' => 'b@example.com', 'status' => 'active'],
        ]));

        $this->expectException(UnexpectedValueException::class);
        (new AffiliateAccountPreviewService($gateway))->preview();
    }

    public function test_wordpress_identity_collision_affecting_established_binding_fails_closed(): void
    {
        $bound = $this->kol('Bound');
        $this->account(401, $bound, ['wordpress_user_id' => 9001, 'wordpress_user_email' => 'same-wp@example.com']);
        $gateway = $this->gateway();
        $gateway->shouldReceive('snapshot')->once()->andReturn($this->response([
            ['affiliate_id' => 401, 'user_id' => 9001, 'user_email' => 'same-wp@example.com', 'email' => 'one@example.com', 'status' => 'active'],
            ['affiliate_id' => 402, 'user_id' => 9001, 'user_email' => 'other@example.com', 'email' => 'two@example.com', 'status' => 'active'],
        ]));

        $this->expectException(UnexpectedValueException::class);
        (new AffiliateAccountPreviewService($gateway))->preview();
    }

    public function test_json_command_emits_aggregate_redacted_result_only(): void
    {
        $gateway = $this->gateway();
        $gateway->shouldReceive('snapshot')->once()->andReturn($this->response([
            [
                'affiliate_id' => 501,
                'user_id' => 9501,
                'user_email' => 'private-wp@example.com',
                'email' => 'private-affiliate@example.com',
                'display_name' => 'Private Person',
                'status' => 'active',
                'user_login' => 'private-login',
            ],
        ]));
        $this->app->instance(AffiliateWpGateway::class, $gateway);

        $this->artisan('kol:affiliate-account-preview --json')
            ->expectsOutputToContain('"provider_affiliate_count":1')
            ->doesntExpectOutputToContain('private-wp@example.com')
            ->doesntExpectOutputToContain('private-affiliate@example.com')
            ->doesntExpectOutputToContain('Private Person')
            ->doesntExpectOutputToContain('private-login')
            ->assertSuccessful();
    }
}
