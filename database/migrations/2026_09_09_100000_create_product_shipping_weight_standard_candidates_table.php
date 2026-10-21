<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_shipping_weight_standard_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id');
            $table->decimal('suggested_weight_kg', 10, 4);
            $table->string('algorithm_version', 64);
            $table->string('workflow_status', 16)->default('pending'); // pending|adopted|rejected|superseded
            $table->string('confidence_status', 32); // high_confidence|review_required
            $table->unsignedInteger('raw_sample_count');
            $table->unsignedInteger('eligible_sample_count');
            $table->unsignedInteger('gross_outlier_count');
            $table->decimal('eligible_min_kg', 10, 4)->nullable();
            $table->decimal('eligible_max_kg', 10, 4)->nullable();
            $table->decimal('eligible_spread_ratio', 10, 6)->nullable();
            $table->json('policy_json');
            $table->string('fingerprint', 64);
            $table->foreignId('created_by_user_id')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->timestamps();

            $table->unique('fingerprint', 'pswsc_fingerprint_uq');
            $table->index(['product_id', 'workflow_status'], 'pswsc_product_workflow_idx');
            $table->index('confidence_status', 'pswsc_confidence_idx');
            $table->foreign('product_id', 'pswsc_product_fk')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'pswsc_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by_user_id', 'pswsc_reviewed_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('product_shipping_weight_standard_candidate_evidences', function (Blueprint $table): void {
            $table->foreignId('candidate_id');
            $table->foreignId('product_weight_evidence_id');
            $table->boolean('is_eligible');
            $table->boolean('is_gross_outlier');
            $table->decimal('deviation_ratio', 10, 6)->nullable();

            $table->unique(['candidate_id', 'product_weight_evidence_id'], 'pswsce_candidate_evidence_uq');
            $table->foreign('candidate_id', 'pswsce_candidate_fk')
                ->references('id')->on('product_shipping_weight_standard_candidates')->cascadeOnDelete();
            $table->foreign('product_weight_evidence_id', 'pswsce_weight_evidence_fk')
                ->references('id')->on('product_weight_evidences')->cascadeOnDelete();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('shipping_weight_standard_candidate_id')
                ->nullable()
                ->after('shipping_weight_evidence_id');
            $table->foreign('shipping_weight_standard_candidate_id', 'products_sw_candidate_fk')
                ->references('id')->on('product_shipping_weight_standard_candidates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign('products_sw_candidate_fk');
            $table->dropColumn('shipping_weight_standard_candidate_id');
        });

        Schema::dropIfExists('product_shipping_weight_standard_candidate_evidences');
        Schema::dropIfExists('product_shipping_weight_standard_candidates');
    }
};
