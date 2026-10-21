<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_inventory_lot_consumptions', function (Blueprint $table): void {
            // A sellable lot can originate from an authoritative inventory sync or
            // directly from a received inbound batch. Keep the sync reference when
            // present, but do not require one for direct inbound lots.
            $table->unsignedBigInteger('source_sync_batch_id')->nullable()->change();

            // Intentionally not an FK: authoritative inventory sync may replace/delete
            // current lots while immutable consumption evidence must remain traceable.
            $table->unsignedBigInteger('source_inventory_lot_id')->nullable()->after('source_sync_batch_id');
            $table->index(
                ['warehouse_id', 'product_id', 'source_inventory_lot_id'],
                'wh_lot_consumption_source_lot_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_inventory_lot_consumptions', function (Blueprint $table): void {
            $table->dropIndex('wh_lot_consumption_source_lot_idx');
            $table->dropColumn('source_inventory_lot_id');
            $table->unsignedBigInteger('source_sync_batch_id')->nullable(false)->change();
        });
    }
};
