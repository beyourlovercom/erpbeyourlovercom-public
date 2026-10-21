<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('carrier_billing_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->string('original_filename', 255);
            $table->char('source_checksum', 64);
            $table->string('parser_version', 32);
            $table->char('plan_checksum', 64);
            $table->string('status', 16)->default('applied');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('skip_count')->default(0);
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'source_checksum'], 'carrier_bill_batch_provider_checksum_unique');
            $table->index(['status', 'applied_at'], 'carrier_bill_batch_status_applied_idx');
        });

        Schema::create('carrier_billing_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('carrier_billing_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('source_row_number');
            $table->string('tracking_number', 191)->nullable();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->foreignId('billing_id')->nullable()->constrained('shipment_carrier_billing')->nullOnDelete();
            $table->string('decision', 16);
            $table->string('reason_code', 64)->nullable();
            $table->decimal('actual_cost', 14, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('chargeable_weight_kg', 12, 3)->nullable();
            $table->string('billing_document_number', 128)->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->timestamp('billed_at')->nullable();
            $table->char('row_hash', 64);
            $table->json('raw_data');
            $table->json('normalized_data');
            $table->timestamps();
            $table->unique(['batch_id', 'source_row_number'], 'carrier_bill_row_batch_source_unique');
            $table->index(['batch_id', 'decision'], 'carrier_bill_row_batch_decision_idx');
            $table->index(['tracking_number', 'shipment_id'], 'carrier_bill_row_tracking_shipment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_billing_import_rows');
        Schema::dropIfExists('carrier_billing_import_batches');
    }
};
