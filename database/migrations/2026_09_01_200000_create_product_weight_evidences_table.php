<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_weight_evidences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('weight_kind', 32); // product_weight | shipping_reference_weight
            $table->decimal('observed_weight_kg', 10, 4);
            $table->string('raw_sku', 255)->nullable();
            $table->string('source_type', 32);
            $table->string('source_reference', 255)->nullable();
            $table->text('source_note')->nullable();
            $table->dateTime('observed_at')->nullable();
            $table->string('status', 16)->default('pending'); // pending | adopted | rejected
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index('source_type');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('weight_evidence_id')
                ->nullable()
                ->after('weight_kg')
                ->constrained('product_weight_evidences')
                ->nullOnDelete();
            $table->foreignId('shipping_weight_evidence_id')
                ->nullable()
                ->after('shipping_weight_kg')
                ->constrained('product_weight_evidences')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('weight_evidence_id');
            $table->dropConstrainedForeignId('shipping_weight_evidence_id');
        });

        Schema::dropIfExists('product_weight_evidences');
    }
};
