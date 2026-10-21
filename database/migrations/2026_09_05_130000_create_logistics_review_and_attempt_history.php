<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('logistics_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reason', 255);
            $table->string('trigger', 64);
            $table->string('destination', 32)->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'requested_at']);
        });

        Schema::create('logistics_review_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_review_id')->constrained('logistics_reviews')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('event', 32);
            $table->string('status', 24);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('destination', 32)->nullable();
            $table->text('note')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('created_at');
            $table->index(['order_id', 'created_at']);
        });

        Schema::create('shipment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('shipment_status', 32);
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('warehouse_selection_source', 32)->nullable();
            $table->string('carrier', 128)->nullable();
            $table->string('service', 128)->nullable();
            $table->string('carrier_selection_source', 32)->nullable();
            $table->string('tracking_number', 128)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->text('logistics_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('snapshotted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('evidence_snapshot')->nullable();
            $table->timestamp('snapshotted_at');
            $table->timestamps();
            $table->unique(['order_id', 'attempt_number']);
            $table->index(['order_id', 'snapshotted_at']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_attempts');
        Schema::dropIfExists('logistics_review_histories');
        Schema::dropIfExists('logistics_reviews');
    }
};
