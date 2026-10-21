<?php

namespace Tests\Feature\Sync;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class WordPressConnectorSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_connection_secret_is_encrypted_and_blank_rotation_input_preserves_it(): void
    {
        $user = $this->userWithPermissions(['sync.operate']);

        $this->actingAs($user)->put('/sync/wpapi/connection', [
            'site_url' => 'https://localdev.beyourlover.com',
            'connection_key' => 'beyourlover.com',
            'key_id' => 'erp-key-1',
            'shared_secret' => 'super-secret-value',
            'verify_tls' => true,
        ])->assertRedirect();

        $stored = DB::table('wordpress_connector_settings')->where('id', 1)->first();
        self::assertNotNull($stored);
        self::assertNotSame('super-secret-value', $stored->shared_secret);
        self::assertSame('super-secret-value', Crypt::decryptString($stored->shared_secret));
        $ciphertext = $stored->shared_secret;
        $this->actingAs($user)->put('/sync/wpapi/connection', [
            'site_url' => 'https://localdev.beyourlover.com',
            'connection_key' => 'beyourlover.com',
            'key_id' => 'erp-key-2',
            'shared_secret' => '',
            'verify_tls' => false,
        ])->assertRedirect();

        $updated = DB::table('wordpress_connector_settings')->where('id', 1)->first();
        self::assertSame($ciphertext, $updated->shared_secret);
        self::assertSame('erp-key-2', $updated->key_id);
        self::assertFalse((bool) $updated->verify_tls);
    }

    public function test_health_result_is_persisted_without_secret_material(): void
    {
        $user = $this->userWithPermissions(['sync.operate']);
        $this->app->bind(AffiliateWpGateway::class, fn () => new class implements AffiliateWpGateway {
            public function descriptor(): array { return ['configured' => true, 'url' => 'https://wp.test', 'connection_key' => 'beyourlover.com', 'key_id' => 'key']; }
            public function health(): ConnectorResponse { return new ConnectorResponse(true, '1', 'req-1', 'beyourlover.com', [
                'andy_core_version' => '1.5.3', 'wp_version' => '7.0.2',
                'woocommerce_active' => true, 'affiliatewp_active' => true,
                'writable' => true, 'secret' => 'must-not-persist',
            ]); }
            public function checkAffiliateIdentity(string $email): ConnectorResponse { throw new \LogicException('not used'); }
            public function snapshot(string $resource, ?string $cursor = null, ?string $updatedAfter = null): ConnectorResponse { throw new \LogicException('not used'); }
            public function provisionAffiliate(array $payload, string $idempotencyKey): ConnectorResponse { throw new \LogicException('not used'); }
            public function setAffiliateStatus(int $affiliateId, string $status, string $idempotencyKey): ConnectorResponse { throw new \LogicException('not used'); }
            public function updateWordpressPassword(int $wordpressUserId, array $payload, string $idempotencyKey): ConnectorResponse { throw new \LogicException('not used'); }
            public function checkCoupon(string $code): ConnectorResponse { throw new \LogicException('not used'); }
            public function provisionCoupon(array $payload, string $idempotencyKey): ConnectorResponse { throw new \LogicException('not used'); }
            public function completePayout(array $payload, string $idempotencyKey): ConnectorResponse { throw new \LogicException('not used'); }
        });

        $this->actingAs($user)->post('/sync/wpapi/health')->assertRedirect();

        $stored = DB::table('wordpress_connector_settings')->where('id', 1)->first();
        self::assertSame('connected', $stored->last_health_status);
        $payload = json_decode($stored->last_health_payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('1.5.3', $payload['andy_core_version']);
        self::assertSame('1', $payload['contract_version']);
        self::assertArrayNotHasKey('secret', $payload);
        self::assertStringNotContainsString('must-not-persist', $stored->last_health_payload);
    }

    public function test_connector_page_never_returns_shared_secret(): void
    {
        config([
            'services.andy_core.url' => 'https://wp.test',
            'services.andy_core.connection_key' => 'beyourlover.com',
            'services.andy_core.key_id' => 'key-1',
            'services.andy_core.shared_secret' => 'runtime-secret-never-render',
        ]);
        $user = $this->userWithPermissions(['sync.view']);

        $this->actingAs($user)->get('/sync/wpapi')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Sync/WpApi')
            ->where('connection.secret_set', true)
            ->missing('connection.shared_secret')
        );
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['key' => 'wp-connector-'.uniqid(), 'label' => 'WP Connector']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
