<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_affiliate_payouts', function (Blueprint $table): void {
            $table->id();
            $table->string('connection_key', 128);
            $table->unsignedBigInteger('payout_id');
            $table->unsignedBigInteger('affiliate_id');
            $table->foreignId('kol_id')->nullable()->constrained('kols')->nullOnDelete();
            $table->json('referral_ids');
            $table->decimal('amount', 18, 6);
            $table->string('currency', 8);
            $table->string('payout_method', 32);
            $table->string('provider_status', 32);
            $table->dateTime('payout_date');
            $table->string('reconciliation_status', 40)->default('present');
            $table->string('sync_status', 32)->default('never_synced');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->timestamps();
            $table->unique(['connection_key', 'payout_id'], 'kol_affiliate_payouts_connection_payout_unique');
            $table->index(['connection_key', 'affiliate_id']);
            $table->index(['connection_key', 'provider_status']);
            $table->index(['connection_key', 'payout_date']);
            $table->index('kol_id');
        });

        Schema::create('kol_payout_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('connection_key', 128);
            $table->foreignId('kol_id')->constrained('kols')->restrictOnDelete();
            $table->unsignedBigInteger('affiliate_id');
            $table->decimal('amount', 18, 6);
            $table->string('currency', 8);
            $table->string('payout_method', 32)->default('paypal_manual');
            $table->text('payout_recipient');
            $table->string('status', 32)->default('pending_approval');
            $table->string('payload_hash', 64);
            $table->string('execution_payload_hash', 64)->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->unsignedBigInteger('provider_payout_id')->nullable();
            $table->string('provider_status', 32)->nullable();
            $table->text('paypal_transaction_id')->nullable();
            $table->date('paid_at')->nullable();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider_last_error_code', 64)->nullable();
            $table->string('provider_last_error_message', 512)->nullable();
            $table->timestamp('provider_last_attempt_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();
            $table->index(['connection_key', 'status']);
            $table->index(['connection_key', 'affiliate_id']);
            $table->unique(['connection_key', 'provider_payout_id'], 'kol_payout_requests_connection_provider_unique');
        });

        Schema::create('kol_payout_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payout_request_id')->constrained('kol_payout_requests')->cascadeOnDelete();
            $table->foreignId('referral_row_id')->constrained('kol_affiliate_referrals')->restrictOnDelete();
            $table->string('connection_key', 128);
            $table->unsignedBigInteger('referral_id');
            $table->decimal('commission_amount', 18, 6);
            $table->string('currency', 8);
            $table->timestamps();
            $table->unique(['payout_request_id', 'referral_id'], 'kol_payout_request_items_request_referral_unique');
            $table->index(['connection_key', 'referral_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_payout_request_items');
        Schema::dropIfExists('kol_payout_requests');
        Schema::dropIfExists('kol_affiliate_payouts');
    }
};
