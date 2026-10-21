<?php

namespace Tests\Feature\Sync;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class WordPressConnectorReadOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    public const CONNECTION = 'test.example.com';

    public function test_unified_connector_runs_affiliate_coupon_referral_and_payout_imports_with_row_level_sync_evidence(): void
    {
        $this->bindGateway();
        $user = $this->userWithPermissions(['sync.view', 'sync.operate', 'kol.affiliates.manage', 'kol.payouts.manage']);

        foreach (['affiliates', 'coupons', 'referrals', 'payouts'] as $resource) {
            $this->actingAs($user)->post('/sync/wpapi/'.$resource)->assertRedirect()->assertSessionHas('success');
        }

        $this->assertDatabaseHas('kol_affiliate_accounts', ['connection_key' => self::CONNECTION, 'affiliate_id' => 101]);
        $this->assertDatabaseHas('kol_affiliate_coupons', ['connection_key' => self::CONNECTION, 'coupon_id' => 201]);
        $this->assertDatabaseHas('kol_affiliate_referrals', ['connection_key' => self::CONNECTION, 'referral_id' => 301, 'provider_status' => 'failed']);
        $this->assertDatabaseHas('kol_affiliate_payouts', ['connection_key' => self::CONNECTION, 'payout_id' => 401, 'provider_status' => 'paid']);

        foreach ([
            'wordpress_affiliates_snapshot' => '101',
            'wordpress_coupons_snapshot' => '201',
            'wordpress_referrals_snapshot' => '301',
            'wordpress_payouts_snapshot' => '401',
        ] as $runType => $reference) {
            $this->assertDatabaseHas('sync_runs', [
                'run_type' => $runType,
                'status' => 'completed',
                'requested_items' => 1,
                'succeeded_items' => 1,
                'failed_items' => 0,
            ]);
            $this->assertDatabaseHas('sync_run_items', ['provider_reference' => $reference, 'status' => 'succeeded']);
            $run = DB::table('sync_runs')->where('run_type', $runType)->latest('id')->firstOrFail();
            $context = json_decode((string) $run->context, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(1, $context['fetched_items']);
            self::assertSame(1, $context['result']['synced']);
            self::assertSame('wordpress_connector', $context['surface']);
        }

        $this->actingAs($user)->get('/sync/wpapi')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Sync/WpApi')
            ->where('permissions.can_sync_kol', true)
            ->where('permissions.can_sync_payouts', true)
            ->where('runs.affiliates.status', 'completed')
            ->where('runs.coupons.status', 'completed')
            ->where('runs.referrals.status', 'completed')
            ->where('runs.payouts.status', 'completed')
            ->where('emailTemplates.status', 'ready')
            ->where('emailTemplates.read_only', true)
            ->where('emailTemplates.resource_contract_version', 'email-os-published-v1')
            ->where('emailTemplates.count', 1)
            ->where('emailTemplates.items.0.template_key', 'wordpress:reset_password')
        );
    }

    public function test_provider_failure_creates_failed_sync_run_and_does_not_report_success(): void
    {
        $this->bindGateway(failResource: 'affiliates');
        $user = $this->userWithPermissions(['sync.operate', 'kol.affiliates.manage']);

        $this->actingAs($user)->post('/sync/wpapi/affiliates')->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseHas('sync_runs', [
            'run_type' => 'wordpress_affiliates_snapshot',
            'status' => 'failed',
            'requested_items' => 1,
            'failed_items' => 1,
        ]);
        $this->assertDatabaseHas('sync_run_items', [
            'operation' => 'wordpress.affiliates_snapshot',
            'status' => 'failed',
            'error_code' => 'provider_error',
        ]);
        $this->assertDatabaseCount('kol_affiliate_accounts', 0);
    }

    public function test_connector_view_is_sync_scoped_but_kol_actions_require_sync_and_kol_permissions(): void
    {
        $this->bindGateway();
        $viewer = $this->userWithPermissions(['sync.view']);
        $this->actingAs($viewer)->get('/sync/wpapi')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('permissions.can_sync_subscribers', false)
            ->where('permissions.can_sync_kol', false)
            ->where('permissions.can_sync_payouts', false)
        );

        $syncOnly = $this->userWithPermissions(['sync.operate']);
        $this->actingAs($syncOnly)->post('/sync/wpapi/affiliates')->assertForbidden();

        $kolOnly = $this->userWithPermissions(['kol.affiliates.manage']);
        $this->actingAs($kolOnly)->post('/sync/wpapi/affiliates')->assertForbidden();

        $this->actingAs($syncOnly)->post('/sync/wpapi/payouts')->assertForbidden();

        $payoutOnly = $this->userWithPermissions(['kol.payouts.manage']);
        $this->actingAs($payoutOnly)->post('/sync/wpapi/payouts')->assertForbidden();
    }

    private function bindGateway(?string $failResource = null): void
    {
        $this->app->bind(AffiliateWpGateway::class, fn () => new class($failResource) implements AffiliateWpGateway {
            public function __construct(private readonly ?string $failResource) {}

            public function descriptor(): array
            {
                return ['configured' => true, 'url' => 'https://wp.test', 'connection_key' => WordPressConnectorReadOrchestrationTest::CONNECTION, 'key_id' => 'key'];
            }

            public function health(): ConnectorResponse
            {
                return $this->response([]);
            }

            public function checkAffiliateIdentity(string $email): ConnectorResponse { throw new \LogicException('not used'); }
            public function snapshot(string $resource, ?string $cursor = null, ?string $updatedAfter = null): ConnectorResponse
            {
                if ($resource === $this->failResource) {
                    throw new \RuntimeException('provider unavailable');
                }
                $items = match ($resource) {
                    'affiliates' => [[
                        'affiliate_id' => 101, 'user_id' => 501, 'display_name' => 'Affiliate 101',
                        'email' => 'affiliate101@example.test', 'status' => 'active',
                    ]],
                    'coupons' => [[
                        'coupon_id' => 201, 'code' => 'KOL201', 'normalized_code' => 'kol201',
                        'discount_type' => 'percent', 'amount' => '10', 'status' => 'publish',
                        'date_expires' => null, 'linked_affiliate_id' => 101,
                    ]],
                    'referrals' => [[
                        'referral_id' => 301, 'affiliate_id' => 101, 'reference' => 'missing-order',
                        'description' => 'Referral 301', 'amount' => '12.50', 'currency' => 'USD',
                        'status' => 'failed', 'context' => 'woocommerce',
                        'referred_at' => '2026-09-01T00:00:00+00:00', 'payout_id' => 0,
                    ]],
                    'payouts' => [[
                        'payout_id' => 401, 'affiliate_id' => 101, 'referral_ids' => [301],
                        'amount' => '12.50', 'currency' => 'USD', 'payout_method' => 'manual',
                        'status' => 'paid', 'date' => '2026-09-01T00:00:00+00:00',
                    ]],
                    'email-templates' => [[
                        'template_key' => 'wordpress:reset_password', 'provider' => 'wordpress',
                        'label' => 'Reset Password', 'status' => 'published', 'runtime_enabled' => true,
                        'published_version' => 3, 'content_hash_sha256' => str_repeat('a', 64),
                        'published_at' => '2026-09-15 17:00:00',
                    ]],
                    default => throw new \LogicException('unexpected resource'),
                };

                return $this->response([
                    ...($resource === 'email-templates' ? ['resource_contract_version' => 'email-os-published-v1'] : []),
                    'items' => $items,
                    'next_cursor' => null,
                ]);
            }

            public function provisionAffiliate(array $payload, string $idempotencyKey): ConnectorResponse { throw new \LogicException('not used'); }
            public function setAffiliateStatus(int $affiliateId, string $status, string $idempotencyKey): ConnectorResponse { throw new \LogicException('not used'); }
            public function updateWordpressPassword(int $wordpressUserId, array $payload, string $idempotencyKey): ConnectorResponse { throw new \LogicException('not used'); }
            public function checkCoupon(string $code): ConnectorResponse { throw new \LogicException('not used'); }
            public function provisionCoupon(array $payload, string $idempotencyKey): ConnectorResponse { throw new \LogicException('not used'); }
            public function completePayout(array $payload, string $idempotencyKey): ConnectorResponse { throw new \LogicException('not used'); }

            private function response(array $data): ConnectorResponse
            {
                return ConnectorResponse::fromArray([
                    'ok' => true,
                    'contract_version' => '1',
                    'request_id' => uniqid('connector-', true),
                    'connection_key' => WordPressConnectorReadOrchestrationTest::CONNECTION,
                    'data' => $data,
                ]);
            }
        });
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['key' => 'connector-read-'.uniqid(), 'label' => 'Connector Read']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
