<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->unsignedSmallInteger('replenishment_lead_time_days')->nullable()->after('priority');
            $table->unsignedSmallInteger('replenishment_safety_stock_days')->nullable()->after('replenishment_lead_time_days');
            $table->unsignedSmallInteger('replenishment_target_cover_days')->nullable()->after('replenishment_safety_stock_days');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropColumn([
                'replenishment_lead_time_days',
                'replenishment_safety_stock_days',
                'replenishment_target_cover_days',
            ]);
        });
    }
};
