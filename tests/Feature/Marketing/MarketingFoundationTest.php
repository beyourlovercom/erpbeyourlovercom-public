<?php

namespace Tests\Feature\Marketing;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Marketing\Infrastructure\Models\MarketingSubscriber;
use App\Modules\Marketing\Infrastructure\Models\MarketingSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class MarketingFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $permissions = ['marketing.view']): User
    {
        $role = Role::create(['key' => uniqid('marketing-'), 'label' => 'Marketing test']);
        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::create(['key' => $permission, 'label' => $permission]));
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    public function test_marketing_workspaces_are_permission_gated_and_empty_state_is_truthful(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get('/marketing')->assertInertia(fn (Assert $page) => $page
            ->component('Marketing/Overview')
            ->where('metrics.subscribers', 0)
            ->where('metrics.subscriptions', 0));
        $this->actingAs($user)->get('/marketing/subscribers')->assertInertia(fn (Assert $page) => $page
            ->component('Marketing/Subscribers/Index')
            ->where('subscribers.total', 0));
        foreach (['newsletters', 'campaigns', 'automations', 'promotions'] as $workspace) {
            $this->actingAs($user)->get('/marketing/'.$workspace)->assertInertia(fn (Assert $page) => $page
                ->component('Marketing/Planned')->where('workspace', $workspace));
        }
        $this->actingAs($this->user([]))->get('/marketing')->assertForbidden();
    }

    public function test_subscriber_email_is_normalized_and_subscription_facts_are_separate(): void
    {
        $subscriber = MarketingSubscriber::create(['email' => '  Alice@Example.TEST ']);
        $this->assertSame('alice@example.test', $subscriber->email);
        MarketingSubscription::create(['subscriber_id' => $subscriber->id, 'provider' => 'provider-a', 'status' => 'subscribed']);
        MarketingSubscription::create(['subscriber_id' => $subscriber->id, 'provider' => 'provider-b', 'status' => 'unsubscribed']);

        $this->actingAs($this->user())->get('/marketing/subscribers')->assertInertia(fn (Assert $page) => $page
            ->where('subscribers.total', 1)
            ->where('subscribers.data.0.email', 'alice@example.test')
            ->where('subscribers.data.0.providers', 'provider-a,provider-b'));
    }

    public function test_search_and_subscription_filters_are_server_side(): void
    {
        $one = MarketingSubscriber::create(['email' => 'one@example.test', 'first_name' => 'Alice']);
        MarketingSubscriber::create(['email' => 'two@example.test', 'first_name' => 'Bob']);
        MarketingSubscription::create(['subscriber_id' => $one->id, 'provider' => 'mail-a', 'source_site' => 'example.test', 'status' => 'pending']);

        $this->actingAs($this->user())->get('/marketing/subscribers?search=Alice&status=pending&provider=mail-a&source_site=example.test')
            ->assertInertia(fn (Assert $page) => $page->where('subscribers.total', 1)->where('subscribers.data.0.email', 'one@example.test'));
    }
}
