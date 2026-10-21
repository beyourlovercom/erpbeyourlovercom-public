<?php

namespace Tests\Feature\Auth;

use App\Modules\Identity\Infrastructure\Models\User;
use Database\Seeders\LocalUatSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LocalUatCredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_uat_zhang_has_procurement_operator_permissions(): void
    {
        $this->app->instance('env', 'testing');
        $this->seed(LocalUatSeeder::class);

        $zhang = User::where('email', 'zhang@example.test')->firstOrFail();
        $this->assertSame('Zhang', $zhang->name);
        $this->assertTrue($zhang->hasPermission('procurement.view'));
        $this->assertTrue($zhang->hasPermission('procurement.operate'));
        $this->assertFalse($zhang->hasPermission('procurement.create'));
    }

    public function test_default_local_uat_admin_can_login_and_open_inventory_sync(): void
    {
        $this->app->instance('env', 'testing');
        $this->seed(LocalUatSeeder::class);

        $user = User::where('email', 'admin@local.test')->firstOrFail();
        $this->assertTrue(Hash::check('password', $user->password));

        $this->post('/login', ['email' => 'admin', 'password' => 'password'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get('/inventory/sync')->assertOk();
    }
}
