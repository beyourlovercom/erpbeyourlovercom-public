<?php

namespace Tests\Feature\Marketing;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M2.6 actual cross-repo integration test, conditional on GitHub's disposable
 * WP/MySQL Core HTTP server. NO Http::fake(): ERP gateway signs real HTTP requests.
 */
final class NewsletterM2JointWordPressE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_real_wordpress_core_hmac_http_syncs_both_sources_into_erp_and_repeats_without_duplicates(): void
    {
        $url = getenv('M2_WP_BASE_URL');
        $secret = getenv('M2_WP_SECRET');
        if (!is_string($url) || !str_starts_with($url, 'http://127.0.0.1:')
            || !is_string($secret) || strlen($secret) < 32) {
            $this->markTestSkipped('M2 joint UAT requires the disposable runner-only Core HTTP/MySQL fixture.');
        }
        config([
            'app.env' => 'testing',
            'services.andy_core.newsletter_snapshot_enabled' => true,
            'services.andy_core.url' => $url,
            'services.andy_core.connection_key' => 'beyourlover.com',
            'services.andy_core.key_id' => 'm2-joint-ci-only',
            'services.andy_core.shared_secret' => $secret,
            'services.andy_core.verify_tls' => true,
        ]);
        $operator = $this->operator();
        $nativeIds = [];
        foreach (['pending', 'confirmed', 'unsubscribed'] as $state) {
            $email = 'm2joint-'.$state.'@example.test';
            $nativeIds[$state] = 'andy_core_newsletter:'.hash('sha256', "127.0.0.1\n".$email);
        }

        // Real TCP HTTP process: Laravel HttpWordPressSubscriberGateway signs each request,
        // WordPress HMAC authenticates it, Core reads disposable MySQL, ERP saves SQLite.
        for ($n = 0; $n < 2; $n++) {
            $this->actingAs($operator)->post('/sync/wpapi/subscribers')
                ->assertRedirect()->assertSessionHas('success');
            $this->assertDatabaseCount('marketing_subscribers', 3);
            $this->assertDatabaseCount('marketing_subscriptions', 4);
            foreach (['pending' => 'pending', 'confirmed' => 'subscribed', 'unsubscribed' => 'unsubscribed'] as $state => $status) {
                $this->assertDatabaseHas('marketing_subscriptions', [
                    'external_subscription_id' => $nativeIds[$state],
                    'source_site' => '127.0.0.1',
                    'provider' => 'wordpress',
                    'status' => $status,
                ]);
            }
            $this->assertDatabaseHas('marketing_subscriptions', [
                'external_subscription_id' => 'elementor_signup:'.hash('sha256', "beyourlover.com\nm2joint-confirmed@example.test"),
                'source_site' => 'beyourlover.com', 'status' => 'subscribed',
            ]);
        }
        $this->assertDatabaseHas('sync_runs', [
            'run_type' => 'wordpress_subscribers_snapshot',
            'status' => 'completed',
        ]);
    }

    private function operator(): User
    {
        $role = Role::create(['key' => 'm2-live-'.uniqid(), 'label' => 'M2 disposable UAT operator']);
        foreach (['sync.view', 'sync.operate', 'marketing.view', 'marketing.operate'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
