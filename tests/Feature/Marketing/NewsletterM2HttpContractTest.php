<?php

namespace Tests\Feature\Marketing;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Isolated, synthetically signed Core HTTP contract E2E:
 * real ERP HTTP gateway -> sync service -> persisted Marketing tables.
 * Core's native server handler is NOT asserted as shipped by this test.
 */
final class NewsletterM2HttpContractTest extends TestCase
{
    use RefreshDatabase;

    private const Site = 'www.beyourlover.com';
    private const Connection = 'beyourlover.com';
    private const Secret = 'synthetic-m2-contract-hmac-key';

    public function test_signed_two_source_http_snapshot_imports_verified_states_and_replay_is_idempotent(): void
    {
        config([
            'services.andy_core.newsletter_snapshot_enabled' => true,
            'services.andy_core.url' => 'https://wp.example.test',
            'services.andy_core.connection_key' => self::Connection,
            'services.andy_core.key_id' => 'synthetic-key-id',
            'services.andy_core.shared_secret' => self::Secret,
            'services.andy_core.verify_tls' => true,
        ]);

        $current = ['status' => 'pending', 'timestamp' => '2026-10-10T01:00:00Z'];
        $count = 0;
        $paths = [];
        Http::fake(function (Request $request) use (&$current, &$count, &$paths) {
            $count++;
            $parts = parse_url($request->url());
            $path = ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
            $paths[] = $path;
            parse_str($parts['query'] ?? '', $query);
            $timestamp = $request->header('X-YBY-Timestamp')[0] ?? '';
            $nonce = $request->header('X-YBY-Nonce')[0] ?? '';
            $connection = $request->header('X-YBY-Connection-Key')[0] ?? '';
            $canonical = implode("\n", ['GET', $path, $timestamp, $nonce, $connection, '', hash('sha256', '')]);
            $signature = $request->header('X-YBY-Signature')[0] ?? '';
            if ($connection !== self::Connection || $nonce === ''
                || ($request->header('X-YBY-Signature-Version')[0] ?? '') !== 'v1'
                || ! hash_equals(hash_hmac('sha256', $canonical, self::Secret), $signature)) {
                return Http::response(['ok' => false, 'code' => 'INVALID_SIGNATURE'], 401);
            }

            $native = ($query['source'] ?? null) === 'andy_core_newsletter';
            if (!$native && array_key_exists('source', $query)) {
                return Http::response(['ok' => false, 'code' => 'INVALID_SOURCE'], 400);
            }
            $items = $native ? [[
                // Same digest and site_key scheme as Core's YBY_Newsletter_Consent::external_id().
                'external_subscription_id' => 'andy_core_newsletter:'.hash('sha256', self::Site."\n".'user@example.test'),
                'email' => 'User@Example.test',
                'provider' => 'wordpress',
                'source_site' => self::Site,
                'consent_source' => 'andy_core_newsletter_form',
                'status' => $current['status'],
                'status_updated_at' => $current['timestamp'],
            ]] : [[
                'external_subscription_id' => 'elementor_signup:'.hash('sha256', self::Connection."\n".'user@example.test'),
                'email' => 'user@example.test',
                'provider' => 'wordpress',
                'source_site' => self::Connection,
                'status' => 'unknown',
                'consent_source' => 'wordpress_elementor_signup',
                'status_updated_at' => '2026-10-09T00:00:00Z',
            ]];
            return Http::response([
                'ok' => true, 'contract_version' => '1',
                'request_id' => 'isolated-'.(string) $count,
                'connection_key' => self::Connection,
                'data' => [
                    'items' => $items,
                    'next_cursor' => null,
                    'source_site' => $native ? self::Site : self::Connection,
                ],
            ], 200);
        });

        $operator = $this->operator();
        foreach ([
            ['pending', '2026-10-10T01:00:00Z', 'pending'],
            ['subscribed', '2026-10-10T02:00:00Z', 'subscribed'],
            ['unsubscribed', '2026-10-10T03:00:00Z', 'unsubscribed'],
            ['subscribed', '2026-10-10T02:00:00Z', 'unsubscribed'], // delayed snapshot
        ] as [$status, $at, $expected]) {
            $current = ['status' => $status, 'timestamp' => $at];
            $this->actingAs($operator)->post('/sync/wpapi/subscribers')
                ->assertRedirect()->assertSessionHas('success');
            $this->assertDatabaseHas('marketing_subscriptions', [
                'external_subscription_id' => 'andy_core_newsletter:'.hash('sha256', self::Site."\n".'user@example.test'),
                'source_site' => self::Site,
                'status' => $expected,
            ]);
        }
        $this->assertSame(8, $count);
        $this->assertDatabaseCount('marketing_subscribers', 1);
        $this->assertDatabaseCount('marketing_subscriptions', 2);
        $this->assertCount(4, array_filter($paths, fn ($path) => str_contains($path, 'source=andy_core_newsletter')));
        $this->assertCount(4, array_filter($paths, fn ($path) => ! str_contains($path, 'source=')));
    }

    public function test_native_http_503_fails_closed_and_does_not_count_as_success(): void
    {
        config([
            'services.andy_core.newsletter_snapshot_enabled' => true,
            'services.andy_core.url' => 'https://wp.example.test',
            'services.andy_core.connection_key' => self::Connection,
            'services.andy_core.key_id' => 'synthetic-key-id',
            'services.andy_core.shared_secret' => self::Secret,
        ]);
        Http::fake(function (Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
            if (($query['source'] ?? null) === 'andy_core_newsletter') {
                return Http::response(['ok' => false, 'code' => 'PROVIDER_UNAVAILABLE'], 503);
            }
            return Http::response([
                'ok' => true, 'contract_version' => '1', 'request_id' => 'legacy-okay',
                'connection_key' => self::Connection,
                'data' => ['items' => [], 'next_cursor' => null, 'source_site' => self::Connection],
            ], 200);
        });

        $this->actingAs($this->operator())->post('/sync/wpapi/subscribers')
            ->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseCount('marketing_subscribers', 0);
        $this->assertDatabaseHas('sync_run_items', [
            'operation' => 'wordpress.subscriber_snapshot', 'provider_reference' => 'andy_core_newsletter', 'status' => 'failed',
        ]);
    }

    private function operator(): User
    {
        $role = Role::create(['key' => 'm2-http-'.uniqid(), 'label' => 'Synthetic M2 operator']);
        foreach (['sync.view', 'sync.operate', 'marketing.view', 'marketing.operate'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
