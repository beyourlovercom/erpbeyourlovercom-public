<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('woocommerce_product_id')->nullable()->unique();
            $table->string('source_type', 32)->default('woocommerce');
            $table->string('product_type', 64)->default('simple');
            $table->string('sku', 128)->nullable()->unique();
            $table->string('gtin', 128)->nullable();
            $table->string('name', 512);
            $table->string('unit', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('weight_kg', 10, 4)->nullable();
            $table->decimal('sale_price', 18, 2)->nullable();
            $table->decimal('regular_price', 18, 2)->nullable();
            $table->decimal('shipping_weight_kg', 10, 4)->nullable();
            $table->decimal('length_cm', 10, 2)->nullable();
            $table->decimal('width_cm', 10, 2)->nullable();
            $table->decimal('height_cm', 10, 2)->nullable();
            $table->string('country_of_origin', 2)->nullable();
            $table->string('hs_code', 32)->nullable();
            $table->decimal('standard_production_cost', 18, 4)->nullable();
            $table->decimal('standard_packaging_cost', 18, 4)->nullable();
            $table->decimal('standard_purchase_cost', 18, 4)->nullable();
            $table->string('cost_currency', 3)->nullable();
            $table->text('cost_note')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('sku');
            $table->index('name');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->after('id')->constrained('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_id');
        });
        Schema::dropIfExists('products');
    }
};
