<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guide_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('content_type', 24)->index();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('markdown_body');
            $table->string('video_url', 1000)->nullable();
            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['content_type', 'status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_articles');
    }
};
