<?php

namespace Tests\Feature\Marketing;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Marketing\Application\Contracts\WordPressSubscriberGateway;
use App\Modules\Marketing\Infrastructure\Models\MarketingSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WordPressSubscriberSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_subscriber_snapshot_is_idempotent_and_updates_provider_status(): void
    {
        $this->bindSnapshot([['external_subscription_id' => 'sub-1', 'email' => ' Alice@Example.test ', 'first_name' => 'Alice', 'status' => 'subscribed', 'consent_source' => 'form', 'subscribed_at' => '2026-08-29T00:00:00Z', 'status_updated_at' => '2026-08-29T00:00:00Z', 'external_list_id' => 'list-1', 'external_list_name' => 'Main']]);
        $user = $this->operator();
        $this->actingAs($user)->post('/sync/wpapi/subscribers')->assertRedirect();
        $this->actingAs($user)->post('/sync/wpapi/subscribers')->assertRedirect();
        $this->assertDatabaseCount('marketing_subscriptions', 1);
        $this->assertDatabaseHas('marketing_subscriptions', ['provider' => 'wordpress', 'external_subscription_id' => 'sub-1', 'source_site' => 'beyourlover.com']);

        $this->bindSnapshot([['external_subscription_id' => 'sub-1', 'email' => 'alice@example.test', 'first_name' => 'Alicia', 'status' => 'unsubscribed', 'unsubscribed_at' => '2026-08-29T01:00:00Z']]);
        $this->actingAs($user)->post('/sync/wpapi/subscribers')->assertRedirect();
        $this->assertDatabaseHas('marketing_subscriptions', ['external_subscription_id' => 'sub-1', 'status' => 'unsubscribed']);
        $this->assertDatabaseHas('marketing_subscribers', ['email' => 'alice@example.test', 'first_name' => 'Alicia']);
    }

    public function test_invalid_items_are_failed_and_no_customer_or_order_is_created(): void
    {
        $this->bindSnapshot([['external_subscription_id' => '', 'email' => 'not-an-email', 'status' => 'subscribed']]);
        $this->actingAs($this->operator())->post('/sync/wpapi/subscribers')->assertRedirect();
        $this->assertDatabaseCount('marketing_subscribers', 0);
        $this->assertDatabaseCount('marketing_subscriptions', 0);
        $this->assertDatabaseHas('sync_run_items', ['operation' => 'wordpress.subscriber_import', 'status' => 'failed']);
    }

    public function test_wpapi_view_and_post_require_their_independent_permission_sets(): void
    {
        $this->bindSnapshot([]);
        $this->actingAs($this->withPermissions(['marketing.view']))->get('/sync/wpapi')->assertForbidden();
        $this->actingAs($this->withPermissions(['sync.view']))->get('/sync/wpapi')->assertOk();
        $this->actingAs($this->withPermissions(['sync.view', 'marketing.view']))->post('/sync/wpapi/subscribers')->assertForbidden();
        $this->actingAs($this->withPermissions(['sync.operate', 'marketing.operate']))->post('/sync/wpapi/subscribers')->assertRedirect();
    }

    public function test_provider_failure_is_recorded_as_failed_and_returned_as_error_flash(): void
    {
        $this->app->bind(WordPressSubscriberGateway::class, fn () => new class implements WordPressSubscriberGateway {
            public function descriptor(): array { return ['configured' => true, 'url' => 'https://wp.test', 'connection_key' => 'beyourlover.com']; }
            public function snapshotSubscribers(?string $cursor = null, ?string $updatedAfter = null, int $limit = 100, ?string $source = null): array { throw new \RuntimeException('provider unavailable'); }
        });

        $this->actingAs($this->operator())->post('/sync/wpapi/subscribers')
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertDatabaseHas('sync_runs', ['run_type' => 'wordpress_subscribers_snapshot', 'status' => 'failed', 'failed_items' => 1]);
    }

    private function bindSnapshot(array $items): void
    {
        $this->app->bind(WordPressSubscriberGateway::class, fn () => new class($items) implements WordPressSubscriberGateway {
            public function __construct(private array $items) {}
            public function descriptor(): array { return ['configured' => true, 'url' => 'https://wp.test', 'connection_key' => 'beyourlover.com']; }
            public function snapshotSubscribers(?string $cursor = null, ?string $updatedAfter = null, int $limit = 100, ?string $source = null): array { return ['items' => $this->items, 'next_cursor' => null, 'source_site' => 'beyourlover.com', 'request_id' => 'test-request']; }
        });
    }

    private function operator(): User { return $this->withPermissions(['sync.view', 'sync.operate', 'marketing.view', 'marketing.operate']); }

    private function withPermissions(array $permissions): User
    {
        $role = Role::create(['key' => 'wp-sync-'.uniqid(), 'label' => 'WordPress Sync']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create(['is_active' => true]); $user->roles()->attach($role); return $user;
    }
}
