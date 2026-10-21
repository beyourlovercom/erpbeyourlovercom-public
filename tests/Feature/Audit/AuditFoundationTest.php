<?php

namespace Tests\Feature\Audit;

use App\Models\AuditRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditService;
use App\Support\Audit\AuditSource;
use App\Support\Audit\AuditWriteException;
use App\Support\Audit\AuditWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class AuditFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_actor_persists_entity_request_source_reason_and_sanitized_metadata(): void
    {
        $user = User::factory()->create();
        $requestId = (string) Str::uuid();

        $this->withHeader('X-Request-ID', $requestId)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => 'Audit Contract'])
            ->actingAs($this->userWith(['audit.view']))
            ->getJson('/system/audit-records')
            ->assertOk();

        $record = app(AuditService::class)->record(
            AuditActor::user($user, AuditSource::Fortify),
            'user.profile_updated',
            User::class,
            $user->id,
            before: ['name' => 'Before', 'nested' => ['password' => 'before-secret']],
            after: ['name' => 'After', 'nested' => ['api_token' => 'after-secret', 'safe' => true]],
            reason: 'owner_requested',
        );

        $this->assertSame($user->id, $record->actor_id);
        $this->assertSame('user.profile_updated', $record->action);
        $this->assertSame(User::class, $record->entity_type);
        $this->assertSame((string) $user->id, $record->entity_id);
        $this->assertSame(AuditSource::Fortify->value, $record->source);
        $this->assertSame($requestId, $record->request_id);
        $this->assertSame('203.0.113.10', $record->ip_address);
        $this->assertSame('Audit Contract', $record->user_agent);
        $this->assertSame('owner_requested', $record->reason);
        $this->assertSame(['name' => 'Before', 'nested' => []], $record->before);
        $this->assertSame(['name' => 'After', 'nested' => ['safe' => true]], $record->after);
    }

    public function test_trusted_system_actor_is_explicit_and_has_no_user_actor(): void
    {
        $record = app(AuditService::class)->record(
            AuditActor::system(AuditSource::InternalSystem),
            'system.maintenance_checked',
            'runtime',
            reason: 'scheduled_check',
        );

        $this->assertNull($record->actor_id);
        $this->assertSame(AuditSource::InternalSystem->value, $record->source);
        $this->assertSame('scheduled_check', $record->reason);
    }

    public function test_sensitive_metadata_is_removed_recursively_without_removing_safe_values(): void
    {
        $record = app(AuditService::class)->record(
            AuditActor::system(AuditSource::Fortify),
            'security.metadata_filtered',
            'security',
            after: [
                'safe' => 'kept',
                'Password' => 'secret',
                'context' => [
                    'sessionId' => 'session-secret',
                    'Authorization-Header' => 'Bearer secret',
                    'requestPayload' => ['email' => 'private@example.test'],
                    'safe_nested' => ['value' => 'kept'],
                ],
            ],
        );

        $this->assertSame([
            'safe' => 'kept',
            'context' => ['safe_nested' => ['value' => 'kept']],
        ], $record->after);
        $serialized = json_encode($record->toArray());
        foreach (['secret', 'Bearer', 'private@example.test', 'Password', 'sessionId', 'requestPayload'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_camel_case_sensitive_keys_are_removed_recursively(): void
    {
        $this->assertSensitiveKeysAreRemoved([
            'apiKey',
            'privateKey',
            'clientSecret',
            'accessToken',
            'refreshToken',
            'authorizationHeader',
            'sessionId',
            'requestPayload',
        ]);
    }

    public function test_pascal_case_sensitive_keys_are_removed_recursively(): void
    {
        $this->assertSensitiveKeysAreRemoved(['APIKey', 'PrivateKey']);
    }

    public function test_snake_kebab_and_dotted_sensitive_keys_are_removed_recursively(): void
    {
        $this->assertSensitiveKeysAreRemoved(['api_key', 'api-key', 'api.key']);
    }

    public function test_audit_records_are_immutable_through_model_update_and_delete(): void
    {
        $record = app(AuditService::class)->record(
            AuditActor::system(AuditSource::Fortify),
            'security.immutable',
            'security',
        );

        try {
            $record->update(['reason' => 'changed']);
            $this->fail('Audit update should fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit records are immutable.', $exception->getMessage());
        }

        $this->assertNull($record->fresh()->reason);

        $this->expectException(LogicException::class);
        $record->delete();
    }

    public function test_direct_model_creation_is_rejected_in_favor_of_audit_service(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit records must be created through AuditService.');

        AuditRecord::create([
            'action' => 'security.bypassed',
            'entity_type' => 'security',
            'source' => 'untrusted',
        ]);
    }

    public function test_direct_eloquent_insert_is_rejected_in_favor_of_audit_service(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit records must be created through AuditService.');

        AuditRecord::query()->insert([
            'action' => 'security.bypassed',
            'entity_type' => 'security',
            'source' => 'untrusted',
        ]);
    }

    public function test_save_quietly_cannot_create_an_audit_record(): void
    {
        $record = new AuditRecord([
            'action' => 'security.quiet_create',
            'entity_type' => 'security',
            'source' => 'untrusted',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit records must be created through AuditService.');

        $record->saveQuietly();
    }

    public function test_update_quietly_cannot_modify_an_audit_record(): void
    {
        $record = $this->auditRecord('security.quiet_update');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit records are immutable.');

        $record->updateQuietly(['reason' => 'changed']);
    }

    public function test_delete_quietly_cannot_delete_an_audit_record(): void
    {
        $record = $this->auditRecord('security.quiet_delete');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit records are immutable.');

        $record->deleteQuietly();
    }

    public function test_force_create_quietly_cannot_create_an_audit_record(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit records must be created through AuditService.');

        AuditRecord::query()->forceCreateQuietly([
            'action' => 'security.force_quiet_create',
            'entity_type' => 'security',
            'source' => 'untrusted',
        ]);
    }

    public function test_without_events_cannot_create_an_audit_record(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit records must be created through AuditService.');

        AuditRecord::withoutEvents(fn () => AuditRecord::create([
            'action' => 'security.eventless_create',
            'entity_type' => 'security',
            'source' => 'untrusted',
        ]));
    }

    public function test_without_events_cannot_save_an_audit_record(): void
    {
        $record = $this->auditRecord('security.eventless_save');
        $record->reason = 'changed';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit records are immutable.');

        AuditRecord::withoutEvents(fn () => $record->save());
    }

    public function test_without_events_cannot_delete_an_audit_record(): void
    {
        $record = $this->auditRecord('security.eventless_delete');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit records are immutable.');

        AuditRecord::withoutEvents(fn () => $record->delete());
    }

    public function test_audit_records_are_immutable_through_bulk_update_and_delete(): void
    {
        $record = app(AuditService::class)->record(
            AuditActor::system(AuditSource::Fortify),
            'security.bulk_immutable',
            'security',
        );

        try {
            AuditRecord::query()->whereKey($record->id)->update(['reason' => 'changed']);
            $this->fail('Bulk audit update should fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit records are immutable.', $exception->getMessage());
        }

        $this->assertNull($record->fresh()->reason);

        try {
            AuditRecord::query()->whereKey($record->id)->delete();
            $this->fail('Bulk audit delete should fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit records are immutable.', $exception->getMessage());
        }

        $this->assertDatabaseHas('audit_records', ['id' => $record->id]);
    }

    public function test_audit_view_requires_named_permission_and_returns_read_only_records(): void
    {
        $record = app(AuditService::class)->record(
            AuditActor::system(AuditSource::Fortify),
            'security.viewed',
            'security',
        );

        $this->get('/system/audit-records')->assertRedirect('/login');
        $this->actingAs($this->userWith([]))->getJson('/system/audit-records')->assertForbidden();
        $this->actingAs($this->userWith(['audit.view']))
            ->getJson('/system/audit-records')
            ->assertOk()
            ->assertJsonPath('data.0.id', $record->id)
            ->assertJsonPath('data.0.action', 'security.viewed');
        $this->actingAs($this->userWith([], 'super_admin'))
            ->getJson('/system/audit-records')
            ->assertOk();

        $this->assertFalse(app('router')->has('system.audit-records.update'));
        $this->assertFalse(app('router')->has('system.audit-records.destroy'));
    }

    public function test_required_audit_write_failure_throws_safe_exception(): void
    {
        Log::spy();
        $writer = new class implements AuditWriter
        {
            public function write(array $attributes): AuditRecord
            {
                throw new RuntimeException('database-password=plain-secret');
            }
        };

        try {
            (new AuditService($writer))->record(
                AuditActor::system(AuditSource::Fortify),
                'security.write_failed',
                'security',
            );
            $this->fail('Required audit write should fail.');
        } catch (AuditWriteException $exception) {
            $this->assertSame('A required audit record could not be written.', $exception->getMessage());
            $this->assertStringNotContainsString('plain-secret', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->with('audit.write_failed', ['exception_type' => RuntimeException::class]);
    }

    public function test_login_audit_failure_revokes_authentication_and_invalidates_session(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $oldSessionId = session()->getId();
        $this->app->bind(AuditWriter::class, fn () => new class implements AuditWriter
        {
            public function write(array $attributes): AuditRecord
            {
                throw new RuntimeException('audit unavailable');
            }
        });
        $this->app->forgetInstance(AuditService::class);
        $this->withoutExceptionHandling();

        try {
            $this->post('/login', ['email' => $user->email, 'password' => 'password']);
            $this->fail('Login should fail when its required audit cannot be written.');
        } catch (AuditWriteException) {
            $this->assertGuest();
            $this->assertNotSame($oldSessionId, session()->getId());
            $this->withExceptionHandling();
            $this->get('/dashboard')->assertRedirect('/login');
        }
    }

    public function test_provisioning_audit_failure_rolls_back_user_and_role_assignment(): void
    {
        Role::create(['key' => 'operator', 'label' => 'Operator']);
        $this->app->bind(AuditWriter::class, fn () => new class implements AuditWriter
        {
            public function write(array $attributes): AuditRecord
            {
                throw new RuntimeException('audit unavailable');
            }
        });

        $this->app->forgetInstance(AuditService::class);

        try {
            $this->artisan('erp:user:create')
                ->expectsQuestion('Name', 'Owner')
                ->expectsQuestion('Email', 'owner@example.test')
                ->expectsQuestion('Password', 'a-strong-password-123')
                ->expectsQuestion('Confirm password', 'a-strong-password-123')
                ->expectsChoice('Role', 'operator', ['super_admin', 'admin', 'manager', 'operator'])
                ->run();
            $this->fail('Provisioning should fail when its required audit cannot be written.');
        } catch (AuditWriteException $exception) {
            $this->assertSame('A required audit record could not be written.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.test']);
        $this->assertDatabaseCount('role_user', 0);
    }

    public function test_logout_audit_failure_still_invalidates_authentication_session(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $authenticatedSessionId = session()->getId();
        $this->app->bind(AuditWriter::class, fn () => new class implements AuditWriter
        {
            public function write(array $attributes): AuditRecord
            {
                throw new RuntimeException('audit unavailable');
            }
        });
        $this->app->forgetInstance(AuditService::class);
        $this->withoutExceptionHandling();

        try {
            $this->post('/logout');
            $this->fail('Logout should report when its required audit cannot be written.');
        } catch (AuditWriteException) {
            $this->assertGuest();
            $this->assertNotSame($authenticatedSessionId, session()->getId());
            $this->withExceptionHandling();
            $this->get('/dashboard')->assertRedirect('/login');
        }
    }

    private function userWith(array $permissions, string $roleKey = 'operator'): User
    {
        $key = $roleKey === 'super_admin' ? $roleKey : $roleKey.'-'.Str::random(8);
        $role = Role::create(['key' => $key, 'label' => $roleKey]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    /** @param list<string> $keys */
    private function assertSensitiveKeysAreRemoved(array $keys): void
    {
        $metadata = ['safe' => 'kept', 'nested' => ['safeNested' => 'also-kept']];
        $forbiddenValues = [];

        foreach ($keys as $index => $key) {
            $value = 'forbidden-value-'.$index;
            $metadata['nested'][$key] = $value;
            $forbiddenValues[] = $value;
        }

        $record = app(AuditService::class)->record(
            AuditActor::system(AuditSource::InternalSystem),
            'security.key_normalization_checked',
            'security',
            after: $metadata,
        );

        $this->assertSame(['safe' => 'kept', 'nested' => ['safeNested' => 'also-kept']], $record->after);
        $serialized = json_encode($record->fresh()->toArray(), JSON_THROW_ON_ERROR);

        foreach ([...$keys, ...$forbiddenValues] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    private function auditRecord(string $action): AuditRecord
    {
        return app(AuditService::class)->record(
            AuditActor::system(AuditSource::InternalSystem),
            $action,
            'security',
        );
    }
}
