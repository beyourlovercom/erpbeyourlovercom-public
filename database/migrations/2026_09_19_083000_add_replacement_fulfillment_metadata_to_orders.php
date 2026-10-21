<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('fulfillment_role', 24)->nullable()->after('fulfillment_suffix');
            $table->foreignId('fulfillment_origin_id')->nullable()->after('fulfillment_role')->constrained('orders')->nullOnDelete();
            $table->string('fulfillment_reason', 32)->nullable()->after('fulfillment_origin_id');
            $table->text('fulfillment_note')->nullable()->after('fulfillment_reason');
            $table->index(['fulfillment_parent_id', 'fulfillment_role'], 'orders_fulfillment_parent_role_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_fulfillment_parent_role_index');
            $table->dropConstrainedForeignId('fulfillment_origin_id');
            $table->dropColumn(['fulfillment_role', 'fulfillment_reason', 'fulfillment_note']);
        });
    }
};
