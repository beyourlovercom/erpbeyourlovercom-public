<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->string('title');
            $table->string('cooperation_type', 64)->nullable();
            $table->json('target_products')->nullable();
            $table->string('stage', 32)->default('pending_contact')->index();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->string('currency', 8)->default('USD');
            $table->date('expected_publish_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable()->index();
            $table->text('next_action')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['kol_id', 'status']);
            $table->index(['kol_id', 'stage']);
            $table->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_opportunities');
    }
};
