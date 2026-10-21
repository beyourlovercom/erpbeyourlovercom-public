<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('fulfillment_parent_id')->nullable()->after('customer_id')->constrained('orders')->cascadeOnDelete();
            $table->string('fulfillment_suffix', 4)->nullable()->after('fulfillment_parent_id');
            $table->unique(['fulfillment_parent_id', 'fulfillment_suffix'], 'orders_fulfillment_parent_suffix_unique');
            $table->index(['fulfillment_parent_id', 'order_status'], 'orders_fulfillment_parent_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_fulfillment_parent_status_index');
            $table->dropUnique('orders_fulfillment_parent_suffix_unique');
            $table->dropConstrainedForeignId('fulfillment_parent_id');
            $table->dropColumn('fulfillment_suffix');
        });
    }
};
