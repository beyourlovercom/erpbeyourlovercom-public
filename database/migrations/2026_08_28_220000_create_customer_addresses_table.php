<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('address_type', 16); // 'billing' or 'shipping'
            $table->string('name', 255)->nullable();
            $table->string('company', 255)->nullable();
            $table->string('phone', 64)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('province_state', 128)->nullable();
            $table->string('city', 128)->nullable();
            $table->string('address_line_1', 255)->nullable();
            $table->string('address_line_2', 255)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'address_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
