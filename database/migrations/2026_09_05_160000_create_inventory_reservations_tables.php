<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('reservation_key', 64)->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 32)->default('reserved');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->string('release_reason', 191)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['order_id', 'status'], 'inv_res_order_status_idx');
            $table->index(['warehouse_id', 'status'], 'inv_res_wh_status_idx');
        });

        Schema::create('inventory_reservation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_reservation_id')->constrained('inventory_reservations')->cascadeOnDelete();
            $table->foreignId('warehouse_inventory_id')->constrained('warehouse_inventory')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['inventory_reservation_id', 'product_id'], 'inv_res_item_res_product_uq');
            $table->index('warehouse_inventory_id', 'inv_res_item_inventory_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservation_items');
        Schema::dropIfExists('inventory_reservations');
    }
};
