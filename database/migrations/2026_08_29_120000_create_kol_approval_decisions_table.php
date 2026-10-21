<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_approval_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->foreignId('policy_id')->nullable()->constrained('kol_approval_policies')->nullOnDelete();
            $table->string('outcome', 16);
            $table->string('source', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('policy_mode', 16)->nullable();
            $table->json('threshold_snapshot');
            $table->string('reason_code', 64);
            $table->text('reason_text')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['kol_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_approval_decisions');
    }
};
