<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('warehouse_storage_cost_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('billing_period_start');
            $table->date('billing_period_end');
            $table->string('original_filename', 255);
            $table->char('source_checksum', 64);
            $table->string('parser_version', 32);
            $table->char('plan_checksum', 64);
            $table->string('status', 16)->default('applied');
            $table->decimal('storage_pool_amount', 18, 4);
            $table->char('currency', 3);
            $table->decimal('allocation_rate_per_unit', 18, 8);
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('ready_count');
            $table->unsignedInteger('excluded_count');
            $table->unsignedInteger('review_count');
            $table->unsignedInteger('total_outbound_units');
            $table->unsignedInteger('ready_customer_units');
            $table->unsignedInteger('excluded_units');
            $table->unsignedInteger('review_units');
            $table->decimal('ready_customer_source_amount', 18, 4)->default(0);
            $table->decimal('excluded_source_amount', 18, 4)->default(0);
            $table->decimal('review_source_amount', 18, 4)->default(0);
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'source_checksum'], 'warehouse_storage_batch_provider_checksum_unique');
            $table->index(['warehouse_id', 'billing_period_start', 'billing_period_end'], 'warehouse_storage_batch_period_idx');
        });

        Schema::create('warehouse_storage_cost_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('warehouse_storage_cost_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('source_row_number');
            $table->string('reference_number', 128)->nullable();
            $table->string('tracking_number', 191)->nullable();
            $table->unsignedInteger('outbound_units');
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('decision', 16);
            $table->string('reason_code', 64)->nullable();
            $table->decimal('allocated_source_amount', 18, 4)->nullable();
            $table->char('row_hash', 64);
            $table->json('raw_data');
            $table->json('normalized_data');
            $table->timestamps();
            $table->unique(['batch_id', 'source_row_number'], 'warehouse_storage_row_batch_source_unique');
            $table->index(['batch_id', 'decision'], 'warehouse_storage_row_batch_decision_idx');
            $table->index(['order_id', 'decision'], 'warehouse_storage_row_order_decision_idx');
            $table->index(['tracking_number', 'shipment_id'], 'warehouse_storage_row_tracking_shipment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_storage_cost_import_rows');
        Schema::dropIfExists('warehouse_storage_cost_import_batches');
    }
};
