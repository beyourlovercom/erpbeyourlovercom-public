<?php

namespace Tests\Feature\Auth;

use App\Models\AuditRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_inertia_failed_login_redirects_with_generic_validation_error_not_500(): void
    {
        $user = User::factory()->create(['is_active' => true, 'password' => Hash::make('correct-password')]);

        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'Accept' => 'text/html, application/xhtml+xml',
        ])->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
        $this->assertSame(1, AuditRecord::query()->where('action', 'auth.login_failed')->count());
    }

    public function test_inertia_inactive_user_login_does_not_become_a_server_error(): void
    {
        $user = User::factory()->create(['is_active' => false, 'password' => Hash::make('inactive-secret')]);

        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'Accept' => 'text/html, application/xhtml+xml',
        ])->post('/login', ['email' => $user->email, 'password' => 'inactive-secret']);

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
    }

    public function test_failed_login_audit_excludes_credentials_and_request_secrets(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $this->withHeaders(['Authorization' => 'Bearer secret-token'])
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password', 'password_confirmation' => 'confirm']);
        $audit = AuditRecord::where('action', 'auth.login_failed')->latest('id')->firstOrFail();
        $this->assertSame('fortify', $audit->source);
        $this->assertSame('credentials_rejected', $audit->reason);
        $this->assertNotNull($audit->request_id);
        $text = json_encode($audit->toArray());
        foreach (['wrong-password', 'confirm', $this->app['session']->getId(), 'secret-token', 'password_confirmation'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertStringNotContainsString('email', json_encode($audit->after));
    }

    public function test_inactive_user_login_is_generic_and_audited_without_secrets(): void
    {
        $user = User::factory()->create(['is_active' => false, 'password' => Hash::make('inactive-secret')]);
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'inactive-secret', 'password_confirmation' => 'inactive-secret']);
        $response->assertSessionHasErrors('email');
        $this->assertStringNotContainsString('inactive', json_encode(session()->all()));
        $audit = AuditRecord::where('action', 'auth.login_failed')->latest('id')->firstOrFail();
        $this->assertNull($audit->actor_id);
        $this->assertSame('credential', $audit->entity_type);
        $this->assertSame('credentials_rejected', $audit->reason);
        foreach (['inactive-secret', 'password_confirmation', session()->getId()] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($audit->toArray()));
        }
    }
}
