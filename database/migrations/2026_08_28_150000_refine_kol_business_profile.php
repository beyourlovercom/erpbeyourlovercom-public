<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kols', function (Blueprint $table): void {
            $table->string('gender', 16)->nullable()->after('level');
            $table->dropIndex(['city']);
            $table->dropColumn(['city', 'store_count']);
        });
    }

    public function down(): void
    {
        Schema::table('kols', function (Blueprint $table): void {
            $table->string('city')->nullable()->after('gender');
            $table->unsignedInteger('store_count')->nullable()->after('product_quantity');
            $table->index('city');
            $table->dropColumn('gender');
        });
    }
};
