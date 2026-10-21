<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_affiliate_coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('connection_key', 128);
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->string('code', 255);
            $table->string('normalized_code', 255);
            $table->string('discount_type', 32)->nullable();
            $table->string('amount', 32)->nullable();
            $table->string('provider_status', 32)->nullable();
            $table->timestamp('date_expires')->nullable();
            $table->unsignedBigInteger('affiliate_id')->nullable();
            $table->foreignId('kol_id')->nullable()->constrained('kols')->nullOnDelete();
            $table->string('origin', 32)->default('external_sync');
            $table->string('sync_status', 32)->default('never_synced');
            $table->string('reconciliation_status', 40)->default('present');
            $table->string('provisioning_status', 32)->default('not_requested');
            $table->string('provision_idempotency_key', 128)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->unique(['connection_key', 'coupon_id'], 'kol_affiliate_coupons_connection_coupon_unique');
            $table->index(['connection_key', 'normalized_code'], 'kol_affiliate_coupons_connection_code_index');
            $table->index(['connection_key', 'affiliate_id']);
            $table->index('kol_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_affiliate_coupons');
    }
};
