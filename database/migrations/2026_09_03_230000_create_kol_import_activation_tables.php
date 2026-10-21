<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_import_activation_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_preview_batch_id')->constrained('kol_import_preview_batches')->cascadeOnDelete();
            $table->foreignId('reconciliation_batch_id')->constrained('kol_reconciliation_batches')->cascadeOnDelete();
            $table->char('source_checksum', 64);
            $table->unsignedInteger('plan_version')->default(1);
            $table->string('status', 24)->default('prepared');
            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->unsignedInteger('existing_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->char('plan_checksum', 64);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamp('applied_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->index(['import_preview_batch_id', 'status'], 'kol_activation_preview_status_idx');
            $table->index(['reconciliation_batch_id', 'status'], 'kol_activation_reconciliation_status_idx');
        });

        Schema::create('kol_import_activation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activation_batch_id')->constrained('kol_import_activation_batches')->cascadeOnDelete();
            $table->foreignId('preview_candidate_id')->nullable()->constrained('kol_import_preview_candidates')->nullOnDelete();
            $table->char('cluster_key', 64);
            $table->string('readiness_status', 24);
            $table->string('preview_status', 32);
            $table->json('block_reasons')->nullable();
            $table->foreignId('effective_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('canonical_proposal');
            $table->json('social_proposal')->nullable();
            $table->json('exact_identity')->nullable();
            $table->json('existing_guard')->nullable();
            $table->json('source_owner_evidence')->nullable();
            $table->json('affiliate_evidence')->nullable();
            $table->json('history_summary')->nullable();
            $table->foreignId('created_kol_id')->nullable()->constrained('kols')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['activation_batch_id', 'cluster_key'], 'kol_activation_item_cluster_uq');
            $table->index(['activation_batch_id', 'readiness_status'], 'kol_activation_item_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_import_activation_items');
        Schema::dropIfExists('kol_import_activation_batches');
    }
};
