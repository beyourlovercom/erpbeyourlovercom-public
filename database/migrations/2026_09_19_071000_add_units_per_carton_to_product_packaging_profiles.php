<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_packaging_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('units_per_carton')->nullable()->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('product_packaging_profiles', function (Blueprint $table): void {
            $table->dropColumn('units_per_carton');
        });
    }
};
