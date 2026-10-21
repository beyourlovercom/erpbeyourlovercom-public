<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('label_sku', 128)->nullable()->unique()->after('sku');
            $table->string('label_description', 512)->nullable()->after('label_sku');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_label_sku_unique');
            $table->dropColumn(['label_sku', 'label_description']);
        });
    }
};
