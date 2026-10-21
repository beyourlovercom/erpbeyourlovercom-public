<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('logistics_historical_shipment_attempt_projection_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('materialization_batch_id');
            $table->foreign('materialization_batch_id', 'log_hist_atp_batch_mat_fk')->references('id')->on('logistics_historical_order_materialization_batches')->restrictOnDelete();
            $table->char('source_checksum', 64);
            $table->string('status', 24)->default('preview');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('applied_count')->default(0);
            $table->foreignId('created_by')->nullable();
            $table->foreign('created_by', 'log_hist_atp_batch_created_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['materialization_batch_id', 'source_checksum'], 'log_hist_atp_batch_idem_uq');
        });

        Schema::create('logistics_historical_shipment_attempt_projection_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('projection_batch_id');
            $table->foreign('projection_batch_id', 'log_hist_atp_row_batch_fk')->references('id')->on('logistics_historical_shipment_attempt_projection_batches')->cascadeOnDelete();
            $table->foreignId('materialization_row_id');
            $table->foreign('materialization_row_id', 'log_hist_atp_row_mat_fk')->references('id')->on('logistics_historical_order_materialization_rows')->restrictOnDelete();
            $table->foreignId('historical_evidence_id');
            $table->foreign('historical_evidence_id', 'log_hist_atp_row_evidence_fk')->references('id')->on('logistics_historical_evidences')->restrictOnDelete();
            $table->unsignedInteger('source_row_number');
            $table->string('decision', 16);
            $table->string('decision_reason', 96);
            $table->foreignId('shipment_attempt_id')->nullable();
            $table->foreign('shipment_attempt_id', 'log_hist_atp_row_attempt_fk')->references('id')->on('shipment_attempts')->nullOnDelete();
            $table->json('projection_manifest');
            $table->timestamps();
            $table->unique(['projection_batch_id', 'materialization_row_id'], 'log_hist_atp_row_mat_uq');
            $table->unique(['projection_batch_id', 'historical_evidence_id'], 'log_hist_atp_row_evidence_uq');
            $table->index(['projection_batch_id', 'decision'], 'log_hist_atp_row_decision_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_historical_shipment_attempt_projection_rows');
        Schema::dropIfExists('logistics_historical_shipment_attempt_projection_batches');
    }
};
