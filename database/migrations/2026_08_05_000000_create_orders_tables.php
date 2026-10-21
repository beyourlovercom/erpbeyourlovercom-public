<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('internal_order_number', 64)->unique();
            $table->string('source_type', 32);
            $table->string('external_order_number', 128)->nullable();
            $table->date('order_date');
            $table->text('order_note')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('order_status', 32)->default('pending_shipment');
            $table->timestamps();
            $table->index(['order_status', 'order_date']);
            $table->index(['source_type', 'order_date']);
            $table->index('owner_id');
        });

        Schema::create('order_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('recipient_name', 255);
            $table->string('phone', 64);
            $table->string('country', 128);
            $table->string('province_state', 128);
            $table->string('city', 128);
            $table->string('address_line_1', 255);
            $table->string('address_line_2', 255)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('product_summary', 255);
            $table->string('sku', 128)->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 18, 2);
            $table->decimal('line_revenue', 18, 2);
            $table->timestamps();
            $table->index(['order_id', 'sku']);
        });

        Schema::create('order_external_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('reference', 191);
            $table->timestamps();
            $table->unique(['provider', 'reference']);
            $table->index(['order_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_external_references');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('order_addresses');
        Schema::dropIfExists('orders');
    }
};
