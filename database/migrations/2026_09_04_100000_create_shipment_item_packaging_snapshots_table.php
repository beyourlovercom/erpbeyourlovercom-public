<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shipment_item_packaging_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_packaging_profile_id')->nullable()->nullOnDelete();
            $table->foreign('product_packaging_profile_id', 'sips_product_pkg_profile_id_fk')->references('id')->on('product_packaging_profiles')->nullOnDelete();
            $table->foreignId('packaging_measurement_record_id')->nullable()->nullOnDelete();
            $table->foreign('packaging_measurement_record_id', 'sips_pkg_measurement_record_id_fk')->references('id')->on('packaging_measurement_records')->nullOnDelete();
            $table->string('packaging_method_key', 64)->nullable();
            $table->string('packaging_method_label', 128)->nullable();
            $table->decimal('packaging_length_cm', 10, 2);
            $table->decimal('packaging_width_cm', 10, 2);
            $table->decimal('packaging_height_cm', 10, 2);
            $table->decimal('packaged_weight_kg', 10, 3);
            $table->dateTime('measured_at')->nullable();
            $table->dateTime('captured_at');
            $table->timestamps();
            $table->unique(['shipment_id', 'order_item_id'], 'shipment_item_packaging_unique');
            $table->index(['product_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_item_packaging_snapshots');
    }
};
