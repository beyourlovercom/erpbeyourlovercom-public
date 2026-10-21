<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_connector_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('site_url')->nullable();
            $table->string('connection_key')->default('beyourlover.com');
            $table->string('key_id')->nullable();
            $table->text('shared_secret')->nullable();
            $table->boolean('verify_tls')->default(true);
            $table->timestamp('last_health_checked_at')->nullable();
            $table->string('last_health_status', 32)->nullable();
            $table->json('last_health_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_connector_settings');
    }
};
