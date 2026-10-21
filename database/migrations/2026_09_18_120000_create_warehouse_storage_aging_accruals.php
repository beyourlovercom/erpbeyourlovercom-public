<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_storage_aging_accruals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('stock_status', 16);
            $table->date('putaway_date');
            $table->unsignedSmallInteger('aging_month_index');
            $table->date('cycle_started_on');
            $table->unsignedInteger('quantity_snapshot');
            $table->decimal('unit_rate_cny', 10, 2)->default(1.00);
            $table->decimal('amount_cny', 14, 2);
            $table->foreignId('source_sync_batch_id')->nullable()->constrained('inventory_sync_batches')->nullOnDelete();
            $table->string('source_reference')->unique();
            $table->date('accrual_as_of');
            $table->boolean('is_backfill')->default(false);
            $table->timestamp('accrued_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['warehouse_id', 'product_id', 'stock_status', 'putaway_date', 'aging_month_index'],
                'wh_storage_aging_business_key_uq',
            );
            $table->index(['warehouse_id', 'accrual_as_of'], 'wh_storage_aging_asof_idx');
            $table->index(['product_id', 'putaway_date'], 'wh_storage_aging_product_putaway_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_storage_aging_accruals');
    }
};
