<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kol_collaborations', function (Blueprint $table): void {
            $table->string('approval_status', 16)->default('not_required')->after('status')->index();
            $table->timestamp('approval_updated_at')->nullable()->after('approval_status');
            $table->foreignId('approved_by_user_id')->nullable()->after('approval_updated_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
        });

        DB::table('kol_collaborations')->where('cooperation_type', '付费发帖')->update(['approval_status' => 'approved']);

        Schema::create('kol_collaboration_approval_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collaboration_id')->constrained('kol_collaborations')->cascadeOnDelete();
            $table->string('outcome', 16);
            $table->string('source', 40);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cooperation_type', 64)->nullable();
            $table->decimal('agreement_amount', 12, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->text('reason_text')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['collaboration_id', 'decided_at'], 'kol_collab_approval_decision_time_idx');
            $table->index(['outcome', 'decided_at'], 'kol_collab_approval_outcome_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_collaboration_approval_decisions');
        Schema::table('kol_collaborations', function (Blueprint $table): void {
            $table->dropIndex(['approval_status']);
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn(['approval_status', 'approval_updated_at', 'approved_at']);
        });
    }
};
