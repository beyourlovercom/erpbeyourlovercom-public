<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kols', function (Blueprint $table): void {
            $table->string('approval_status', 16)->default('approved')->after('status');
            $table->timestamp('approval_updated_at')->nullable()->after('approval_status');
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('kols', function (Blueprint $table): void {
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'approval_updated_at']);
        });
    }
};
