<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fulfillment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('status', 32)->default('submitted')->index();
            $table->string('decision_source', 32);
            $table->string('recommended_plan_key', 128)->nullable();
            $table->string('selected_plan_key', 128)->nullable();
            $table->json('plan_snapshot')->nullable();
            $table->json('manual_assignments')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_allocations');
    }
};
