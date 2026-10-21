<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('woocommerce_line_item_id')->nullable()->after('product_id');
            $table->index('woocommerce_line_item_id', 'order_items_woo_line_item_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_woo_line_item_index');
            $table->dropColumn('woocommerce_line_item_id');
        });
    }
};
