<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('shipment_status', 32)->default('pending');
            $table->string('carrier', 128)->nullable();
            $table->string('tracking_number', 128)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->text('logistics_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['shipment_status', 'shipped_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
