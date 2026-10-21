<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kol_owner_source_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('source_owner_key', 191)->unique();
            $table->string('source_owner_value', 191);
            $table->foreignId('mapped_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('mapped_user_id', 'kol_owner_source_mapping_user_idx');
        });

        Schema::create('kol_owner_candidate_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reconciliation_batch_id')->constrained('kol_reconciliation_batches')->cascadeOnDelete();
            $table->char('cluster_key', 64);
            $table->foreignId('mapped_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('source_owner_values');
            $table->text('reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['reconciliation_batch_id', 'cluster_key'], 'kol_owner_candidate_decision_cluster_uq');
            $table->index('mapped_user_id', 'kol_owner_candidate_decision_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_owner_candidate_decisions');
        Schema::dropIfExists('kol_owner_source_mappings');
    }
};
