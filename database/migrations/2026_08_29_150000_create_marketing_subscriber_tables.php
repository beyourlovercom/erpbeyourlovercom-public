<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketing_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('language_code', 10)->nullable();
            $table->timestamps();
        });
        Schema::create('marketing_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('marketing_subscribers')->cascadeOnDelete();
            $table->string('channel')->default('email');
            $table->string('provider');
            $table->string('source_site')->nullable();
            $table->string('external_subscription_id')->nullable();
            $table->string('external_list_id')->nullable();
            $table->string('external_list_name')->nullable();
            $table->enum('status', ['subscribed', 'unsubscribed', 'suppressed', 'pending', 'unknown'])->default('unknown');
            $table->string('consent_source')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('status_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->index(['provider', 'source_site', 'status']);
            $table->unique(['provider', 'external_subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_subscriptions');
        Schema::dropIfExists('marketing_subscribers');
    }
};
