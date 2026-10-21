<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sync_run_items', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->after('shipment_id')->constrained('products')->nullOnDelete();
            $table->index(['product_id', 'operation'], 'sync_run_items_product_operation_index');
        });
    }

    public function down(): void
    {
        Schema::table('sync_run_items', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
            $table->dropIndex('sync_run_items_product_operation_index');
            $table->dropColumn('product_id');
        });
    }
};
