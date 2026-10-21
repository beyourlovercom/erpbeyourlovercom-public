<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('logistics_historical_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('original_filename');
            $table->char('source_checksum', 64);
            $table->string('source_sheet', 128)->default('工作表1');
            $table->foreignId('reconciliation_batch_id')->nullable();
            $table->foreign('reconciliation_batch_id', 'log_hist_batch_recon_fk')->references('id')->on('kol_reconciliation_batches')->nullOnDelete();
            $table->string('status', 24)->default('preview');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('skip_count')->default(0);
            $table->foreignId('created_by')->nullable();
            $table->foreign('created_by', 'log_hist_batch_created_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreignId('committed_by')->nullable();
            $table->foreign('committed_by', 'log_hist_batch_committed_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
            $table->index(['source_checksum', 'created_at'], 'log_hist_batch_source_idx');
            $table->index(['reconciliation_batch_id', 'created_at'], 'log_hist_batch_recon_idx');
        });

        Schema::create('logistics_historical_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id');
            $table->foreign('batch_id', 'log_hist_row_batch_fk')->references('id')->on('logistics_historical_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('source_row_number');
            $table->string('source_order_no', 128)->nullable();
            $table->foreignId('kol_id')->nullable();
            $table->foreign('kol_id', 'log_hist_row_kol_fk')->references('id')->on('kols')->nullOnDelete();
            $table->string('source_status_raw', 255)->nullable();
            $table->string('source_carrier_raw', 255)->nullable();
            $table->string('source_warehouse_raw', 255)->nullable();
            $table->text('source_tracking_raw')->nullable();
            $table->string('normalized_tracking_candidate', 128)->nullable();
            $table->string('source_input_time_raw', 128)->nullable();
            $table->string('source_sku_raw', 255)->nullable();
            $table->string('source_quantity_raw', 128)->nullable();
            $table->string('classification', 16);
            $table->string('classification_reason', 64);
            $table->char('evidence_hash', 64);
            $table->timestamps();
            $table->unique(['batch_id', 'source_row_number'], 'log_hist_row_source_uq');
            $table->index(['batch_id', 'classification'], 'log_hist_row_class_idx');
            $table->index('source_order_no', 'log_hist_row_order_idx');
        });

        Schema::create('logistics_historical_evidences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id');
            $table->foreign('batch_id', 'log_hist_evidence_batch_fk')->references('id')->on('logistics_historical_import_batches')->restrictOnDelete();
            $table->foreignId('historical_row_id');
            $table->foreign('historical_row_id', 'log_hist_evidence_row_fk')->references('id')->on('logistics_historical_import_rows')->restrictOnDelete();
            $table->unsignedInteger('source_row_number');
            $table->foreignId('kol_id');
            $table->foreign('kol_id', 'log_hist_evidence_kol_fk')->references('id')->on('kols')->restrictOnDelete();
            $table->char('source_checksum', 64);
            $table->string('normalized_tracking_candidate', 128);
            $table->char('evidence_hash', 64);
            $table->json('evidence_snapshot');
            $table->timestamps();
            $table->unique(['source_checksum', 'source_row_number', 'evidence_hash'], 'log_hist_evidence_idem_uq');
            $table->index(['kol_id', 'source_row_number'], 'log_hist_evidence_kol_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_historical_evidences');
        Schema::dropIfExists('logistics_historical_import_rows');
        Schema::dropIfExists('logistics_historical_import_batches');
    }
};
