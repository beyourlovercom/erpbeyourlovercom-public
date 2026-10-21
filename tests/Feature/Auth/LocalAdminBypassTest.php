<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\ApplyLocalAdminBypass;
use App\Models\AuditRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AuthorizationKeys;
use App\Support\ErpShellNavigation;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LocalAdminBypassTest extends TestCase
{
    use RefreshDatabase;

    private string $originalEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvironment = (string) $this->app->environment();
        config([
            'erp_local.admin_bypass.enabled' => false,
            'erp_local.admin_bypass.email' => 'test@example.com',
        ]);
        URL::forceRootUrl(null);
    }

    protected function tearDown(): void
    {
        Auth::guard('web')->forgetUser();
        if ($this->app->bound('request') && $this->app->make('request') instanceof Request) {
            $this->app->make('request')->attributes->remove(ApplyLocalAdminBypass::REQUEST_MARKER);
        }
        $this->app->instance('env', $this->originalEnvironment);
        config([
            'erp_local.admin_bypass.enabled' => false,
            'erp_local.admin_bypass.email' => 'test@example.com',
        ]);

        parent::tearDown();
    }

    public function test_bypass_is_disabled_by_default_and_guest_contract_is_unchanged(): void
    {
        $this->app->instance('env', 'local');

        $this->localRequest()->get('/dashboard')->assertRedirect('/login');
        $login = $this->localRequest()->get('/login')->assertOk();
        $this->assertStringContainsString('"component":"Auth\\/Login"', $login->getContent());
    }

    public function test_local_enabled_root_redirects_to_dashboard_for_existing_active_user(): void
    {
        $this->enableBypass();

        foreach (['erp.b', 'localhost', '127.0.0.1', '::1'] as $host) {
            $this->localRequest(host: $host)->get('/')->assertRedirectContains('/dashboard');
        }
    }

    public function test_local_enabled_login_get_and_post_never_render_or_execute_login(): void
    {
        $this->enableBypass();
        Event::fake([Login::class, Failed::class]);

        $this->localRequest()->get('/login')->assertRedirect('/dashboard');
        $this->localRequest()->post('/login', [
            'email' => 'nobody@example.com',
            'password' => 'not-used',
        ])->assertRedirect('/dashboard');

        Event::assertNotDispatched(Login::class);
        Event::assertNotDispatched(Failed::class);
        $this->assertDatabaseCount('audit_records', 0);
    }

    public function test_local_enabled_user_passes_auth_and_is_shared_safely_with_inertia(): void
    {
        $user = $this->enableBypass();

        $response = $this->localRequest()->get('/dashboard')->assertOk();
        $this->assertMatchesRegularExpression(
            '/<script data-page="app" type="application\/json">(.*?)<\/script>/s',
            $response->getContent(),
        );
        preg_match(
            '/<script data-page="app" type="application\/json">(.*?)<\/script>/s',
            $response->getContent(),
            $matches,
        );
        $page = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Dashboard/Index', $page['component']);
        $this->assertSame($user->id, $page['props']['auth']['user']['id']);
        $this->assertSame($user->name, $page['props']['auth']['user']['name']);
        $this->assertSame($user->email, $page['props']['auth']['user']['email']);
        $this->assertArrayNotHasKey('password', $page['props']['auth']['user']);
        $this->assertArrayNotHasKey('remember_token', $page['props']['auth']['user']);
    }

    public function test_local_enabled_user_passes_every_defined_gate_and_receives_full_navigation(): void
    {
        $this->enableBypass();
        Route::middleware('web')->get('/_test/local-admin/gates', function (Request $request) {
            $user = $request->user();
            $permissions = collect(AuthorizationKeys::cases())
                ->map->value
                ->filter(fn (string $key) => str_contains($key, '.'));

            return response()->json([
                'all_allowed' => $permissions->every(fn (string $key) => Gate::forUser($user)->allows($key)),
                'navigation_count' => count(app(ErpShellNavigation::class)->for($user)),
            ]);
        });

        $this->localRequest()->get('/_test/local-admin/gates')
            ->assertOk()
            ->assertJson(['all_allowed' => true, 'navigation_count' => count(config('erp_shell_navigation'))]);
    }

    public function test_local_enabled_entry_does_not_write_session_login_event_or_audit(): void
    {
        $this->enableBypass();
        Event::fake([Login::class]);
        $guardName = Auth::guard('web')->getName();

        $response = $this->localRequest()->get('/dashboard')->assertOk();

        Event::assertNotDispatched(Login::class);
        $response->assertSessionMissing($guardName);
        $this->assertDatabaseCount('audit_records', 0);
    }

    public function test_local_enabled_logout_is_intercepted_without_logout_event_or_audit(): void
    {
        $this->enableBypass();
        Event::fake([Logout::class]);

        $this->localRequest(sameOrigin: false)->post('/logout')->assertStatus(419);
        $this->localRequest()->post('/logout')->assertRedirect('/dashboard');
        Event::assertNotDispatched(Logout::class);
        $this->assertDatabaseMissing('audit_records', ['action' => 'auth.logout']);
        $this->localRequest()->get('/dashboard')->assertOk();
    }

    public function test_missing_configured_user_returns_503_without_mutation(): void
    {
        $this->enableBypass(createUser: false);
        $counts = $this->databaseCounts();

        $this->localRequest()->get('/dashboard')
            ->assertServiceUnavailable()
            ->assertSeeText('Local administrator bypass is unavailable.');

        $this->assertSame($counts, $this->databaseCounts());
    }

    public function test_inactive_configured_user_returns_503_without_state_disclosure_or_mutation(): void
    {
        $user = $this->enableBypass(active: false);
        $counts = $this->databaseCounts();

        $this->localRequest()->get('/dashboard')
            ->assertServiceUnavailable()
            ->assertSeeText('Local administrator bypass is unavailable.')
            ->assertDontSee($user->email);

        $this->assertSame($counts, $this->databaseCounts());
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_invalid_configured_email_returns_503_without_query_side_effects(): void
    {
        $this->enableBypass(email: 'not-an-email', createUser: false);

        [$response, $userQueries] = $this->captureUserQueries(
            fn () => $this->localRequest()->get('/dashboard'),
        );

        $response->assertServiceUnavailable();
        $this->assertSame([], $userQueries);
        $this->assertDatabaseCount('audit_records', 0);
    }

    public function test_enabled_bypass_fails_closed_outside_local(): void
    {
        config(['erp_local.admin_bypass.enabled' => true]);

        foreach (['development', 'testing', 'staging', 'production'] as $environment) {
            $this->app->instance('env', $environment);
            [$response, $userQueries] = $this->captureUserQueries(
                fn () => $this->localRequest()->get('/dashboard'),
            );
            $response->assertServiceUnavailable();
            $this->assertSame([], $userQueries);
        }
    }

    public function test_disabling_bypass_preserves_real_fortify_login_logout_and_audit(): void
    {
        $this->app->instance('env', 'local');
        config(['erp_local.admin_bypass.enabled' => false]);
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'is_active' => true,
            'password' => Hash::make('normal-password'),
        ]);
        $loginEvents = 0;
        $logoutEvents = 0;
        Event::listen(Login::class, function () use (&$loginEvents): void {
            $loginEvents++;
        });
        Event::listen(Logout::class, function () use (&$logoutEvents): void {
            $logoutEvents++;
        });

        $this->localRequest()->post('/login', [
            'email' => $user->email,
            'password' => 'normal-password',
        ])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->localRequest()->post('/logout')->assertRedirect('/');
        $this->assertGuest();

        $this->assertSame(1, $loginEvents);
        $this->assertSame(1, $logoutEvents);
        $this->assertDatabaseHas('audit_records', ['action' => 'auth.login', 'actor_id' => $user->id]);
        $this->assertDatabaseHas('audit_records', ['action' => 'auth.logout', 'actor_id' => $user->id]);
    }

    public function test_request_marker_is_removed_and_guard_user_is_forgotten_after_response(): void
    {
        $this->enableBypass();

        $this->localRequest()->get('/dashboard')->assertOk();

        $this->assertNull(Auth::guard('web')->getUser());
        $this->assertFalse($this->app->make('request')->attributes->has(ApplyLocalAdminBypass::REQUEST_MARKER));
    }

    public function test_cached_configuration_uses_frozen_values_and_requires_rebuild(): void
    {
        $cachePath = sys_get_temp_dir().'/byl-erp-config-cache-'.bin2hex(random_bytes(8)).'.php';

        try {
            $process = new Process(
                [PHP_BINARY, 'artisan', 'config:cache', '--no-ansi'],
                base_path(),
                [
                    'APP_CONFIG_CACHE' => $cachePath,
                    'APP_ENV' => 'local',
                    'ERP_LOCAL_ADMIN_BYPASS' => 'true',
                    'ERP_LOCAL_ADMIN_EMAIL' => 'test@example.com',
                ],
            );
            $process->mustRun();

            $cached = require $cachePath;
            $this->assertSame('local', $cached['app']['env']);
            $this->assertTrue($cached['erp_local']['admin_bypass']['enabled']);
            $this->app->instance('env', $cached['app']['env']);
            config(['erp_local' => $cached['erp_local']]);

            [$response, $userQueries] = $this->captureUserQueries(
                fn () => $this->localRequest(host: 'production.example')->get('/dashboard'),
            );
            $response->assertServiceUnavailable();
            $this->assertSame([], $userQueries);
            $this->assertFileDoesNotExist(base_path('bootstrap/cache/config.php'));
        } finally {
            if (is_file($cachePath)) {
                unlink($cachePath);
            }
        }

        $this->assertFileDoesNotExist($cachePath);
    }

    public function test_enabled_bypass_fails_closed_for_unapproved_host_before_user_query(): void
    {
        $this->enableBypass();

        [$response, $userQueries] = $this->captureUserQueries(
            fn () => $this->localRequest(host: 'evil.example')->get('/dashboard'),
        );

        $response->assertServiceUnavailable();
        $this->assertSame([], $userQueries);
        $this->assertNull(Auth::guard('web')->getUser());
        $this->assertDatabaseCount('audit_records', 0);
    }

    public function test_enabled_bypass_fails_closed_for_non_loopback_client_before_user_query(): void
    {
        $this->enableBypass();

        [$response, $userQueries] = $this->captureUserQueries(
            fn () => $this->localRequest(remoteAddress: '203.0.113.10')
                ->withHeaders([
                    'X-Forwarded-For' => '127.0.0.1',
                    'Forwarded' => 'for=127.0.0.1',
                    'X-Real-IP' => '127.0.0.1',
                ])->get('/dashboard'),
        );

        $response->assertServiceUnavailable();
        $this->assertSame([], $userQueries);
        $this->assertNull(Auth::guard('web')->getUser());
        $this->assertDatabaseCount('audit_records', 0);
    }

    public function test_locally_cached_enabled_configuration_fails_closed_on_production_host(): void
    {
        $this->enableBypass();

        [$response, $userQueries] = $this->captureUserQueries(
            fn () => $this->localRequest(host: 'erp.production.example')->get('/dashboard'),
        );

        $response->assertServiceUnavailable();
        $this->assertSame([], $userQueries);
        $this->assertFalse($this->app->make('request')->attributes->has(ApplyLocalAdminBypass::REQUEST_MARKER));
    }

    public function test_gate_without_http_request_returns_null_without_exception_or_local_authorization(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $request = $this->app->make('request');
        $this->app->forgetInstance('request');

        try {
            $this->assertFalse($this->app->bound('request'));
            $this->assertFalse(Gate::forUser($user)->allows(AuthorizationKeys::DashboardView->value));
        } finally {
            $this->app->instance('request', $request);
        }
    }

    public function test_existing_web_auth_session_fails_closed_without_override_logout_or_audit(): void
    {
        $this->enableBypass();
        Event::fake([Logout::class]);
        $guard = Auth::guard('web');
        $sessionKey = $guard->getName();
        $sessionValue = 'existing-authentication-id';

        [$sessionResponse, $sessionUserQueries] = $this->captureUserQueries(
            fn () => $this->localRequest()->withSession([$sessionKey => $sessionValue])->get('/dashboard'),
        );
        $sessionResponse->assertServiceUnavailable()->assertSessionHas($sessionKey, $sessionValue);
        $this->assertSame([], $sessionUserQueries);

        $this->flushSession();
        [$cookieResponse, $cookieUserQueries] = $this->captureUserQueries(
            fn () => $this->localRequest()->withCookie($guard->getRecallerName(), 'existing-recaller')->get('/dashboard'),
        );
        $cookieResponse->assertServiceUnavailable();
        $this->assertSame([], $cookieUserQueries);
        $this->assertNull($guard->getUser());
        Event::assertNotDispatched(Logout::class);
        $this->assertDatabaseCount('audit_records', 0);
    }

    public function test_guard_user_and_request_marker_are_cleared_when_downstream_throws(): void
    {
        $this->enableBypass();
        Route::middleware('web')->get('/_test/local-admin/throws', fn () => throw new RuntimeException('downstream failure'));
        $this->withoutExceptionHandling();

        try {
            $this->localRequest()->get('/_test/local-admin/throws');
            $this->fail('The downstream exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('downstream failure', $exception->getMessage());
        }

        $this->assertNull(Auth::guard('web')->getUser());
        $this->assertFalse($this->app->make('request')->attributes->has(ApplyLocalAdminBypass::REQUEST_MARKER));
    }

    private function enableBypass(
        string $email = 'test@example.com',
        bool $active = true,
        bool $createUser = true,
    ): ?User {
        $this->app->instance('env', 'local');
        config([
            'erp_local.admin_bypass.enabled' => true,
            'erp_local.admin_bypass.email' => $email,
        ]);

        return $createUser
            ? User::factory()->create(['email' => $email, 'is_active' => $active])
            : null;
    }

    private function localRequest(
        string $host = 'erp.b',
        string $remoteAddress = '127.0.0.1',
        bool $sameOrigin = true,
    ): static {
        $urlHost = str_contains($host, ':') && ! str_starts_with($host, '[') ? '['.$host.']' : $host;
        URL::forceRootUrl('http://'.$urlHost);

        $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress]);

        return $sameOrigin ? $this->withHeader('Sec-Fetch-Site', 'same-origin') : $this;
    }

    /**
     * @return array{0: mixed, 1: list<array<string, mixed>>}
     */
    private function captureUserQueries(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $response = $request();
            $queries = array_values(array_filter(
                DB::getQueryLog(),
                fn (array $query) => preg_match('/(?:from|into|update) ["`]?users["`]?/i', $query['query']) === 1,
            ));
        } finally {
            DB::disableQueryLog();
        }

        return [$response, $queries];
    }

    /** @return array<string, int> */
    private function databaseCounts(): array
    {
        return [
            'users' => User::count(),
            'roles' => Role::count(),
            'permissions' => Permission::count(),
            'role_user' => DB::table('role_user')->count(),
            'audit_records' => AuditRecord::count(),
        ];
    }
}
