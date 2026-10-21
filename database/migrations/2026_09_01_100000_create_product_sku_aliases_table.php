<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_sku_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('alias_sku', 128);
            $table->string('match_key', 128)->unique();
            $table->string('source_type', 32)->default('manual');
            $table->string('source_reference', 255)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('product_id');
        });

        Schema::create('product_sku_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->string('match_key', 128)->unique();
            $table->string('raw_sku', 255);
            $table->string('product_name_hint', 512)->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->json('source_counts')->nullable();
            $table->string('last_source_type', 32)->nullable();
            $table->string('last_source_reference', 255)->nullable();
            $table->string('status', 16)->default('open'); // open | resolved | ignored
            $table->foreignId('resolved_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('resolved_alias_id')->nullable()->constrained('product_sku_aliases')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sku_reconciliations');
        Schema::dropIfExists('product_sku_aliases');
    }
};
