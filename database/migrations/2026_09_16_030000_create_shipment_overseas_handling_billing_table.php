<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shipment_overseas_handling_billing', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->unique()->constrained('shipments')->cascadeOnDelete();
            $table->decimal('actual_cost', 14, 4);
            $table->char('currency', 3);
            $table->string('billing_document_number', 128)->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->string('data_source', 64);
            $table->timestamp('billed_at')->nullable();
            $table->json('raw_evidence')->nullable();
            $table->timestamps();
            $table->index(['billed_at', 'currency']);
        });
    }

    public function down(): void { Schema::dropIfExists('shipment_overseas_handling_billing'); }
};
