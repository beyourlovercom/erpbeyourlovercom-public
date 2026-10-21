<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_affiliate_referrals', function (Blueprint $table): void {
            $table->id();
            $table->string('connection_key', 128);
            $table->unsignedBigInteger('referral_id');
            $table->unsignedBigInteger('affiliate_id');
            $table->foreignId('kol_id')->nullable()->constrained('kols')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('order_reference', 255);
            $table->text('description')->nullable();
            $table->decimal('commission_amount', 18, 6);
            $table->string('currency', 8);
            $table->string('provider_status', 32);
            $table->string('context', 64);
            $table->dateTime('referral_date');
            $table->unsignedBigInteger('payout_id')->nullable();
            $table->string('affiliate_resolution_status', 32)->default('missing');
            $table->string('order_resolution_status', 32)->default('unresolved');
            $table->string('reconciliation_status', 40)->default('present');
            $table->string('sync_status', 32)->default('never_synced');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_key', 'referral_id'], 'kol_affiliate_referrals_connection_referral_unique');
            $table->index(['connection_key', 'affiliate_id']);
            $table->index(['connection_key', 'order_reference']);
            $table->index(['connection_key', 'provider_status']);
            $table->index(['connection_key', 'referral_date']);
            $table->index('kol_id');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_affiliate_referrals');
    }
};
