<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_inbound_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('reference', 80);
            $table->string('transport_mode', 24);
            $table->string('status', 24)->default('in_transit');
            $table->unsignedInteger('carton_count')->nullable();
            $table->unsignedInteger('quantity_total')->default(0);
            $table->decimal('gross_weight_kg', 12, 4)->nullable();
            $table->decimal('volume_cbm', 12, 6)->nullable();
            $table->date('shipped_on')->nullable();
            $table->date('eta_date')->nullable();
            $table->boolean('eta_is_estimated')->default(true);
            $table->date('received_on')->nullable();
            $table->string('source_manifest_checksum', 64)->nullable();
            $table->string('source_filename', 255)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'reference'], 'wh_inbound_batch_reference_uq');
            $table->index(['warehouse_id', 'status', 'eta_date'], 'wh_inbound_batch_status_idx');
        });

        Schema::create('warehouse_inbound_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('warehouse_inbound_batches')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('source_sku', 80)->nullable();
            $table->string('canonical_sku', 80)->nullable();
            $table->string('source_product_name', 255)->nullable();
            $table->unsignedInteger('quantity');
            $table->string('evidence_type', 24)->default('actual_manifest');
            $table->string('match_status', 24)->default('matched');
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'product_id'], 'wh_inbound_item_product_idx');
            $table->index(['canonical_sku'], 'wh_inbound_item_sku_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_inbound_items');
        Schema::dropIfExists('warehouse_inbound_batches');
    }
};
