<?php

namespace Tests\Feature\Marketing;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Marketing\Application\Contracts\WordPressSubscriberGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NewsletterM2SyncTest extends TestCase
{
    use RefreshDatabase;

    private const LegacyId = 'elementor_signup:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const NativeId = 'andy_core_newsletter:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function test_dual_source_upsert_keeps_single_email_and_two_independent_source_facts_after_replay(): void
    {
        $this->enableNative();
        $legacy = [['external_subscription_id' => self::LegacyId, 'email' => ' User@Example.test ',
            'status' => 'unknown', 'source_site' => 'beyourlover.com']];
        $native = [$this->native('pending', '2026-10-10T01:00:00Z')];
        $this->bindSources($legacy, $native);
        $operator = $this->operator();
        $this->actingAs($operator)->post('/sync/wpapi/subscribers')->assertRedirect();
        $this->actingAs($operator)->post('/sync/wpapi/subscribers')->assertRedirect();

        $this->assertDatabaseCount('marketing_subscribers', 1);
        $this->assertDatabaseCount('marketing_subscriptions', 2);
        $this->assertDatabaseHas('marketing_subscribers', ['email' => 'user@example.test']);
        $this->assertDatabaseHas('marketing_subscriptions', ['external_subscription_id' => self::NativeId, 'status' => 'pending']);
        $this->assertDatabaseHas('marketing_subscriptions', ['external_subscription_id' => self::LegacyId, 'status' => 'unknown']);
        $this->assertDatabaseHas('sync_runs', ['run_type' => 'wordpress_subscribers_snapshot', 'status' => 'completed']);
    }

    public function test_native_double_opt_in_unsubscribe_pending_and_suppression_never_rewind(): void
    {
        $this->enableNative();
        $operator = $this->operator();
        $states = [
            ['pending', '2026-10-10T01:00:00Z', 'pending'],
            ['subscribed', '2026-10-10T01:10:00Z', 'subscribed'],
            ['unsubscribed', '2026-10-10T02:00:00Z', 'unsubscribed'],
            ['subscribed', '2026-10-10T01:10:00Z', 'unsubscribed'], // delayed subscribe
            ['subscribed', '2026-10-10T02:00:00Z', 'unsubscribed'], // equal-time tie
            ['pending', '2026-10-10T03:00:00Z', 'pending'], // explicit new opt-in
            ['subscribed', '2026-10-10T04:00:00Z', 'subscribed'],
            ['suppressed', '2026-10-10T05:00:00Z', 'suppressed'],
            ['pending', '2026-10-10T06:00:00Z', 'suppressed'], // suppression is sticky
            ['subscribed', '2026-10-10T07:00:00Z', 'suppressed'],
        ];
        foreach ($states as [$state, $at, $expected]) {
            $this->bindSources([], [$this->native($state, $at)]);
            $this->actingAs($operator)->post('/sync/wpapi/subscribers')->assertRedirect();
            $this->assertDatabaseHas('marketing_subscriptions', ['external_subscription_id' => self::NativeId, 'status' => $expected]);
        }
        $this->assertDatabaseCount('marketing_subscribers', 1);
        $this->assertDatabaseCount('marketing_subscriptions', 1);
    }

    public function test_native_validation_rejects_source_mismatch_missing_time_and_invalid_identity(): void
    {
        $this->enableNative();
        $invalid = [
            ['external_subscription_id' => self::NativeId, 'email' => 'bad-address', 'status' => 'subscribed', 'status_updated_at' => '2026-10-10T01:00:00Z', 'source_site' => 'beyourlover.com'],
            ['external_subscription_id' => '', 'email' => 'one@example.test', 'status' => 'subscribed', 'status_updated_at' => '2026-10-10T01:00:00Z', 'source_site' => 'beyourlover.com'],
            ['external_subscription_id' => self::NativeId, 'email' => 'one@example.test', 'status' => 'unknown', 'status_updated_at' => '2026-10-10T01:00:00Z', 'source_site' => 'beyourlover.com'],
            ['external_subscription_id' => self::NativeId, 'email' => 'one@example.test', 'status' => 'subscribed', 'source_site' => 'beyourlover.com'],
            ['external_subscription_id' => self::NativeId, 'email' => 'one@example.test', 'status' => 'subscribed', 'status_updated_at' => '2026-10-10T01:00:00Z', 'source_site' => 'other.example'],
            ['external_subscription_id' => self::NativeId, 'email' => 'one@example.test', 'status' => 'subscribed', 'status_updated_at' => 'nonsense', 'source_site' => 'beyourlover.com'],
        ];
        $this->bindSources([], $invalid);
        $this->actingAs($this->operator())->post('/sync/wpapi/subscribers')->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseCount('marketing_subscribers', 0);
        $this->assertDatabaseCount('marketing_subscriptions', 0);
        $this->assertDatabaseHas('sync_run_items', ['operation' => 'wordpress.subscriber_import', 'status' => 'failed']);
    }

