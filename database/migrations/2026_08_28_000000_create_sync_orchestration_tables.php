<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('run_type', 64);
            $table->string('provider_key', 64)->nullable();
            $table->string('connection_key', 127)->nullable();
            $table->string('status', 24)->default('running');
            $table->unsignedInteger('requested_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('succeeded_items')->default(0);
            $table->unsignedInteger('retrying_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->unsignedInteger('conflict_items')->default(0);
            $table->unsignedInteger('skipped_items')->default(0);
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('context')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at'], 'sync_runs_status_started_index');
            $table->index(['run_type', 'created_at'], 'sync_runs_type_created_index');
            $table->index(['provider_key', 'connection_key'], 'sync_runs_provider_connection_index');
        });

        Schema::create('sync_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('operation', 100);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->foreignId('outbox_id')->nullable()->constrained('sync_outbox')->nullOnDelete();
            $table->string('provider_reference', 127)->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('result_code', 100)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['sync_run_id', 'sequence'], 'sync_run_items_run_sequence_unique');
            $table->index(['sync_run_id', 'status'], 'sync_run_items_run_status_index');
            $table->index(['operation', 'status'], 'sync_run_items_operation_status_index');
            $table->index(['order_id', 'shipment_id'], 'sync_run_items_order_shipment_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_run_items');
        Schema::dropIfExists('sync_runs');
    }
};
