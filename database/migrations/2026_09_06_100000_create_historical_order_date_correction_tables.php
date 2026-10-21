<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('logistics_historical_order_date_correction_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('materialization_batch_id');
            $table->foreign('materialization_batch_id', 'log_hist_odc_batch_mat_fk')->references('id')->on('logistics_historical_order_materialization_batches')->restrictOnDelete();
            $table->char('source_checksum', 64);
            $table->string('correction_version', 64);
            $table->date('as_of_date');
            $table->string('status', 24)->default('preview');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('applied_count')->default(0);
            $table->foreignId('created_by')->nullable();
            $table->foreign('created_by', 'log_hist_odc_batch_creator_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['materialization_batch_id', 'source_checksum', 'correction_version', 'as_of_date'], 'log_hist_odc_batch_idem_uq');
            $table->index(['source_checksum', 'status'], 'log_hist_odc_batch_checksum_idx');
        });

        Schema::create('logistics_historical_order_date_correction_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('correction_batch_id');
            $table->foreign('correction_batch_id', 'log_hist_odc_row_batch_fk')->references('id')->on('logistics_historical_order_date_correction_batches')->cascadeOnDelete();
            $table->foreignId('materialization_row_id');
            $table->foreign('materialization_row_id', 'log_hist_odc_row_mat_fk')->references('id')->on('logistics_historical_order_materialization_rows')->restrictOnDelete();
            $table->foreignId('order_id');
            $table->foreign('order_id', 'log_hist_odc_row_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreignId('historical_evidence_id');
            $table->foreign('historical_evidence_id', 'log_hist_odc_row_evidence_fk')->references('id')->on('logistics_historical_evidences')->restrictOnDelete();
            $table->unsignedInteger('source_row_number');
            $table->string('decision', 16);
            $table->string('decision_reason', 96);
            $table->date('current_order_date');
            $table->date('proposed_order_date')->nullable();
            $table->json('correction_manifest');
            $table->timestamps();
            $table->unique(['correction_batch_id', 'materialization_row_id', 'order_id'], 'log_hist_odc_row_identity_uq');
            $table->index(['correction_batch_id', 'decision'], 'log_hist_odc_row_decision_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_historical_order_date_correction_rows');
        Schema::dropIfExists('logistics_historical_order_date_correction_batches');
    }
};
