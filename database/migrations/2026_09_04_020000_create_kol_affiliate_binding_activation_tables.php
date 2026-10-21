<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_affiliate_binding_activation_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_activation_batch_id');
            $table->foreign('import_activation_batch_id', 'kol_bind_batch_import_fk')->references('id')->on('kol_import_activation_batches')->cascadeOnDelete();
            $table->foreignId('reconciliation_batch_id');
            $table->foreign('reconciliation_batch_id', 'kol_bind_batch_recon_fk')->references('id')->on('kol_reconciliation_batches')->cascadeOnDelete();
            $table->string('connection_key', 128);
            $table->char('source_checksum', 64);
            $table->unsignedInteger('plan_version')->default(1);
            $table->string('status', 24)->default('prepared');
            $table->unsignedInteger('eligible_kol_count')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('already_bound_count')->default(0);
            $table->unsignedInteger('no_affiliate_count')->default(0);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->char('plan_checksum', 64);
            $table->foreignId('generated_by')->nullable();
            $table->foreign('generated_by', 'kol_bind_batch_generated_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreignId('applied_by')->nullable();
            $table->foreign('applied_by', 'kol_bind_batch_applied_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamp('applied_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
            $table->index(['import_activation_batch_id', 'status'], 'kol_binding_activation_import_status_idx');
            $table->index(['connection_key', 'status'], 'kol_binding_activation_connection_status_idx');
        });

        Schema::create('kol_affiliate_binding_activation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('binding_activation_batch_id');
            $table->foreign('binding_activation_batch_id', 'kol_bind_item_batch_fk')->references('id')->on('kol_affiliate_binding_activation_batches')->cascadeOnDelete();
            $table->foreignId('import_activation_item_id')->nullable();
            $table->foreign('import_activation_item_id', 'kol_bind_item_import_fk')->references('id')->on('kol_import_activation_items')->nullOnDelete();
            $table->char('cluster_key', 64);
            $table->foreignId('canonical_kol_id')->nullable();
            $table->foreign('canonical_kol_id', 'kol_bind_item_kol_fk')->references('id')->on('kols')->nullOnDelete();
            $table->foreignId('affiliate_account_id')->nullable();
            $table->foreign('affiliate_account_id', 'kol_bind_item_affiliate_fk')->references('id')->on('kol_affiliate_accounts')->nullOnDelete();
            $table->string('readiness_status', 32);
            $table->json('block_reasons')->nullable();
            $table->json('exact_identity')->nullable();
            $table->json('affiliate_snapshot')->nullable();
            $table->unsignedInteger('referral_impact_count')->default(0);
            $table->char('referral_provider_checksum', 64)->nullable();
            $table->foreignId('applied_by')->nullable();
            $table->foreign('applied_by', 'kol_bind_item_applied_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['binding_activation_batch_id', 'cluster_key'], 'kol_binding_activation_item_cluster_uq');
            $table->index(['binding_activation_batch_id', 'readiness_status'], 'kol_binding_activation_item_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_affiliate_binding_activation_items');
        Schema::dropIfExists('kol_affiliate_binding_activation_batches');
    }
};
