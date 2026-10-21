<?php

namespace Tests\Feature\Audit;

use App\Models\AuditRecord;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditService;
use App\Support\Audit\AuditSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditSchemaContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_model_and_schema_contract_are_aligned(): void
    {
        $columns = Schema::getColumnListing('audit_records');
        $this->assertSame(['id', 'actor_id', 'action', 'entity_type', 'entity_id', 'before', 'after', 'source', 'request_id', 'ip_address', 'user_agent', 'reason', 'created_at'], $columns);
        $model = new AuditRecord;
        $this->assertSame(['actor_id', 'action', 'entity_type', 'entity_id', 'before', 'after', 'source', 'request_id', 'ip_address', 'user_agent', 'reason', 'created_at'], $model->getFillable());
        $casts = $model->getCasts();
        $this->assertSame('array', $casts['before']);
        $this->assertSame('array', $casts['after']);
        $this->assertSame('datetime', $casts['created_at']);
    }

    public function test_auth_and_provisioning_actions_persist_json_without_sensitive_fields(): void
    {
        $user = User::factory()->create();
        foreach (['auth.login', 'auth.login_failed', 'auth.logout', 'user.created'] as $action) {
            $record = app(AuditService::class)->record(
                AuditActor::user($user, AuditSource::InternalSystem),
                $action,
                User::class,
                before: ['state' => 'old'],
                after: ['state' => 'new'],
            );
            $this->assertIsArray($record->fresh()->before);
            $this->assertIsArray($record->fresh()->after);
        }
        $this->assertDatabaseMissing('audit_records', ['password' => 'anything']);
    }

    public function test_audit_foundation_requires_no_schema_migration(): void
    {
        $this->assertSame(
            ['id', 'actor_id', 'action', 'entity_type', 'entity_id', 'before', 'after', 'source', 'request_id', 'ip_address', 'user_agent', 'reason', 'created_at'],
            Schema::getColumnListing('audit_records'),
        );
        $this->assertInstanceOf(AuditService::class, app(AuditService::class));
        $this->assertSame([], glob(database_path('migrations/*audit_foundation*')));
    }
}
