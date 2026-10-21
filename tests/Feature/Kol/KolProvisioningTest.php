<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use App\Modules\Kol\Application\AffiliateWp\KolProvisioningService;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\AffiliateWp\ConnectorException;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class KolProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECTION = 'test.example.com';

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'test_prov_'.uniqid(), 'label' => 'Test']);
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
        return $this->userWith(['kol.view', 'kol.operate', 'kol.approvals.manage', 'kol.affiliates.manage']);
    }

    private function makeKol(User $owner, array $overrides = []): Kol
    {
        return Kol::create(array_merge([
            'display_name' => 'Provision KOL',
            'status' => 'active',
            'relationship_stage' => 'prospect',
            'owner_id' => $owner->id,
            'approval_status' => 'approved',
            'contact_email' => 'kol@example.com',
        ], $overrides));
    }

    private function successResponse(int $affiliateId = 9001, int $userId = 7001): ConnectorResponse
    {
        return ConnectorResponse::fromArray([
            'ok' => true,
            'contract_version' => '1',
            'request_id' => 'req-'.uniqid(),
            'connection_key' => self::CONNECTION,
            'data' => [
                'affiliate_id' => $affiliateId,
                'user_id' => $userId,
                'user_email' => 'kol@example.com',
                'status' => 'active',
            ],
        ]);
    }

    private function mockGateway(): AffiliateWpGateway&MockInterface
    {
        $mock = Mockery::mock(AffiliateWpGateway::class);
        $mock->shouldReceive('descriptor')->andReturn(['connection_key' => self::CONNECTION])->byDefault();
        $this->app->instance(AffiliateWpGateway::class, $mock);

        return $mock;
    }

    private function service(): KolProvisioningService
    {
        return $this->app->make(KolProvisioningService::class);
    }

    // -------------------------------------------------------------------------
    // Success path
    // -------------------------------------------------------------------------

    public function test_approved_kol_provisions_to_active_with_verified_affiliate_and_wp_user_ids(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);
        $mock = $this->mockGateway();
        $mock->shouldReceive('provisionAffiliate')
            ->once()
            ->andReturn($this->successResponse(9001, 7001));

        $account = $this->service()->provision(self::CONNECTION, $kol->id, $manager);

        self::assertSame('active', $account->provisioning_status);
        self::assertSame(9001, $account->affiliate_id);
        self::assertSame(7001, $account->wordpress_user_id);
        self::assertSame('kol@example.com', $account->wordpress_user_email);
        self::assertSame('active', $account->provider_status);
        self::assertSame('erp_provisioned', $account->origin);
        self::assertSame($kol->id, $account->kol_id);
        self::assertNull($account->last_error_code);
        self::assertNull($account->last_error_message);
    }

    public function test_provision_accepts_andy_core_v153_readback_keys(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);
        $mock = $this->mockGateway();
        $mock->shouldReceive('provisionAffiliate')
            ->once()
            ->andReturn(ConnectorResponse::fromArray([
                'ok' => true,
                'contract_version' => '1',
                'request_id' => 'req-v153',
                'connection_key' => self::CONNECTION,
                'data' => [
                    'affiliate_id' => 9002,
                    'wp_user_id' => 7002,
                    'affiliate_status' => 'active',
                    'created_user' => true,
                    'created_affiliate' => true,
                ],
            ]));

        $account = $this->service()->provision(self::CONNECTION, $kol->id, $manager);

        self::assertSame('active', $account->provisioning_status);
        self::assertSame(9002, $account->affiliate_id);
        self::assertSame(7002, $account->wordpress_user_id);
        self::assertSame('active', $account->provider_status);
    }

    // -------------------------------------------------------------------------
    // Provider failure → error
    // -------------------------------------------------------------------------

    public function test_provider_retryable_error_transitions_to_error_state_and_preserves_row(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);
        $mock = $this->mockGateway();
        $mock->shouldReceive('provisionAffiliate')
            ->once()
            ->andThrow(ConnectorException::retryable('Provider timeout.', 'PROVIDER_UNAVAILABLE'));

        $account = $this->service()->provision(self::CONNECTION, $kol->id, $manager);

        self::assertSame('error', $account->provisioning_status);
        self::assertSame('PROVIDER_UNAVAILABLE', $account->last_error_code);
        self::assertNotNull($account->last_error_message);
        self::assertSame(1, KolAffiliateAccount::where('kol_id', $kol->id)->count());
    }

    public function test_provider_non_retryable_error_also_transitions_to_error_state(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);
        $mock = $this->mockGateway();
        $mock->shouldReceive('provisionAffiliate')
            ->once()
            ->andThrow(ConnectorException::nonRetryable('Validation failed.', 'VALIDATION_FAILED'));

        $account = $this->service()->provision(self::CONNECTION, $kol->id, $manager);

        self::assertSame('error', $account->provisioning_status);
        self::assertSame('VALIDATION_FAILED', $account->last_error_code);
    }

    // -------------------------------------------------------------------------
    // Conflict
    // -------------------------------------------------------------------------

    public function test_provider_affiliate_id_conflict_transitions_to_conflict_state(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);
        $mock = $this->mockGateway();
        $mock->shouldReceive('provisionAffiliate')
            ->once()
            ->andThrow(ConnectorException::nonRetryable('Affiliate ID conflict.', 'AFFILIATE_ID_CONFLICT'));

        $account = $this->service()->provision(self::CONNECTION, $kol->id, $manager);

        self::assertSame('conflict', $account->provisioning_status);
        self::assertSame('AFFILIATE_ID_CONFLICT', $account->last_error_code);
        self::assertNull($account->affiliate_id);
    }

    // -------------------------------------------------------------------------
    // Read-back mismatch
    // -------------------------------------------------------------------------

    public function test_response_without_active_status_transitions_to_readback_mismatch(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);
        $mock = $this->mockGateway();
        $mock->shouldReceive('provisionAffiliate')
            ->once()
            ->andReturn(ConnectorResponse::fromArray([
                'ok' => true,
                'contract_version' => '1',
                'request_id' => 'req-1',
                'connection_key' => self::CONNECTION,
                'data' => [
                    'affiliate_id' => 9002,
                    'user_id' => 7002,
                    'status' => 'pending',
                ],
            ]));

        $account = $this->service()->provision(self::CONNECTION, $kol->id, $manager);

        self::assertSame('readback_mismatch', $account->provisioning_status);
        self::assertSame('READBACK_MISMATCH', $account->last_error_code);
    }

    public function test_response_missing_affiliate_id_transitions_to_readback_mismatch(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);
        $mock = $this->mockGateway();
        $mock->shouldReceive('provisionAffiliate')
            ->once()
            ->andReturn(ConnectorResponse::fromArray([
                'ok' => true,
                'contract_version' => '1',
                'request_id' => 'req-2',
                'connection_key' => self::CONNECTION,
                'data' => [
                    'user_id' => 7003,
                    'status' => 'active',
                ],
            ]));

        $account = $this->service()->provision(self::CONNECTION, $kol->id, $manager);

        self::assertSame('readback_mismatch', $account->provisioning_status);
    }

    public function test_response_missing_wordpress_user_id_transitions_to_readback_mismatch(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);
        $mock = $this->mockGateway();
        $mock->shouldReceive('provisionAffiliate')
            ->once()
            ->andReturn(ConnectorResponse::fromArray([
                'ok' => true,
                'contract_version' => '1',
                'request_id' => 'req-3',
                'connection_key' => self::CONNECTION,
                'data' => [
                    'affiliate_id' => 9004,
                    'status' => 'active',
                ],
            ]));

        $account = $this->service()->provision(self::CONNECTION, $kol->id, $manager);

        self::assertSame('readback_mismatch', $account->provisioning_status);
    }

    // -------------------------------------------------------------------------
    // Retry idempotency
    // -------------------------------------------------------------------------

    public function test_retry_after_error_reuses_same_row_and_same_idempotency_key(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);
        $mock = $this->mockGateway();

        $capturedKey = null;
        $mock->shouldReceive('provisionAffiliate')
            ->twice()
            ->andReturnUsing(function (array $payload, string $key) use (&$capturedKey): never {
                $capturedKey = $key;
                throw ConnectorException::retryable('Timeout.', 'PROVIDER_UNAVAILABLE');
            });

        $first = $this->service()->provision(self::CONNECTION, $kol->id, $manager);
        $firstKey = $first->provision_idempotency_key;
        self::assertSame('error', $first->provisioning_status);

        $capturedKey = null;
        $second = $this->service()->provision(self::CONNECTION, $kol->id, $manager);

        self::assertSame('error', $second->provisioning_status);
        self::assertSame($firstKey, $second->provision_idempotency_key);
        self::assertSame($firstKey, $capturedKey);
        self::assertSame(1, KolAffiliateAccount::where('kol_id', $kol->id)->count());
    }

    public function test_retry_on_conflict_state_reuses_same_row_and_idempotency_key(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);

        // Seed a conflict row directly
        $existing = KolAffiliateAccount::create([
            'connection_key' => self::CONNECTION,
            'kol_id' => $kol->id,
            'affiliate_id' => null,
            'origin' => 'erp_provisioned',
            'sync_status' => 'never_synced',
            'reconciliation_status' => 'present',
            'provisioning_status' => 'conflict',
            'provision_idempotency_key' => 'stable-key-abc',
            'last_error_code' => 'AFFILIATE_ID_CONFLICT',
        ]);

        $mock = $this->mockGateway();
        $capturedKey = null;
        $mock->shouldReceive('provisionAffiliate')
            ->once()
            ->andReturnUsing(function (array $payload, string $key) use (&$capturedKey): ConnectorResponse {
                $capturedKey = $key;

                return ConnectorResponse::fromArray([
                    'ok' => true, 'contract_version' => '1', 'request_id' => 'r1',
                    'connection_key' => self::CONNECTION,
                    'data' => ['affiliate_id' => 9005, 'user_id' => 7005, 'status' => 'active'],
                ]);
            });

        $account = $this->service()->provision(self::CONNECTION, $kol->id, $manager);

        self::assertSame($existing->id, $account->id);
        self::assertSame('stable-key-abc', $capturedKey);
        self::assertSame('active', $account->provisioning_status);
        self::assertSame(1, KolAffiliateAccount::where('kol_id', $kol->id)->count());
    }

    // -------------------------------------------------------------------------
    // State guards
    // -------------------------------------------------------------------------

    public function test_already_active_kol_is_rejected_without_calling_gateway(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);

        KolAffiliateAccount::create([
            'connection_key' => self::CONNECTION,
            'kol_id' => $kol->id,
            'affiliate_id' => 9010,
            'wordpress_user_id' => 7010,
            'origin' => 'erp_provisioned',
            'sync_status' => 'never_synced',
            'reconciliation_status' => 'present',
            'provisioning_status' => 'active',
            'provision_idempotency_key' => 'done-key',
        ]);

        $mock = $this->mockGateway();
        $mock->shouldNotReceive('provisionAffiliate');

        $this->expectException(DomainException::class);
        $this->service()->provision(self::CONNECTION, $kol->id, $manager);
    }

    public function test_non_approved_kol_is_rejected(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager, ['approval_status' => 'pending']);

        $mock = $this->mockGateway();
        $mock->shouldNotReceive('provisionAffiliate');

        $this->expectException(DomainException::class);
        $this->service()->provision(self::CONNECTION, $kol->id, $manager);
    }

    public function test_rejected_kol_is_also_rejected_from_provisioning(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager, ['approval_status' => 'rejected']);

        $mock = $this->mockGateway();
        $mock->shouldNotReceive('provisionAffiliate');

        $this->expectException(DomainException::class);
        $this->service()->provision(self::CONNECTION, $kol->id, $manager);
    }

    public function test_externally_sourced_affiliate_row_prevents_re_provisioning(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);

        // External sync row with authoritative affiliate_id already bound to this KOL
        KolAffiliateAccount::create([
            'connection_key' => self::CONNECTION,
            'kol_id' => $kol->id,
            'affiliate_id' => 5001,
            'origin' => 'external_sync',
            'sync_status' => 'synced',
            'reconciliation_status' => 'present',
            'provisioning_status' => 'not_requested',
        ]);

        $mock = $this->mockGateway();
        $mock->shouldNotReceive('provisionAffiliate');

        $this->expectException(DomainException::class);
        $this->service()->provision(self::CONNECTION, $kol->id, $manager);
    }

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    public function test_specialist_without_affiliates_manage_is_forbidden(): void
    {
        $specialist = $this->userWith(['kol.view', 'kol.operate']);
        $manager = $this->manager();
        $kol = $this->makeKol($manager);

        $mock = $this->mockGateway();
        $mock->shouldNotReceive('provisionAffiliate');

        $this->expectException(AuthorizationException::class);
        $this->service()->provision(self::CONNECTION, $kol->id, $specialist);
    }

    // -------------------------------------------------------------------------
    // Route-level permission
    // -------------------------------------------------------------------------

    public function test_provision_route_requires_kol_affiliates_manage(): void
    {
        $specialist = $this->userWith(['kol.view', 'kol.operate']);
        $manager = $this->manager();
        $kol = $this->makeKol($manager);

        $this->actingAs($specialist)
            ->post('/kol/affiliatewp/provision', ['kol_id' => $kol->id])
            ->assertForbidden();
    }

    public function test_provision_route_manager_succeeds_and_redirects(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);

        $mock = $this->mockGateway();
        $mock->shouldReceive('provisionAffiliate')
            ->once()
            ->andReturn($this->successResponse());

        $this->actingAs($manager)
            ->post('/kol/affiliatewp/provision', ['kol_id' => $kol->id])
            ->assertRedirect();

        self::assertSame('active', KolAffiliateAccount::where('kol_id', $kol->id)->first()->provisioning_status);
    }

    public function test_provision_route_surfaces_provider_error_as_operator_feedback(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);

        $mock = $this->mockGateway();
        $mock->shouldReceive('provisionAffiliate')
            ->once()
            ->andThrow(ConnectorException::retryable('Synthetic provider timeout.', 'PROVIDER_UNAVAILABLE'));

        $this->actingAs($manager)
            ->post('/kol/affiliatewp/provision', ['kol_id' => $kol->id])
            ->assertRedirect()
            ->assertSessionHasErrors('affiliatewp_provision');

        $account = KolAffiliateAccount::where('kol_id', $kol->id)->firstOrFail();
        self::assertSame('error', $account->provisioning_status);
        self::assertSame('PROVIDER_UNAVAILABLE', $account->last_error_code);
    }

    public function test_provision_route_surfaces_readback_mismatch_as_operator_feedback(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);

        $mock = $this->mockGateway();
        $mock->shouldReceive('provisionAffiliate')
            ->once()
            ->andReturn(ConnectorResponse::fromArray([
                'ok' => true,
                'contract_version' => '1',
                'request_id' => 'route-readback',
                'connection_key' => self::CONNECTION,
                'data' => ['affiliate_id' => 9901, 'user_id' => 7901, 'status' => 'pending'],
            ]));

        $this->actingAs($manager)
            ->post('/kol/affiliatewp/provision', ['kol_id' => $kol->id])
            ->assertRedirect()
            ->assertSessionHasErrors('affiliatewp_provision');

        self::assertSame('readback_mismatch', KolAffiliateAccount::where('kol_id', $kol->id)->firstOrFail()->provisioning_status);
    }

    // -------------------------------------------------------------------------
    // Idempotency key sent to gateway
    // -------------------------------------------------------------------------

    public function test_provision_idempotency_key_is_sent_to_gateway_and_persisted(): void
    {
        $manager = $this->manager();
        $kol = $this->makeKol($manager);
        $mock = $this->mockGateway();

        $capturedKey = null;
        $capturedPayload = null;
        $mock->shouldReceive('provisionAffiliate')
            ->once()
            ->andReturnUsing(function (array $payload, string $key) use (&$capturedKey, &$capturedPayload): ConnectorResponse {
                $capturedKey = $key;
                $capturedPayload = $payload;

                return ConnectorResponse::fromArray([
                    'ok' => true, 'contract_version' => '1', 'request_id' => 'r1',
                    'connection_key' => self::CONNECTION,
                    'data' => ['affiliate_id' => 9020, 'user_id' => 7020, 'status' => 'active'],
                ]);
            });

        $account = $this->service()->provision(self::CONNECTION, $kol->id, $manager);

        self::assertNotNull($capturedKey);
        self::assertSame($capturedKey, $account->provision_idempotency_key);
        self::assertNotEmpty($capturedKey);
        self::assertSame([
            'erp_kol_id' => $kol->id,
            'email' => 'kol@example.com',
            'display_name' => 'Provision KOL',
            'preferred_username' => 'erp-kol-'.$kol->id,
            'requested_affiliate_status' => 'active',
            'payment_email' => 'kol@example.com',
        ], $capturedPayload);
    }
}
