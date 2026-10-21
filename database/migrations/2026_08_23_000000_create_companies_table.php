<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->string('legal_name', 255)->nullable();
            $table->string('website_url', 500)->nullable();
            $table->string('website_domain_normalized', 191)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('registration_number', 191)->nullable();
            $table->string('industry', 191)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('prospect');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index('website_domain_normalized');
            $table->index(['country_code', 'registration_number']);
            $table->index('owner_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
