<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_affiliate_accounts', function (Blueprint $table): void {
            $table->id();

            // Provider identity
            $table->string('connection_key', 128);
            $table->unsignedBigInteger('affiliate_id')->nullable();
            $table->foreignId('kol_id')->nullable()->constrained('kols')->nullOnDelete();

            // Provider facts
            $table->unsignedBigInteger('wordpress_user_id')->nullable();
            $table->string('wordpress_user_email', 255)->nullable();
            $table->string('display_name', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('provider_status', 32)->nullable();

            // Origin / state
            $table->string('origin', 32)->default('external_sync');
            $table->string('sync_status', 32)->default('never_synced');
            $table->string('reconciliation_status', 32)->default('present');
            $table->string('provisioning_status', 32)->default('not_requested');

            // Retry / reconciliation
            $table->string('provision_idempotency_key', 128)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();

            $table->timestamps();

            // Exact provider identity: (connection_key, affiliate_id) is unique when affiliate_id is present
            $table->unique(['connection_key', 'affiliate_id'], 'kol_affiliate_accounts_connection_affiliate_unique');
            // One KOL can be bound to at most one Affiliate per connection
            $table->unique(['connection_key', 'kol_id'], 'kol_affiliate_accounts_connection_kol_unique');

            $table->index('connection_key');
            $table->index('kol_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_affiliate_accounts');
    }
};
