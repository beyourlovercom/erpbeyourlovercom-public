<?php

namespace Tests\Feature\Auth;

use App\Models\AuditRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LogoutSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_invalidates_authentication_session_and_audits_safely(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $sessionId = session()->getId();
        session()->put('verification-marker', 'present');
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->assertNotSame($sessionId, session()->getId());
        $this->get('/dashboard')->assertRedirect('/login');
        $audit = AuditRecord::where('action', 'auth.logout')->latest('id')->firstOrFail();
        $this->assertSame($user->id, $audit->actor_id);
        $this->assertSame(['state' => 'authenticated'], $audit->before);
        $this->assertSame(['state' => 'guest'], $audit->after);
        $this->assertSame('logout_requested', $audit->reason);
        $this->assertStringNotContainsString($sessionId, json_encode($audit->toArray()));
        $this->assertStringNotContainsString('verification-marker', json_encode($audit->toArray()));
    }
}
