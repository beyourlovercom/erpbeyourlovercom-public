<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_restock_document_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('restock_plan_id')->constrained('warehouse_restock_plans')->restrictOnDelete();
            $table->string('reference', 128)->unique();
            $table->string('document_kind', 64);
            $table->string('status', 32)->default('queued');
            $table->string('schema_version', 64);
            $table->string('template_version', 128);
            $table->string('generator_version', 128);
            $table->string('dispatch_mode', 128);
            $table->char('plan_snapshot_checksum', 64);
            $table->char('payload_checksum', 64);
            $table->json('payload');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('worker_id', 128)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('result_manifest')->nullable();
            $table->char('result_manifest_checksum', 64)->nullable();
            $table->string('output_root', 1024)->nullable();
            $table->string('last_error_code', 128)->nullable();
            $table->text('last_error_message')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamps();

            $table->unique(
                ['restock_plan_id', 'document_kind'],
                'warehouse_restock_document_jobs_plan_kind_uq',
            );
            $table->index(
                ['document_kind', 'status', 'requested_at'],
                'warehouse_restock_document_jobs_queue_idx',
            );
            $table->index(
                ['worker_id', 'status'],
                'warehouse_restock_document_jobs_worker_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_restock_document_jobs');
    }
};
