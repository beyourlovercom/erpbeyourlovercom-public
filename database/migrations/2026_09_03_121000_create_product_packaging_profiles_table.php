<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_packaging_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->foreignId('current_measurement_record_id')->nullable()->constrained('packaging_measurement_records')->nullOnDelete();
            $table->string('preset_key', 64)->nullable();
            $table->string('custom_method', 128)->nullable();
            $table->string('scenario', 512)->nullable();
            $table->string('version', 64)->nullable();
            $table->json('custom_materials')->nullable();
            $table->json('custom_steps')->nullable();
            $table->json('custom_tips')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_packaging_profiles');
    }
};
