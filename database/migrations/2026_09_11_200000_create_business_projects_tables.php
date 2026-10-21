<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_projects', function (Blueprint $table): void {
            $table->id();
            $table->string('project_key', 64)->unique();
            $table->string('name', 191);
            $table->string('status', 32)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('business_project_sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_project_id')->constrained('business_projects')->cascadeOnDelete();
            $table->string('site_key', 64);
            $table->string('domain_normalized', 191);
            $table->string('base_url', 500);
            $table->string('status', 32)->default('active');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['business_project_id', 'site_key']);
            $table->unique('domain_normalized');
            $table->index(['business_project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_project_sites');
        Schema::dropIfExists('business_projects');
    }
};
