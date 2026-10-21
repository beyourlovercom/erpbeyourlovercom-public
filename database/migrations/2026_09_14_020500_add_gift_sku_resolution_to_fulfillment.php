<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('source_sku', 128)->nullable()->after('sku');
            $table->string('resolved_fulfillment_sku', 128)->nullable()->after('source_sku');
        });

        Schema::create('warehouse_gift_sku_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('source_sku', 128)->default('gift');
            $table->foreignId('fulfillment_product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['warehouse_id', 'source_sku', 'fulfillment_product_id'], 'gift_sku_mapping_unique');
            $table->index(['source_sku', 'is_active'], 'gift_sku_source_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_gift_sku_mappings');
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['source_sku', 'resolved_fulfillment_sku']);
        });
    }
};
