<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kols', function (Blueprint $table): void {
            $table->foreignId('approval_manager_id')->nullable()->after('owner_id')->constrained('users')->nullOnDelete();
            $table->foreignId('finance_owner_id')->nullable()->after('approval_manager_id')->constrained('users')->nullOnDelete();
            $table->index(['approval_manager_id', 'approval_status'], 'kols_approval_manager_status_idx');
            $table->index('finance_owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('kols', function (Blueprint $table): void {
            $table->dropForeign(['approval_manager_id']);
            $table->dropForeign(['finance_owner_id']);
            $table->dropIndex('kols_approval_manager_status_idx');
            $table->dropIndex(['finance_owner_id']);
            $table->dropColumn(['approval_manager_id', 'finance_owner_id']);
        });
    }
};
