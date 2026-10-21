<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kol_reconciliation_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 16);
            $table->string('original_filename');
            $table->char('checksum', 64);
            $table->string('connection_key', 128);
            $table->foreignId('creator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('distinct_source_identities')->default(0);
            $table->string('status', 24)->default('preview');
            $table->json('preview_summary')->nullable();
            $table->timestamps();
            $table->index(['connection_key', 'created_at']);
        });

        Schema::create('kol_reconciliation_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('kol_reconciliation_batches')->cascadeOnDelete();
            $table->unsignedInteger('source_row_number');
            $table->char('row_hash', 64);
            $table->json('raw_source');
            $table->json('normalized_identity')->nullable();
            $table->string('source_identity_key', 255)->nullable();
            $table->foreignId('matched_kol_id')->nullable()->constrained('kols')->nullOnDelete();
            $table->foreignId('matched_affiliate_account_id')->nullable()->constrained('kol_affiliate_accounts')->nullOnDelete();
            $table->string('kol_status', 24);
            $table->string('affiliate_status', 24);
            $table->string('reconciliation_status', 32);
            $table->json('exact_match_evidence')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'source_row_number']);
            $table->index(['batch_id', 'reconciliation_status']);
            $table->index(['batch_id', 'source_identity_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_reconciliation_rows');
        Schema::dropIfExists('kol_reconciliation_batches');
    }
};
