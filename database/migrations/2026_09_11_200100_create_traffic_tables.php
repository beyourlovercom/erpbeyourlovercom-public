<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_source_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_project_id')->constrained('business_projects')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('external_account_id', 191);
            $table->string('name', 191)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('account_timezone', 64)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_account_id']);
            $table->index(['business_project_id', 'provider', 'status'], 'tsa_project_provider_status_idx');
        });

        Schema::create('traffic_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('traffic_source_account_id')->constrained('traffic_source_accounts')->cascadeOnDelete();
            $table->string('external_campaign_id', 191);
            $table->string('name', 255);
            $table->string('status', 32)->nullable();
            $table->string('advertising_channel_type', 64)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['traffic_source_account_id', 'external_campaign_id'], 'tcmp_account_campaign_uniq');
            $table->index(['traffic_source_account_id', 'status']);
        });

        Schema::create('traffic_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('traffic_campaign_id')->constrained('traffic_campaigns')->cascadeOnDelete();
            $table->date('metric_date');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->decimal('provider_conversions', 14, 4)->default(0);
            $table->timestamps();

            $table->unique(['traffic_campaign_id', 'metric_date']);
            $table->index('metric_date');
        });

        Schema::create('traffic_inquiry_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_project_id')->constrained('business_projects')->cascadeOnDelete();
            $table->date('metric_date');
            $table->string('source_type', 32);
            $table->unsignedInteger('inquiries')->default(0);
            $table->unsignedInteger('qualified_inquiries')->default(0);
            $table->timestamps();

            $table->unique(['business_project_id', 'metric_date', 'source_type'], 'tiq_project_date_source_uniq');
            $table->index(['metric_date', 'source_type']);
        });

        Schema::create('landing_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_project_id')->constrained('business_projects')->cascadeOnDelete();
            $table->foreignId('business_project_site_id')->nullable()->constrained('business_project_sites')->nullOnDelete();
            $table->string('page_key', 127);
            $table->string('name', 255);
            $table->string('canonical_url', 500);
            $table->string('path', 500)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['business_project_id', 'page_key']);
            $table->index(['business_project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_pages');
        Schema::dropIfExists('traffic_inquiry_daily_metrics');
        Schema::dropIfExists('traffic_daily_metrics');
        Schema::dropIfExists('traffic_campaigns');
        Schema::dropIfExists('traffic_source_accounts');
    }
};
