<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kols', function (Blueprint $table): void {
            $table->id();
            $table->string('display_name');
            $table->string('legal_name')->nullable();
            $table->string('country_code', 8)->nullable();
            $table->string('language', 16)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('relationship_stage', 32)->default('prospect')->index();
            $table->string('cooperation_type', 32)->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'status']);
            $table->index(['owner_id', 'relationship_stage']);
            $table->index(['country_code', 'status']);
            $table->index('archived_at');
        });

        Schema::create('kol_social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('handle')->nullable();
            $table->string('profile_url')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['kol_id', 'platform']);
            $table->index(['kol_id', 'is_active']);
        });

        Schema::create('kol_social_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_account_id')->constrained('kol_social_accounts')->cascadeOnDelete();
            $table->unsignedBigInteger('followers')->nullable();
            $table->string('raw_followers_value', 32)->nullable();
            $table->timestamp('measured_at');
            $table->timestamps();

            $table->index(['social_account_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_social_metrics');
        Schema::dropIfExists('kol_social_accounts');
        Schema::dropIfExists('kols');
    }
};
