<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 191)->unique();
            $table->string('operation', 100);
            $table->string('aggregate_type', 64)->nullable();
            $table->string('aggregate_id', 127)->nullable();
            $table->string('provider_key', 64)->nullable();
            $table->string('connection_key', 127)->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('locked_at')->nullable();
            $table->char('lock_token', 36)->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at'], 'sync_outbox_claim_index');
            $table->index(['status', 'locked_at'], 'sync_outbox_stale_index');
            $table->index(['aggregate_type', 'aggregate_id'], 'sync_outbox_aggregate_index');
            $table->index(['provider_key', 'connection_key'], 'sync_outbox_provider_connection_index');
            $table->index('lock_token', 'sync_outbox_lock_token_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_outbox');
    }
};
