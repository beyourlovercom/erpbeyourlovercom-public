<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_provider_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('connection_key', 127);
            $table->string('remote_product_id', 127);
            $table->string('permalink', 2048)->nullable();
            $table->string('provider_status', 64)->nullable();
            $table->text('description_text')->nullable();
            $table->text('short_description_text')->nullable();
            $table->json('category_names')->nullable();
            $table->unsignedInteger('variation_count')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('provider_modified_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('payload_hash', 64);
            $table->timestamps();
            $table->unique(['provider', 'connection_key', 'remote_product_id'], 'pps_provider_connection_remote_unique');
            $table->unique(['product_id', 'provider', 'connection_key'], 'pps_product_provider_connection_unique');
            $table->index(['provider', 'connection_key', 'provider_modified_at'], 'pps_incremental_cursor_index');
        });

        Schema::create('product_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('connection_key', 127);
            $table->string('provider_media_key', 255);
            $table->string('remote_media_id', 127)->nullable();
            $table->string('source_url', 2048);
            $table->string('storage_path', 1024);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('alt_text', 1000)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'provider', 'connection_key', 'provider_media_key'], 'pm_product_provider_media_unique');
            $table->index(['product_id', 'is_primary', 'position'], 'pm_product_primary_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('product_provider_snapshots');
    }
};
