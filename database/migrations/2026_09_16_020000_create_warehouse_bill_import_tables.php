<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('warehouse_bill_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('original_filename', 255);
            $table->char('source_checksum', 64);
            $table->string('parser_version', 32);
            $table->char('plan_checksum', 64);
            $table->string('status', 16)->default('applied');
            $table->string('invoice_number', 128)->nullable();
            $table->string('client_name', 191)->nullable();
            $table->date('invoice_date')->nullable();
            $table->char('currency', 3);
            $table->decimal('invoice_total', 18, 4);
            $table->decimal('shipping_total', 18, 4)->default(0);
            $table->decimal('parcel_outbound_total', 18, 4)->default(0);
            $table->decimal('storage_total', 18, 4)->default(0);
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('shipping_rows')->default(0);
            $table->unsignedInteger('parcel_outbound_rows')->default(0);
            $table->unsignedInteger('storage_rows')->default(0);
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'source_checksum'], 'warehouse_bill_batch_provider_checksum_unique');
            $table->index(['warehouse_id', 'invoice_date'], 'warehouse_bill_batch_warehouse_invoice_idx');
        });

        Schema::create('warehouse_bill_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('warehouse_bill_import_batches')->cascadeOnDelete();
            $table->string('category', 32);
            $table->string('source_sheet', 64);
            $table->unsignedInteger('source_row_number');
            $table->string('transaction_number', 128)->nullable();
            $table->string('outbound_order_number', 128)->nullable();
            $table->string('tracking_number', 191)->nullable();
            $table->date('charged_on');
            $table->unsignedInteger('quantity')->default(0);
            $table->string('charge_item_name', 255)->nullable();
            $table->decimal('charge_amount', 18, 4);
            $table->char('row_hash', 64);
            $table->json('raw_data');
            $table->json('normalized_data');
            $table->timestamps();
            $table->unique(['batch_id', 'category', 'source_row_number'], 'warehouse_bill_row_source_unique');
            $table->index(['batch_id', 'category'], 'warehouse_bill_row_batch_category_idx');
            $table->index(['tracking_number', 'category'], 'warehouse_bill_row_tracking_category_idx');
            $table->index(['charged_on', 'category'], 'warehouse_bill_row_date_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_bill_import_rows');
        Schema::dropIfExists('warehouse_bill_import_batches');
    }
};
