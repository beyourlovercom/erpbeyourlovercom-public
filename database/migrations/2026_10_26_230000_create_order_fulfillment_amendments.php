<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_fulfillment_amendments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->json('original_sale_items');
            $table->json('latest_fulfillment_items');
            $table->json('latest_woo_inbound_items')->nullable();
            $table->string('reason', 500);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('order_fulfillment_amendments');
    }
};
