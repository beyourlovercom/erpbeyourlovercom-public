<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('packaging_measurement_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('status', 16)->default('draft');
            $table->string('packaging_method_key', 64)->nullable();
            $table->decimal('packaging_length_cm', 10, 2)->nullable();
            $table->decimal('packaging_width_cm', 10, 2)->nullable();
            $table->decimal('packaging_height_cm', 10, 2)->nullable();
            $table->decimal('packaged_weight_kg', 10, 3)->nullable();
            $table->dateTime('measured_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index(['product_id', 'status']);
            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('packaging_measurement_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('record_id')->constrained('packaging_measurement_records')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['record_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_measurement_media');
        Schema::dropIfExists('packaging_measurement_records');
    }
};
