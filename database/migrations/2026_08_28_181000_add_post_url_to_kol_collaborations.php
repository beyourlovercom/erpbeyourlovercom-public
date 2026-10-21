<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kol_collaborations') && ! Schema::hasColumn('kol_collaborations', 'post_url')) {
            Schema::table('kol_collaborations', function (Blueprint $table): void {
                $table->text('post_url')->nullable()->after('title');
                $table->index('post_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('kol_collaborations') && Schema::hasColumn('kol_collaborations', 'post_url')) {
            Schema::table('kol_collaborations', fn (Blueprint $table) => $table->dropColumn('post_url'));
        }
    }
};
