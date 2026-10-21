<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_contents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->foreignId('collaboration_id')->nullable()->constrained('kol_collaborations')->nullOnDelete();
            $table->string('platform', 32);
            $table->string('content_type', 64);
            $table->string('publication_status', 32)->default('unpublished');
            $table->text('post_url')->nullable();
            $table->date('planned_publish_at')->nullable();
            $table->date('published_at')->nullable();
            $table->timestamps();
            $table->index(['kol_id', 'publication_status']);
            $table->index(['platform', 'publication_status']);
            $table->index('collaboration_id');
        });

        Schema::create('kol_content_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id')->constrained('kol_contents')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 32)->default('local');
            $table->string('path', 1024);
            $table->string('original_name', 255);
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('evidence_type', 32)->default('screenshot');
            $table->timestamps();
            $table->index(['content_id', 'evidence_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_content_attachments');
        Schema::dropIfExists('kol_contents');
    }
};
