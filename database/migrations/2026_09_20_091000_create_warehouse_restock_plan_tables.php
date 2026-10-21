<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_restock_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('reference', 96)->unique();
            $table->string('status', 32)->default('confirmed');
            $table->char('source_checksum', 64);
            $table->char('snapshot_checksum', 64);
            $table->json('rule_snapshot');
            $table->json('summary_snapshot');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->index(['warehouse_id', 'confirmed_at'], 'warehouse_restock_plans_warehouse_confirmed_idx');
            $table->index(['warehouse_id', 'snapshot_checksum'], 'warehouse_restock_plans_snapshot_idx');
        });

        Schema::create('warehouse_restock_plan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restock_plan_id')->constrained('warehouse_restock_plans')->cascadeOnDelete();
            // Snapshot evidence must survive later Product master changes/deletion.
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('product_sku', 128);
            $table->string('label_sku', 128);
            $table->string('label_description', 512)->nullable();
            $table->string('product_name', 512);
            $table->unsignedInteger('final_restock_qty');
            $table->unsignedInteger('formula_restock_qty');
            $table->unsignedInteger('manual_restock_qty');
            $table->unsignedInteger('shipped_120d_qty');
            $table->unsignedInteger('quantity_available');
            $table->unsignedInteger('quantity_on_hand');
            $table->unsignedInteger('quantity_reserved');
            $table->unsignedInteger('units_per_carton')->nullable();
            $table->unsignedInteger('carton_count')->nullable();
            $table->unsignedBigInteger('packaging_profile_id')->nullable();
            $table->unsignedBigInteger('packaging_measurement_record_id')->nullable();
            $table->string('packaging_method_label', 128)->nullable();
            $table->decimal('packaging_length_cm', 10, 2)->nullable();
            $table->decimal('packaging_width_cm', 10, 2)->nullable();
            $table->decimal('packaging_height_cm', 10, 2)->nullable();
            $table->decimal('packaged_weight_kg', 10, 3)->nullable();
            $table->decimal('logistics_weight_kg', 10, 4)->nullable();
            $table->string('weight_source', 32)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['restock_plan_id', 'product_id'], 'warehouse_restock_plan_items_plan_product_uq');
            $table->index(['restock_plan_id', 'sort_order'], 'warehouse_restock_plan_items_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_restock_plan_items');
        Schema::dropIfExists('warehouse_restock_plans');
    }
};
