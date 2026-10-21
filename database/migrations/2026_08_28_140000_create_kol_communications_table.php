<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_communications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('kol_opportunities')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 32);
            $table->text('summary');
            $table->timestamp('contacted_at');
            $table->text('next_action')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamps();
            $table->index(['kol_id', 'contacted_at']);
            $table->index(['opportunity_id', 'contacted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_communications');
    }
};
