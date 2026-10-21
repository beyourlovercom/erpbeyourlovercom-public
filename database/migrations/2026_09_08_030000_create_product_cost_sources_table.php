<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_cost_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('source_key', 191);
            $table->decimal('unit_cost', 24, 12);
            $table->string('currency', 3);
            $table->string('provenance', 32)->default('confirmed_source_file');
            $table->string('status', 16)->default('confirmed');
            $table->string('source_type', 64)->default('inventory_cost_book');
            $table->string('source_reference', 255);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->dateTime('source_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->char('source_fingerprint', 64)->unique('pcs_fingerprint_unique');
            $table->timestamps();

            $table->index(['product_id', 'status'], 'pcs_product_status_idx');
            $table->index(['effective_from', 'effective_to'], 'pcs_effective_idx');
            $table->index(['source_type', 'source_reference'], 'pcs_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_cost_sources');
    }
};
