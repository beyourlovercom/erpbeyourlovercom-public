<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kol_import_preview_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reconciliation_batch_id')->unique()->constrained('kol_reconciliation_batches')->cascadeOnDelete();
            $table->foreignId('creator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('preview');
            $table->unsignedInteger('total_source_rows')->default(0);
            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('unresolved_source_rows')->default(0);
            $table->json('preview_summary')->nullable();
            $table->timestamps();
        });

        Schema::create('kol_import_preview_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_preview_batch_id')->constrained('kol_import_preview_batches')->cascadeOnDelete();
            $table->char('cluster_key', 64);
            $table->json('source_row_ids');
            $table->unsignedInteger('source_row_count');
            $table->json('exact_identity');
            $table->json('proposed_kol');
            $table->json('proposed_social_accounts')->nullable();
            $table->json('owner_evidence')->nullable();
            $table->string('owner_resolution_status', 24)->default('none');
            $table->foreignId('matched_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('history_summary')->nullable();
            $table->foreignId('matched_existing_kol_id')->nullable()->constrained('kols')->nullOnDelete();
            $table->string('existing_kol_status', 24)->default('none');
            $table->json('affiliate_account_ids')->nullable();
            $table->string('preview_status', 32);
            $table->text('message')->nullable();
            $table->timestamps();
            $table->unique(['import_preview_batch_id', 'cluster_key'], 'kol_import_preview_candidate_cluster_uq');
            $table->index(['import_preview_batch_id', 'preview_status'], 'kol_import_preview_candidate_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_import_preview_candidates');
        Schema::dropIfExists('kol_import_preview_batches');
    }
};
