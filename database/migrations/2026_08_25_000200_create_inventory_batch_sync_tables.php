<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_sync_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('source_type', 32); // paste | csv | xlsx | api
            $table->string('sync_mode', 16);   // partial | full
            $table->string('original_filename', 255)->nullable();
            $table->string('checksum', 64)->nullable();
            $table->timestamp('stock_count_at')->nullable();
            $table->unsignedInteger('preview_total_rows')->default(0);
            $table->unsignedInteger('preview_unique_skus')->default(0);
            $table->unsignedInteger('preview_sellable_qty')->default(0);
            $table->unsignedInteger('preview_defective_qty')->default(0);
            $table->unsignedInteger('preview_unmatched_skus')->default(0);
            $table->unsignedInteger('preview_duplicate_skus')->default(0);
            $table->unsignedInteger('preview_warning_rows')->default(0);
            $table->unsignedInteger('preview_zeroed_skus')->default(0);
            $table->unsignedInteger('preview_error_rows')->default(0);
            $table->string('status', 16)->default('preview'); // preview | confirmed | abandoned
            $table->foreignId('creator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('warehouse_inventory_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('stock_status', 16)->default('sellable'); // sellable | defective
            $table->date('putaway_date')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->foreignId('sync_batch_id')->nullable()->constrained('inventory_sync_batches')->nullOnDelete();
            $table->timestamps();
            $table->index(['warehouse_id', 'product_id', 'stock_status'], 'wh_inv_lots_wh_product_status_idx');
        });

        Schema::create('inventory_sync_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sync_batch_id')->constrained('inventory_sync_batches')->cascadeOnDelete();
            $table->unsignedInteger('source_row_no');
            $table->string('raw_sku', 255)->nullable();
            $table->string('raw_product_name', 255)->nullable();
            $table->string('raw_warehouse_name', 255)->nullable();
            $table->string('raw_stock_status', 64)->nullable();
            $table->string('raw_stock_count_date', 64)->nullable();
            $table->string('raw_putaway_date', 64)->nullable();
            $table->string('raw_quantity', 64)->nullable();
            $table->string('raw_dimension', 255)->nullable();
            $table->string('raw_total_volume', 64)->nullable();
            // normalized
            $table->string('sku', 128)->nullable();
            $table->string('stock_status', 16)->nullable(); // sellable | defective | null if unknown
            $table->date('stock_count_at')->nullable();
            $table->date('putaway_date')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('validation_status', 16)->default('ok'); // ok | warning | error
            $table->string('validation_message', 512)->nullable();
            $table->timestamps();
            $table->index(['sync_batch_id', 'validation_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_sync_rows');
        Schema::dropIfExists('warehouse_inventory_lots');
        Schema::dropIfExists('inventory_sync_batches');
    }
};
