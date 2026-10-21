<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_historical_order_materialization_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historical_import_batch_id');
            $table->foreign('historical_import_batch_id', 'log_hist_mat_batch_import_fk')->references('id')->on('logistics_historical_import_batches')->restrictOnDelete();
            $table->char('source_checksum', 64);
            $table->string('status', 24)->default('preview');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('already_applied_count')->default(0);
            $table->foreignId('created_by')->nullable();
            $table->foreign('created_by', 'log_hist_mat_batch_creator_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['historical_import_batch_id', 'source_checksum'], 'log_hist_mat_batch_import_uq');
            $table->index(['source_checksum', 'status'], 'log_hist_mat_batch_checksum_idx');
        });

        Schema::create('logistics_historical_order_materialization_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('materialization_batch_id');
            $table->foreign('materialization_batch_id', 'log_hist_mat_row_batch_fk')->references('id')->on('logistics_historical_order_materialization_batches')->cascadeOnDelete();
            $table->foreignId('historical_evidence_id');
            $table->foreign('historical_evidence_id', 'log_hist_mat_row_evidence_fk')->references('id')->on('logistics_historical_evidences')->restrictOnDelete();
            $table->unsignedInteger('source_row_number');
            $table->string('source_order_no', 128)->nullable();
            $table->string('decision', 24);
            $table->string('decision_reason', 96);
            $table->json('materialization_manifest');
            $table->foreignId('order_id')->nullable();
            $table->foreign('order_id', 'log_hist_mat_row_order_fk')->references('id')->on('orders')->nullOnDelete();
            $table->timestamps();
            $table->unique(['materialization_batch_id', 'historical_evidence_id'], 'log_hist_mat_row_evidence_uq');
            $table->index(['materialization_batch_id', 'decision'], 'log_hist_mat_row_decision_idx');
            $table->index('source_order_no', 'log_hist_mat_row_order_no_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_historical_order_materialization_rows');
        Schema::dropIfExists('logistics_historical_order_materialization_batches');
    }
};
