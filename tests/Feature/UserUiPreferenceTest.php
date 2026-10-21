<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class UserUiPreferenceTest extends TestCase
{
    use RefreshDatabase;
    public function test_preference_is_scoped_to_authenticated_account_and_overwritten_in_place(): void
    {
        $a = User::factory()->create(); $b = User::factory()->create(); $url = '/ui/preferences/grid.customers.groups.v1';
        $this->actingAs($a)->putJson($url, ['value' => ['columns' => [['key' => 'email', 'visible' => false]]]])->assertOk();
        $this->actingAs($a)->putJson($url, ['value' => ['columns' => [['key' => 'phone', 'visible' => true]]]])->assertOk();
        $this->assertDatabaseCount('user_ui_preferences', 1);
        $this->actingAs($a)->getJson($url)->assertOk()->assertJsonPath('value.columns.0.key', 'phone');
        $this->actingAs($b)->getJson($url)->assertOk()->assertJsonPath('value', null);
    }
    public function test_preference_routes_require_authentication(): void { $this->get('/ui/preferences/grid.customers.groups.v1')->assertRedirect('/login'); }
    public function test_oversized_preference_payload_is_rejected(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->put('/ui/preferences/grid.customers.groups.v1', ['value' => ['blob' => str_repeat('x', 66000)]]);
        self::assertSame(302, $response->getStatusCode());
        $this->assertDatabaseCount('user_ui_preferences', 0);
    }
}
