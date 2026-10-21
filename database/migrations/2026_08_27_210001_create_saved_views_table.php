<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table): void {
            $table->id();
            $table->string('module', 32)->index();
            $table->string('name');
            $table->string('view_type', 16)->default('custom');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('visibility', 16)->default('private');
            $table->json('filters_json');
            $table->json('sort_json')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['module', 'created_by']);
            $table->index(['module', 'visibility']);
            $table->index(['module', 'is_pinned', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
