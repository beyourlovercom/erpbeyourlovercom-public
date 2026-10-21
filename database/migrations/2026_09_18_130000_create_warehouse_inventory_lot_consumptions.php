<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_inventory_lot_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id');
            $table->foreignId('order_id');
            $table->foreignId('order_item_id');
            $table->foreignId('warehouse_id');
            $table->foreignId('product_id');
            $table->string('stock_status', 16);
            $table->date('putaway_date');
            $table->foreignId('source_sync_batch_id');
            $table->unsignedInteger('quantity');
            $table->unsignedSmallInteger('aging_months_charged')->default(0);
            $table->decimal('unit_storage_cost_cny', 10, 2)->default(0);
            $table->decimal('allocated_storage_cost_cny', 14, 2)->default(0);
            $table->timestamp('consumed_at');
            $table->string('lot_business_key', 64);
            $table->string('source_reference')->unique();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->foreign('shipment_id', 'wh_lot_cons_shipment_fk')->references('id')->on('shipments')->restrictOnDelete();
            $table->foreign('order_id', 'wh_lot_cons_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('order_item_id', 'wh_lot_cons_order_item_fk')->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('warehouse_id', 'wh_lot_cons_warehouse_fk')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('product_id', 'wh_lot_cons_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('source_sync_batch_id', 'wh_lot_cons_sync_batch_fk')->references('id')->on('inventory_sync_batches')->restrictOnDelete();

            $table->unique(
                ['shipment_id', 'order_item_id', 'source_sync_batch_id', 'lot_business_key'],
                'wh_lot_consumption_business_key_uq',
            );
            $table->index(['warehouse_id', 'product_id', 'source_sync_batch_id'], 'wh_lot_consumption_availability_idx');
            $table->index(['order_id', 'consumed_at'], 'wh_lot_consumption_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_inventory_lot_consumptions');
    }
};
