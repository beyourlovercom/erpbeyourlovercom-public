<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_financials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('merchandise_list_amount', 18, 2)->nullable();
            $table->decimal('discount_amount', 18, 2)->nullable();
            $table->decimal('shipping_revenue', 18, 2)->nullable();
            $table->decimal('amount_paid', 18, 2)->nullable();
            $table->decimal('refund_amount', 18, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->timestamps();
        });

        Schema::create('order_cost_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('cost_type', 64);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('USD');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'cost_type']);
            $table->index(['cost_type', 'amount']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_cost_entries');
        Schema::dropIfExists('order_financials');
    }
};
