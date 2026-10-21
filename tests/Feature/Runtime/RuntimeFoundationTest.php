<?php

namespace Tests\Feature\Runtime;

use App\Models\Role;
use App\Models\User;
use App\Support\Runtime\RuntimeHealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuntimeFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_configuration_defaults_and_phpunit_isolation_are_consistent(): void
    {
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('session.driver'));

        $cacheSource = file_get_contents(config_path('cache.php'));
        $queueSource = file_get_contents(config_path('queue.php'));
        $databaseSource = file_get_contents(config_path('database.php'));
        $sessionSource = file_get_contents(config_path('session.php'));
        $this->assertStringContainsString("env('CACHE_STORE', 'redis')", $cacheSource);
        $this->assertStringContainsString("env('REDIS_CACHE_CONNECTION', 'cache')", $cacheSource);
        $this->assertStringContainsString("env('REDIS_CACHE_LOCK_CONNECTION', 'default')", $cacheSource);
        $this->assertStringContainsString("env('QUEUE_CONNECTION', 'redis')", $queueSource);
        $this->assertStringContainsString("env('REDIS_QUEUE_CONNECTION', 'default')", $queueSource);
        $this->assertStringContainsString("env('REDIS_QUEUE', 'default')", $queueSource);
        $this->assertStringContainsString("env('REDIS_QUEUE_RETRY_AFTER', 90)", $queueSource);
        $this->assertStringContainsString("env('REDIS_QUEUE_BLOCK_FOR', 5)", $queueSource);
        $this->assertStringContainsString("'after_commit' => true", $queueSource);
        $this->assertSame(2.0, config('database.redis.default.timeout'));
        $this->assertSame(6.0, config('database.redis.default.read_timeout'));
        $this->assertSame(2.0, config('database.redis.cache.timeout'));
        $this->assertSame(6.0, config('database.redis.cache.read_timeout'));
        $this->assertStringContainsString("env('REDIS_CONNECT_TIMEOUT', 2.0)", $databaseSource);
        $this->assertStringContainsString("env('REDIS_READ_TIMEOUT', 6.0)", $databaseSource);
        $this->assertStringContainsString("env('SESSION_DRIVER', 'database')", $sessionSource);
    }

    public function test_internal_health_endpoint_enforces_authorization_and_status_contract(): void
    {
        $healthy = $this->healthResult('ok');
        $this->mock(RuntimeHealthService::class)->shouldReceive('check')->andReturn($healthy);

        $this->get('/system/runtime/health')->assertRedirect('/login');
        $this->actingAs($this->userWith([]))->getJson('/system/runtime/health')->assertForbidden();
        $this->actingAs($this->userWith(['system.permissions.manage']))
            ->getJson('/system/runtime/health')
            ->assertOk()
            ->assertExactJson($healthy);
        $this->actingAs($this->userWith([], 'super_admin'))
            ->getJson('/system/runtime/health')
            ->assertOk()
            ->assertExactJson($healthy);
    }

    public function test_internal_health_endpoint_returns_503_with_only_redacted_fields_when_degraded(): void
    {
        $degraded = $this->healthResult('degraded');
        $this->mock(RuntimeHealthService::class)->shouldReceive('check')->andReturn($degraded);

        $response = $this->actingAs($this->userWith(['system.permissions.manage']))
            ->getJson('/system/runtime/health')
            ->assertStatus(503)
            ->assertExactJson($degraded);

        $payload = $response->json();
        $this->assertSame(['status', 'checks'], array_keys($payload));
        foreach ($payload['checks'] as $check) {
            $this->assertSame(['status', 'code'], array_keys($check));
        }
        foreach (['host', 'port', 'username', 'password', 'url', 'database', 'exception', 'trace', 'path'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower(json_encode($payload)));
        }
    }

    public function test_runtime_health_command_shares_service_result_exit_code_and_redacted_output(): void
    {
        $this->mock(RuntimeHealthService::class)->shouldReceive('check')->once()->andReturn($this->healthResult('ok'));
        $this->artisan('runtime:health')->expectsOutputToContain('Runtime health: OK')->assertSuccessful();

        $this->mock(RuntimeHealthService::class)->shouldReceive('check')->once()->andReturn($this->healthResult('degraded'));
        $this->artisan('runtime:health --json')
            ->doesntExpectOutputToContain('plain-secret')
            ->assertFailed();
    }

    public function test_runtime_health_schedule_is_bounded_and_has_no_business_events(): void
    {
        $events = app(Schedule::class)->events();
        $runtimeEvents = collect($events)->filter(fn ($event): bool => $event->description === 'runtime.health');

        $this->assertCount(1, $runtimeEvents);
        $event = $runtimeEvents->first();
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
        $this->assertStringContainsString('runtime:health', $event->command);
        $this->assertStringContainsString('--quiet', $event->command);
        $this->assertStringContainsString('--no-interaction', $event->command);
        $this->assertCount(1, $events);
    }

    public function test_public_up_endpoint_remains_available_and_registration_route_remains_absent(): void
    {
        $this->get('/up')->assertOk();
        $this->assertFalse(app('router')->has('register'));
    }

    private function userWith(array $permissions, string $roleKey = 'operator'): User
    {
        $role = Role::firstOrCreate(['key' => $roleKey], ['label' => $roleKey]);
        foreach ($permissions as $key) {
            $role->permissions()->create(['key' => $key, 'label' => $key]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function healthResult(string $status): array
    {
        return [
            'status' => $status,
            'checks' => [
                'cache_configuration' => [
                    'status' => $status,
                    'code' => $status === 'ok' ? 'configured' : 'expected_redis',
                ],
            ],
        ];
    }
}
