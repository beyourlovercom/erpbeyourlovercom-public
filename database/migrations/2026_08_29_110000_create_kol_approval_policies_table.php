<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_approval_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('version')->unique();
            $table->string('mode', 16);
            $table->json('thresholds');
            $table->timestamp('effective_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('effective_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_approval_policies');
    }
};
