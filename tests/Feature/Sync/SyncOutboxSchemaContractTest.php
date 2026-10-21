<?php

namespace Tests\Feature\Sync;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncOutboxSchemaContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_authoring_matches_length_and_unsigned_contracts(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_01_000000_create_sync_outbox_table.php'));

        $this->assertIsString($migration);

        foreach ([
            "string('idempotency_key', 191)",
            "string('operation', 100)",
            "string('aggregate_type', 64)",
            "string('aggregate_id', 127)",
            "string('provider_key', 64)",
            "string('connection_key', 127)",
            "string('status', 20)",
            "char('lock_token', 36)",
            "string('last_error_code', 100)",
            "string('last_error_message', 500)",
            "unsignedSmallInteger('attempts')",
            "unsignedSmallInteger('max_attempts')",
        ] as $definition) {
            $this->assertStringContainsString($definition, $migration);
        }
    }

    public function test_sync_outbox_schema_matches_the_frozen_contract(): void
    {
        $this->assertTrue(Schema::hasTable('sync_outbox'));
        $this->assertSame([
            'id',
            'idempotency_key',
            'operation',
            'aggregate_type',
            'aggregate_id',
            'provider_key',
            'connection_key',
            'payload',
            'status',
            'attempts',
            'max_attempts',
            'available_at',
            'locked_at',
            'lock_token',
            'last_attempted_at',
            'completed_at',
            'failed_at',
            'last_error_code',
            'last_error_message',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('sync_outbox'));

        $columns = collect(Schema::getColumns('sync_outbox'))->keyBy('name');

        $this->assertSame('pending', trim((string) $columns['status']['default'], "'\""));
        $this->assertSame('0', $this->normalizeDefault($columns['attempts']['default']));
        $this->assertSame('3', $this->normalizeDefault($columns['max_attempts']['default']));
        $this->assertSame('current_timestamp', strtolower(trim((string) $columns['available_at']['default'], "()'\"")));
        $this->assertTrue($columns['id']['auto_increment']);

        foreach (['idempotency_key', 'operation', 'aggregate_type', 'aggregate_id', 'provider_key', 'connection_key', 'status', 'lock_token', 'last_error_code', 'last_error_message'] as $column) {
            $this->assertContains($columns[$column]['type_name'], ['varchar', 'char']);
        }

        $this->assertContains($columns['payload']['type_name'], ['json', 'text']);

        foreach (['attempts', 'max_attempts'] as $column) {
            $this->assertContains($columns[$column]['type_name'], ['smallint', 'integer']);
        }

        foreach (['available_at', 'locked_at', 'last_attempted_at', 'completed_at', 'failed_at', 'created_at', 'updated_at'] as $column) {
            $this->assertContains($columns[$column]['type_name'], ['timestamp', 'datetime']);
        }

        foreach (['id', 'idempotency_key', 'operation', 'status', 'attempts', 'max_attempts', 'available_at'] as $column) {
            $this->assertFalse($columns[$column]['nullable'], $column.' must not be nullable.');
        }

        foreach (['aggregate_type', 'aggregate_id', 'provider_key', 'connection_key', 'payload', 'locked_at', 'lock_token', 'last_attempted_at', 'completed_at', 'failed_at', 'last_error_code', 'last_error_message', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue($columns[$column]['nullable'], $column.' must be nullable.');
        }
    }

    public function test_sync_outbox_indexes_match_the_frozen_contract(): void
    {
        $indexes = collect(Schema::getIndexes('sync_outbox'))->keyBy('name');

        $this->assertSame([
            'primary',
            'sync_outbox_aggregate_index',
            'sync_outbox_claim_index',
            'sync_outbox_idempotency_key_unique',
            'sync_outbox_lock_token_index',
            'sync_outbox_provider_connection_index',
            'sync_outbox_stale_index',
        ], $indexes->keys()->sort()->values()->all());
        $this->assertTrue($indexes['primary']['primary']);
        $this->assertTrue($indexes['primary']['unique']);
        $this->assertSame(['id'], $indexes['primary']['columns']);
        $this->assertTrue($indexes['sync_outbox_idempotency_key_unique']['unique']);
        $this->assertSame(['idempotency_key'], $indexes['sync_outbox_idempotency_key_unique']['columns']);
        $this->assertSame(['status', 'available_at'], $indexes['sync_outbox_claim_index']['columns']);
        $this->assertSame(['status', 'locked_at'], $indexes['sync_outbox_stale_index']['columns']);
        $this->assertSame(['aggregate_type', 'aggregate_id'], $indexes['sync_outbox_aggregate_index']['columns']);
        $this->assertSame(['provider_key', 'connection_key'], $indexes['sync_outbox_provider_connection_index']['columns']);
        $this->assertSame(['lock_token'], $indexes['sync_outbox_lock_token_index']['columns']);

        foreach ($indexes->except(['primary', 'sync_outbox_idempotency_key_unique']) as $index) {
            $this->assertFalse($index['primary']);
            $this->assertFalse($index['unique']);
        }
    }

    private function normalizeDefault(mixed $default): string
    {
        return trim((string) $default, " \t\n\r\0\x0B'\"()");
    }
}
