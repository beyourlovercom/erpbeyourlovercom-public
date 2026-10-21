<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shipment_carrier_billing', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->unique()->constrained('shipments')->cascadeOnDelete();
            $table->decimal('chargeable_weight_kg', 12, 3)->nullable();
            $table->decimal('actual_cost', 14, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('billing_document_number', 128)->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->string('delivery_status', 32)->nullable();
            $table->string('data_source', 64)->nullable();
            $table->timestamp('billed_at')->nullable();
            $table->json('raw_evidence')->nullable();
            $table->timestamps();
            $table->index(['delivery_status', 'billed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_carrier_billing');
    }
};
