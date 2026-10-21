<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_historical_rescue_attempt_projection_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rescue_order_projection_batch_id');
            $table->foreign('rescue_order_projection_batch_id', 'log_hist_rap_batch_order_fk')->references('id')->on('logistics_historical_rescue_order_projection_batches')->restrictOnDelete();
            $table->char('source_checksum', 64);
            $table->string('projection_version', 64);
            $table->string('status', 24)->default('preview');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('applied_count')->default(0);
            $table->char('ready_manifest_sha256', 64)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreign('created_by', 'log_hist_rap_batch_creator_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['rescue_order_projection_batch_id', 'source_checksum', 'projection_version'], 'log_hist_rap_batch_idem_uq');
            $table->index(['source_checksum', 'status'], 'log_hist_rap_batch_status_idx');
        });

        Schema::create('logistics_historical_rescue_attempt_projection_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('projection_batch_id');
            $table->foreign('projection_batch_id', 'log_hist_rap_row_batch_fk')->references('id')->on('logistics_historical_rescue_attempt_projection_batches')->cascadeOnDelete();
            $table->foreignId('rescue_order_projection_row_id');
            $table->foreign('rescue_order_projection_row_id', 'log_hist_rap_row_order_row_fk')->references('id')->on('logistics_historical_rescue_order_projection_rows')->restrictOnDelete();
            $table->foreignId('historical_evidence_id');
            $table->foreign('historical_evidence_id', 'log_hist_rap_row_evidence_fk')->references('id')->on('logistics_historical_evidences')->restrictOnDelete();
            $table->foreignId('order_id');
            $table->foreign('order_id', 'log_hist_rap_row_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->unsignedInteger('source_row_number');
            $table->unsignedInteger('attempt_number');
            $table->string('decision', 24);
            $table->string('decision_reason', 96);
            $table->foreignId('shipment_attempt_id')->nullable();
            $table->foreign('shipment_attempt_id', 'log_hist_rap_row_attempt_fk')->references('id')->on('shipment_attempts')->nullOnDelete();
            $table->json('projection_manifest');
            $table->timestamps();
            $table->unique(['projection_batch_id', 'historical_evidence_id'], 'log_hist_rap_row_evidence_uq');
            $table->unique(['projection_batch_id', 'order_id', 'attempt_number'], 'log_hist_rap_row_attempt_no_uq');
            $table->index(['projection_batch_id', 'decision'], 'log_hist_rap_row_decision_idx');
            $table->index('source_row_number', 'log_hist_rap_row_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_historical_rescue_attempt_projection_rows');
        Schema::dropIfExists('logistics_historical_rescue_attempt_projection_batches');
    }
};