    public function test_native_provider_error_is_audited_without_undoing_legacy_import(): void
    {
        $this->enableNative();
        $legacy = [['external_subscription_id' => self::LegacyId, 'email' => 'legacy@example.test', 'status' => 'unknown']];
        $this->app->bind(WordPressSubscriberGateway::class, fn () => new class($legacy) implements WordPressSubscriberGateway {
            public function __construct(private array $legacy) {}
            public function descriptor(): array { return ['configured' => true, 'url' => 'https://wp.test', 'connection_key' => 'beyourlover.com']; }
            public function snapshotSubscribers(?string $cursor = null, ?string $updatedAfter = null, int $limit = 100, ?string $source = null): array
            {
                if ($source !== null) throw new \RuntimeException('native signed snapshot not shipped');
                return ['items' => $this->legacy, 'next_cursor' => null, 'source_site' => 'beyourlover.com', 'request_id' => 'fake-legacy'];
            }
        });
        $this->actingAs($this->operator())->post('/sync/wpapi/subscribers')->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseCount('marketing_subscribers', 1);
        $this->assertDatabaseHas('sync_run_items', ['operation' => 'wordpress.subscriber_snapshot', 'status' => 'failed', 'provider_reference' => 'andy_core_newsletter']);
    }

    public function test_cursors_are_independent_between_sources_and_repeating_cursor_fails_closed(): void
    {
        $this->enableNative();
        $this->app->bind(WordPressSubscriberGateway::class, fn () => new class implements WordPressSubscriberGateway {
            public function descriptor(): array { return ['configured' => true, 'url' => 'https://wp.test', 'connection_key' => 'beyourlover.com']; }
            public function snapshotSubscribers(?string $cursor = null, ?string $updatedAfter = null, int $limit = 100, ?string $source = null): array
            {
                $token = $source === null ? 'legacy-token' : 'native-token';
                return ['items' => [], 'next_cursor' => $token, 'source_site' => 'beyourlover.com', 'request_id' => 'fake-page'];
            }
        });
        $this->actingAs($this->operator())->post('/sync/wpapi/subscribers')->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseCount('marketing_subscribers', 0);
        $this->assertDatabaseHas('sync_run_items', ['provider_reference' => 'elementor_legacy', 'status' => 'failed']);
        $this->assertDatabaseHas('sync_run_items', ['provider_reference' => 'andy_core_newsletter', 'status' => 'failed']);
    }

    public function test_combined_site_and_status_filters_match_the_same_subscription_fact(): void
    {
        $subscriber = \App\Modules\Marketing\Infrastructure\Models\MarketingSubscriber::query()
            ->create(['email' => 'mixed@example.test']);
        \App\Modules\Marketing\Infrastructure\Models\MarketingSubscription::query()->create([
            'subscriber_id' => $subscriber->id, 'channel' => 'email',
            'provider' => 'wordpress', 'external_subscription_id' => 'sub-site-a',
            'source_site' => 'site-a.example', 'status' => 'subscribed',
        ]);
        \App\Modules\Marketing\Infrastructure\Models\MarketingSubscription::query()->create([
            'subscriber_id' => $subscriber->id, 'channel' => 'email',
            'provider' => 'wordpress', 'external_subscription_id' => 'sub-site-b',
            'source_site' => 'site-b.example', 'status' => 'unsubscribed',
        ]);
        $this->actingAs($this->operator())
            ->get('/marketing/subscribers?source_site=site-b.example&status=subscribed')
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Marketing/Subscribers/Index')
                ->where('subscribers.total', 0)
                ->etc());
    }

    private function native(string $status, string $at): array
    {
        return ['external_subscription_id' => self::NativeId, 'email' => 'user@example.test',
            'provider' => 'wordpress', 'source_site' => 'beyourlover.com',
            'status' => $status, 'consent_source' => 'andy_core_newsletter_form',
            'status_updated_at' => $at];
    }

    private function enableNative(): void
    {
        config(['services.andy_core.newsletter_snapshot_enabled' => true]);
    }

    private function bindSources(array $legacy, array $native): void
    {
        $this->app->bind(WordPressSubscriberGateway::class, fn () => new class($legacy, $native) implements WordPressSubscriberGateway {
            public function __construct(private array $legacy, private array $native) {}
            public function descriptor(): array { return ['configured' => true, 'url' => 'https://wp.test', 'connection_key' => 'beyourlover.com']; }
            public function snapshotSubscribers(?string $cursor = null, ?string $updatedAfter = null, int $limit = 100, ?string $source = null): array
            {
                return ['items' => $source === null ? $this->legacy : $this->native, 'next_cursor' => null,
                    'source_site' => 'beyourlover.com', 'request_id' => 'fake'];
            }
        });
    }

    private function operator(): User
    {
        $role = Role::create(['key' => 'm2-sync-'.uniqid(), 'label' => 'M2 test operator']);
        foreach (['sync.view', 'sync.operate', 'marketing.view', 'marketing.operate'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
