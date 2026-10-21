<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->after('order_id')->constrained('warehouses')->nullOnDelete();
            $table->string('service', 128)->nullable()->after('carrier');
            $table->string('warehouse_selection_source', 32)->nullable()->after('warehouse_id');
            $table->string('carrier_selection_source', 32)->nullable()->after('service');
            $table->index(['warehouse_id', 'shipment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex(['warehouse_id', 'shipment_status']);
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn(['warehouse_id', 'warehouse_selection_source', 'service', 'carrier_selection_source']);
        });
    }
};
