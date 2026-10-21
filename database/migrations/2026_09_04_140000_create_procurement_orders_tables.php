<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('procurement_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('procurement_number', 32)->unique();
            $table->foreignId('source_order_id')->constrained('orders')->cascadeOnDelete()->unique();
            $table->string('status', 32)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('procurement_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('procurement_order_id')->constrained('procurement_orders')->cascadeOnDelete();
            $table->foreignId('source_order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('sku', 128)->nullable();
            $table->string('product_summary')->nullable();
            $table->unsignedInteger('source_quantity');
            $table->text('purchase_url')->nullable();
            $table->string('purchase_size', 255)->nullable();
            $table->unsignedInteger('purchase_quantity');
            $table->timestamps();
            $table->unique(['procurement_order_id', 'source_order_item_id'], 'proc_order_item_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_order_items');
        Schema::dropIfExists('procurement_orders');
    }
};
