<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('oversea_warehouse_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('submitted_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('reviewed_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->string('progress', 64)->default('pending_review');
            $table->text('note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oversea_warehouse_reviews');
    }
};
